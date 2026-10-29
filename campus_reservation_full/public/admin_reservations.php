<?php
declare(strict_types=1);

header('Content-Type: text/html; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store');
ini_set('display_errors', '0');

session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
session_start();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function db(): PDO
{
    return new PDO(
        'mysql:host=' . getenv('DB_HOST') . ';dbname=' . getenv('DB_NAME') . ';charset=utf8mb4',
        (string) getenv('DB_USER'),
        (string) getenv('DB_PASSWORD'),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
}

function ensure_audit_log_table(PDO $pdo): void
{
    // 既存のDockerボリュームでも監査ログを利用できるようにする。
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS audit_logs ('
        . ' id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
        . ' actor_user_id INT UNSIGNED NOT NULL,'
        . ' action VARCHAR(50) NOT NULL,'
        . ' target_type VARCHAR(50) NOT NULL,'
        . ' target_id INT UNSIGNED NOT NULL,'
        . ' details TEXT NULL,'
        . ' created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,'
        . ' PRIMARY KEY (id),'
        . ' KEY idx_audit_logs_actor_created (actor_user_id, created_at),'
        . ' KEY idx_audit_logs_target (target_type, target_id),'
        . ' CONSTRAINT fk_audit_logs_actor FOREIGN KEY (actor_user_id)'
        . ' REFERENCES users(id) ON UPDATE CASCADE ON DELETE RESTRICT'
        . ') ENGINE=InnoDB'
    );
}

function post_string(string $key): string
{
    $value = $_POST[$key] ?? '';
    return is_string($value) ? trim($value) : '';
}

function reservation_status_label(string $status): string
{
    return [
        'pending' => '申請中',
        'approved' => '承認済み',
        'lent' => '貸出中',
        'returned' => '返却済み',
        'rejected' => '却下',
        'canceled' => 'キャンセル',
    ][$status] ?? $status;
}

function audit_reservation(
    PDO $pdo,
    int $actorId,
    string $action,
    int $reservationId,
    array $details
): void {
    $statement = $pdo->prepare(
        'INSERT INTO audit_logs (actor_user_id, action, target_type, target_id, details)'
        . ' VALUES (?, ?, ?, ?, ?)'
    );
    $statement->execute([
        $actorId,
        $action,
        'reservation',
        $reservationId,
        json_encode($details, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
    ]);
}

function redirect_with_message(string $message): never
{
    $_SESSION['admin_reservations_flash'] = $message;
    header('Location: /admin_reservations.php');
    exit;
}

$pdo = null;
$currentUser = null;
$reservations = [];
$auditLogs = [];
$pageError = null;
$accessDenied = false;
$flash = $_SESSION['admin_reservations_flash'] ?? null;
unset($_SESSION['admin_reservations_flash']);

try {
    $pdo = db();
    ensure_audit_log_table($pdo);

    if (!empty($_SESSION['uid'])) {
        $userStatement = $pdo->prepare('SELECT id, name, role FROM users WHERE id = ?');
        $userStatement->execute([(int) $_SESSION['uid']]);
        $currentUser = $userStatement->fetch() ?: null;
    }

    if ($currentUser === null) {
        header('Location: /login.php');
        exit;
    }
    if (($currentUser['role'] ?? null) !== 'admin') {
        http_response_code(403);
        $accessDenied = true;
    }

    if (!$accessDenied && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (
            !isset($_POST['csrf_token'], $_SESSION['csrf_token'])
            || !is_string($_POST['csrf_token'])
            || !hash_equals((string) $_SESSION['csrf_token'], $_POST['csrf_token'])
        ) {
            http_response_code(403);
            throw new DomainException('不正なリクエストです。画面を再読み込みしてください。');
        }

        $action = post_string('action');
        $transitions = [
            'approve' => ['from' => 'pending', 'to' => 'approved', 'audit' => 'reservation.approve'],
            'reject' => ['from' => 'pending', 'to' => 'rejected', 'audit' => 'reservation.reject'],
            'lend' => ['from' => 'approved', 'to' => 'lent', 'audit' => 'reservation.lend'],
            'return' => ['from' => 'lent', 'to' => 'returned', 'audit' => 'reservation.return'],
        ];
        if (!isset($transitions[$action])) {
            http_response_code(400);
            throw new DomainException('操作内容を確認してください。');
        }

        $reservationIdInput = post_string('reservation_id');
        $reservationId = ctype_digit($reservationIdInput) ? (int) $reservationIdInput : 0;
        if ($reservationId < 1) {
            http_response_code(400);
            throw new DomainException('予約を選び直してください。');
        }

        $transition = $transitions[$action];
        $pdo->beginTransaction();

        $reservationStatement = $pdo->prepare(
            'SELECT id, user_id, item_id, start_date, end_date, status'
            . ' FROM reservations WHERE id = ? FOR UPDATE'
        );
        $reservationStatement->execute([$reservationId]);
        $reservation = $reservationStatement->fetch();

        if (!$reservation) {
            http_response_code(404);
            throw new DomainException('対象の予約が見つかりません。');
        }
        if ($reservation['status'] !== $transition['from']) {
            http_response_code(409);
            throw new DomainException(
                '現在の状態が「' . reservation_status_label((string) $transition['from'])
                . '」ではないため、この操作は実行できません。'
            );
        }

        if ($action === 'approve') {
            // 同一施設・備品への承認を直列化し、確定済み予約を現在の状態で再確認する。
            $itemStatement = $pdo->prepare('SELECT id, status FROM items WHERE id = ? FOR UPDATE');
            $itemStatement->execute([(int) $reservation['item_id']]);
            $item = $itemStatement->fetch();
            if (!$item || $item['status'] !== 'available') {
                http_response_code(409);
                throw new DomainException('この施設・備品は現在利用できません。');
            }
            $overlapStatement = $pdo->prepare(
                "SELECT id FROM reservations WHERE item_id = ? AND id <> ? AND status IN ('approved', 'lent')"
                . ' AND start_date <= ? AND end_date >= ? LIMIT 1 FOR UPDATE'
            );
            $overlapStatement->execute([
                (int) $reservation['item_id'], $reservationId,
                $reservation['end_date'], $reservation['start_date'],
            ]);
            if ($overlapStatement->fetch()) {
                http_response_code(409);
                throw new DomainException('承認済みまたは貸出中の予約と期間が重複しています。');
            }
        }

        $updateStatement = $pdo->prepare(
            'UPDATE reservations SET status = ? WHERE id = ? AND status = ?'
        );
        $updateStatement->execute([$transition['to'], $reservationId, $transition['from']]);
        if ($updateStatement->rowCount() !== 1) {
            http_response_code(409);
            throw new DomainException('予約状態が更新されたため、画面を再読み込みしてください。');
        }

        audit_reservation(
            $pdo,
            (int) $currentUser['id'],
            (string) $transition['audit'],
            $reservationId,
            [
                'before_status' => $transition['from'],
                'after_status' => $transition['to'],
                'user_id' => (int) $reservation['user_id'],
                'item_id' => (int) $reservation['item_id'],
            ]
        );

        $pdo->commit();
        $messages = [
            'approve' => '予約を承認しました。', 'reject' => '予約を却下しました。',
            'lend' => '貸出処理を記録しました。', 'return' => '返却処理を記録しました。',
        ];
        redirect_with_message($messages[$action]);
    }
} catch (DomainException $exception) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $pageError = $exception->getMessage();
} catch (Throwable) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    $pageError = '処理中にエラーが発生しました。時間をおいて再度お試しください。';
}

if ($pdo instanceof PDO && !$accessDenied && ($currentUser['role'] ?? null) === 'admin') {
    try {
        $reservationStatement = $pdo->prepare(
            'SELECT r.id, r.start_date, r.end_date, r.status,'
            . ' u.name AS user_name, i.name AS item_name, i.storage_location,'
            . " CASE WHEN r.status = 'lent' AND r.end_date < CURRENT_DATE THEN 1 ELSE 0 END AS is_overdue"
            . ' FROM reservations r'
            . ' JOIN users u ON u.id = r.user_id'
            . ' JOIN items i ON i.id = r.item_id'
            . ' ORDER BY r.id DESC'
        );
        $reservationStatement->execute();
        $reservations = $reservationStatement->fetchAll();

        $auditStatement = $pdo->prepare(
            'SELECT a.action, a.target_id, a.details, a.created_at, u.name AS actor_name'
            . ' FROM audit_logs a JOIN users u ON u.id = a.actor_user_id'
            . " WHERE a.target_type = 'reservation' ORDER BY a.id DESC LIMIT 20"
        );
        $auditStatement->execute();
        $auditLogs = $auditStatement->fetchAll();
    } catch (Throwable) {
        if ($pageError === null) {
            http_response_code(500);
            $pageError = '表示データを取得できませんでした。時間をおいて再度お試しください。';
        }
    }
}
?>
<!doctype html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>貸出・返却管理</title>
  <style>
    :root { font-family: system-ui, -apple-system, "Yu Gothic", Meiryo, sans-serif; color: #1d2939; background: #f3f6fb; }
    * { box-sizing: border-box; }
    body { margin: 0; }
    header { background: #17365d; color: white; padding: 22px max(4vw, 20px); }
    header h1 { margin: 0 0 6px; font-size: 1.5rem; }
    header p { margin: 0; color: #d7e3f3; }
    nav { display: flex; gap: 14px; margin-top: 16px; align-items: center; flex-wrap: wrap; }
    nav a { color: white; }
    nav form { margin: 0; }
    nav button { padding: 7px 12px; border: 0; border-radius: 5px; cursor: pointer; }
    main { max-width: 1180px; margin: 28px auto; padding: 0 20px; }
    .card { background: white; padding: 23px; margin-bottom: 20px; border-radius: 10px; box-shadow: 0 2px 12px #182e4615; }
    .alert { padding: 13px 18px; border-radius: 6px; margin-bottom: 18px; background: #e2f4e9; }
    .error { background: #ffe5e2; color: #8a2b22; }
    .scroll { overflow-x: auto; }
    table { width: 100%; border-collapse: collapse; font-size: .9rem; }
    th, td { padding: 11px; border-bottom: 1px solid #dce2eb; text-align: left; vertical-align: middle; }
    th { background: #f0f4f9; }
    button { padding: 8px 12px; border: 0; border-radius: 5px; background: #215a9c; color: white; cursor: pointer; font: inherit; }
    form.inline { display: inline; margin: 0; }
    .pill { display: inline-block; padding: 4px 9px; border-radius: 20px; background: #e9effa; white-space: nowrap; }
    .overdue { background: #ffe0dc; color: #9b271e; font-weight: 700; }
    .empty { text-align: center; color: #617287; }
    code { overflow-wrap: anywhere; }
  </style>
</head>
<body>
<header>
  <h1>学内施設・備品予約管理システム</h1>
  <p>貸出・返却管理</p>
  <nav>
    <a href="/items.php">施設・備品一覧</a>
    <a href="/admin_items.php">施設・備品管理</a>
    <?php if ($currentUser): ?><span><?= e($currentUser['name']) ?>さん</span><?php endif; ?>
    <?php if ($currentUser): ?>
      <form method="post" action="/logout.php">
        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token'] ?? '') ?>">
        <button type="submit">ログアウト</button>
      </form>
    <?php endif; ?>
  </nav>
</header>
<main>
  <?php if ($flash): ?><p class="alert" role="status"><?= e($flash) ?></p><?php endif; ?>
  <?php if ($pageError): ?><p class="alert error" role="alert"><?= e($pageError) ?></p><?php endif; ?>

  <?php if ($accessDenied): ?>
    <section class="card">
      <h2>アクセスできません</h2>
      <p>この画面は管理担当者専用です。</p>
    </section>
  <?php elseif ($currentUser): ?>
    <section class="card">
      <h2>予約・貸出状況</h2>
      <div class="scroll">
        <table>
          <thead>
          <tr><th>ID</th><th>申請者</th><th>施設・備品</th><th>利用期間</th><th>状態</th><th>期限</th><th>操作</th></tr>
          </thead>
          <tbody>
          <?php if ($reservations === []): ?>
            <tr><td class="empty" colspan="7">予約はまだありません。</td></tr>
          <?php else: ?>
            <?php foreach ($reservations as $reservation): ?>
              <tr>
                <td><?= e($reservation['id']) ?></td>
                <td><?= e($reservation['user_name']) ?></td>
                <td>
                  <?= e($reservation['item_name']) ?><br>
                  <small>保管場所: <?= e($reservation['storage_location'] ?: '未登録') ?></small>
                </td>
                <td><?= e($reservation['start_date']) ?> ～ <?= e($reservation['end_date']) ?></td>
                <td><span class="pill"><?= e(reservation_status_label((string) $reservation['status'])) ?></span></td>
                <td>
                  <?php if ((int) $reservation['is_overdue'] === 1): ?>
                    <span class="pill overdue">返却期限超過</span>
                  <?php elseif ($reservation['status'] === 'lent'): ?>
                    <span class="pill">期限内</span>
                  <?php else: ?>—<?php endif; ?>
                </td>
                <td>
                  <?php if ($reservation['status'] === 'pending'): ?>
                    <form method="post" action="/admin_reservations.php" class="inline">
                      <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                      <input type="hidden" name="action" value="approve">
                      <input type="hidden" name="reservation_id" value="<?= e($reservation['id']) ?>">
                      <button type="submit">承認する</button>
                    </form>
                    <form method="post" action="/admin_reservations.php" class="inline">
                      <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                      <input type="hidden" name="action" value="reject">
                      <input type="hidden" name="reservation_id" value="<?= e($reservation['id']) ?>">
                      <button type="submit">却下する</button>
                    </form>
                  <?php elseif ($reservation['status'] === 'approved'): ?>
                    <form method="post" action="/admin_reservations.php" class="inline">
                      <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                      <input type="hidden" name="action" value="lend">
                      <input type="hidden" name="reservation_id" value="<?= e($reservation['id']) ?>">
                      <button type="submit">貸出にする</button>
                    </form>
                  <?php elseif ($reservation['status'] === 'lent'): ?>
                    <form method="post" action="/admin_reservations.php" class="inline">
                      <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                      <input type="hidden" name="action" value="return">
                      <input type="hidden" name="reservation_id" value="<?= e($reservation['id']) ?>">
                      <button type="submit">返却済みにする</button>
                    </form>
                  <?php else: ?>—<?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>

    <section class="card">
      <h2>予約操作の監査ログ（最新20件）</h2>
      <div class="scroll">
        <table>
          <thead><tr><th>日時</th><th>担当者</th><th>操作</th><th>予約ID</th><th>内容</th></tr></thead>
          <tbody>
          <?php if ($auditLogs === []): ?>
            <tr><td class="empty" colspan="5">予約操作の監査ログはまだありません。</td></tr>
          <?php else: ?>
            <?php foreach ($auditLogs as $log): ?>
              <tr>
                <td><?= e($log['created_at']) ?></td>
                <td><?= e($log['actor_name']) ?></td>
                <td><?= e($log['action']) ?></td>
                <td><?= e($log['target_id']) ?></td>
                <td><code><?= e($log['details']) ?></code></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>
  <?php endif; ?>
</main>
</body>
</html>
