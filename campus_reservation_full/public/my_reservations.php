<?php
declare(strict_types=1);

header('Content-Type: text/html; charset=UTF-8');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
ini_set('display_errors', '0');
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
session_start();

function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function reservation_label(string $status): string
{
    return [
        'pending' => '申請中', 'approved' => '承認済み', 'lent' => '貸出中',
        'returned' => '返却済み', 'rejected' => '却下', 'canceled' => 'キャンセル済み',
    ][$status] ?? $status;
}

if (empty($_SESSION['uid'])) {
    header('Location: /login.php');
    exit;
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$pdo = null;
$user = null;
$reservations = [];
$error = null;
$notice = $_SESSION['my_reservations_notice'] ?? null;
unset($_SESSION['my_reservations_notice']);

try {
    $pdo = new PDO(
        'mysql:host=' . getenv('DB_HOST') . ';dbname=' . getenv('DB_NAME') . ';charset=utf8mb4',
        (string) getenv('DB_USER'),
        (string) getenv('DB_PASSWORD'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
    );
    $statement = $pdo->prepare('SELECT id, name FROM users WHERE id = ?');
    $statement->execute([(int) $_SESSION['uid']]);
    $user = $statement->fetch();
    if (!$user) {
        header('Location: /login.php');
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf_token'] ?? null;
        if (!is_string($token) || !hash_equals((string) $_SESSION['csrf_token'], $token)) {
            http_response_code(403);
            throw new DomainException('不正なリクエストです。画面を再読み込みしてください。');
        }
        $idInput = $_POST['reservation_id'] ?? null;
        if (!is_string($idInput) || !ctype_digit($idInput) || (int) $idInput < 1) {
            http_response_code(400);
            throw new DomainException('予約を選び直してください。');
        }

        $pdo->beginTransaction();
        $statement = $pdo->prepare("UPDATE reservations SET status = 'canceled' WHERE id = ? AND user_id = ? AND status = 'pending'");
        $statement->execute([(int) $idInput, (int) $user['id']]);
        if ($statement->rowCount() !== 1) {
            http_response_code(403);
            throw new DomainException('この予約はキャンセルできません。');
        }
        $pdo->commit();
        $_SESSION['my_reservations_notice'] = '予約をキャンセルしました。';
        header('Location: /my_reservations.php');
        exit;
    }

    $statement = $pdo->prepare(
        'SELECT r.id, r.start_date, r.end_date, r.status, i.name AS item_name,'
        . " CASE WHEN r.status = 'lent' AND r.end_date < CURRENT_DATE THEN 1 ELSE 0 END AS is_overdue"
        . ' FROM reservations r JOIN items i ON i.id = r.item_id'
        . ' WHERE r.user_id = ? ORDER BY r.id DESC'
    );
    $statement->execute([(int) $user['id']]);
    $reservations = $statement->fetchAll();
} catch (DomainException $exception) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $error = $exception->getMessage();
} catch (Throwable) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    $error = '処理中にエラーが発生しました。時間をおいて再度お試しください。';
}
?>
<!doctype html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>自分の予約一覧</title>
  <style>
    :root { font-family: system-ui, -apple-system, "Yu Gothic", Meiryo, sans-serif; color: #1d2939; background: #f3f6fb; }
    * { box-sizing: border-box; } body { margin: 0; }
    header { background: #17365d; color: white; padding: 22px max(4vw, 20px); }
    header h1 { margin: 0 0 6px; font-size: 1.5rem; }
    nav { display: flex; gap: 14px; margin-top: 16px; align-items: center; flex-wrap: wrap; }
    nav a { color: white; } nav form { margin: 0; }
    main { max-width: 1100px; margin: 28px auto; padding: 0 20px; }
    .card { background: white; padding: 23px; border-radius: 10px; box-shadow: 0 2px 12px #182e4615; }
    .alert { padding: 13px 18px; border-radius: 6px; margin-bottom: 18px; background: #e2f4e9; }
    .error { background: #ffe5e2; color: #8a2b22; }
    .scroll { overflow-x: auto; } table { width: 100%; border-collapse: collapse; }
    th, td { padding: 12px; border-bottom: 1px solid #dce2eb; text-align: left; }
    th { background: #f0f4f9; } button { padding: 8px 12px; border: 0; border-radius: 5px; background: #215a9c; color: white; cursor: pointer; font: inherit; }
    .overdue { color: #9b271e; font-weight: 700; } .empty { text-align: center; color: #617287; }
  </style>
</head>
<body>
<header>
  <h1>学内施設・備品予約管理システム</h1>
  <nav>
    <a href="/items.php">施設・備品一覧</a>
    <?php if ($user): ?><span><?= e($user['name']) ?>さん</span>
      <form method="post" action="/logout.php">
        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
        <button type="submit">ログアウト</button>
      </form>
    <?php endif; ?>
  </nav>
</header>
<main>
  <?php if ($notice): ?><p class="alert" role="status"><?= e($notice) ?></p><?php endif; ?>
  <?php if ($error): ?><p class="alert error" role="alert"><?= e($error) ?></p><?php endif; ?>
  <?php if ($user && !$error): ?>
    <section class="card">
      <h2>自分の予約一覧</h2>
      <div class="scroll"><table>
        <thead><tr><th>施設・備品</th><th>利用期間</th><th>状態</th><th>操作</th></tr></thead>
        <tbody>
        <?php if (!$reservations): ?>
          <tr><td colspan="4" class="empty">予約はまだありません。</td></tr>
        <?php else: foreach ($reservations as $reservation): ?>
          <tr>
            <td><?= e($reservation['item_name']) ?></td>
            <td><?= e($reservation['start_date']) ?> ～ <?= e($reservation['end_date']) ?></td>
            <td><?= e(reservation_label((string) $reservation['status'])) ?>
              <?php if ((int) $reservation['is_overdue'] === 1): ?><span class="overdue">（返却期限超過）</span><?php endif; ?>
            </td>
            <td>
              <?php if ($reservation['status'] === 'pending'): ?>
                <form method="post" action="/my_reservations.php">
                  <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                  <input type="hidden" name="reservation_id" value="<?= e($reservation['id']) ?>">
                  <button type="submit">キャンセルする</button>
                </form>
              <?php else: ?>—<?php endif; ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table></div>
    </section>
  <?php endif; ?>
</main>
</body>
</html>
