<?php
declare(strict_types=1);
require_once __DIR__ . '/audit.php';

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
    // 既存のDockerボリュームでもIssue #10の監査ログを利用できるようにする。
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

function status_label(string $status): string
{
    return [
        'available' => '利用可能',
        'maintenance' => 'メンテナンス',
        'inactive' => '利用停止',
    ][$status] ?? $status;
}

function post_string(string $key): string
{
    $value = $_POST[$key] ?? '';
    return is_string($value) ? trim($value) : '';
}

function utf8_length(string $value): ?int
{
    if (preg_match('//u', $value) !== 1) {
        return null;
    }
    $count = preg_match_all('/./us', $value);
    return $count === false ? null : $count;
}

/** @return array{0: array{name: string, category_id: int, description: string, storage_location: string, status: string}, 1: list<string>} */
function validate_item_input(array $validCategoryIds): array
{
    // 更新対象はこの5項目だけ。余分なPOST項目は読み取らずDBへ反映しない。
    $name = post_string('name');
    $categoryInput = post_string('category_id');
    $description = post_string('description');
    $storageLocation = post_string('storage_location');
    $status = post_string('status');
    $errors = [];

    $nameLength = utf8_length($name);
    if ($name === '') {
        $errors[] = '名称を入力してください。';
    } elseif ($nameLength === null || $nameLength > 100) {
        $errors[] = '名称は100文字以内で入力してください。';
    }

    $categoryId = ctype_digit($categoryInput) ? (int) $categoryInput : 0;
    if ($categoryId < 1 || !in_array($categoryId, $validCategoryIds, true)) {
        $errors[] = '分類を選び直してください。';
    }

    $descriptionLength = utf8_length($description);
    if ($descriptionLength === null || $descriptionLength > 1000) {
        $errors[] = '説明は1000文字以内で入力してください。';
    }

    $storageLocationLength = utf8_length($storageLocation);
    if ($storageLocationLength === null || $storageLocationLength > 255) {
        $errors[] = '保管場所は255文字以内で入力してください。';
    }

    if (!in_array($status, ['available', 'maintenance', 'inactive'], true)) {
        $errors[] = '利用状態を選び直してください。';
    }

    return [[
        'name' => $name,
        'category_id' => $categoryId,
        'description' => $description,
        'storage_location' => $storageLocation,
        'status' => $status,
    ], $errors];
}

function audit(PDO $pdo, int $actorId, string $action, int $targetId, array $details): void
{
    $safeDetails = [
        'before_status' => $details['before_status'] ?? ($details['before']['status'] ?? null),
        'after_status' => $details['after_status'] ?? ($details['after']['status'] ?? null),
    ];
    write_audit_log($pdo, $actorId, $action, 'item', $targetId, $safeDetails);
}

function redirect_with_message(string $message): never
{
    $_SESSION['admin_items_flash'] = $message;
    header('Location: /admin_items.php');
    exit;
}

$pdo = null;
$currentUser = null;
$categories = [];
$items = [];
$auditLogs = [];
$pageError = null;
$accessDenied = false;
$flash = $_SESSION['admin_items_flash'] ?? null;
unset($_SESSION['admin_items_flash']);

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

    if (!$accessDenied) {
        $categoryStatement = $pdo->prepare('SELECT id, name FROM categories ORDER BY name');
        $categoryStatement->execute();
        $categories = $categoryStatement->fetchAll();
        $validCategoryIds = array_map(
            static fn(array $category): int => (int) $category['id'],
            $categories
        );

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (
                !isset($_POST['csrf_token'], $_SESSION['csrf_token'])
                || !is_string($_POST['csrf_token'])
                || !hash_equals((string) $_SESSION['csrf_token'], $_POST['csrf_token'])
            ) {
                http_response_code(403);
                throw new DomainException('不正なリクエストです。画面を再読み込みしてください。');
            }

            $action = post_string('action');
            if (!in_array($action, ['create', 'update', 'deactivate'], true)) {
                http_response_code(400);
                throw new DomainException('操作内容を確認してください。');
            }

            if ($action === 'create') {
                [$values, $errors] = validate_item_input($validCategoryIds);
                if ($values['status'] === 'inactive') {
                    $errors[] = '登録時は利用可能またはメンテナンスを選択してください。';
                }
                if ($errors !== []) {
                    throw new DomainException(implode(' ', $errors));
                }

                $pdo->beginTransaction();
                $statement = $pdo->prepare(
                    'INSERT INTO items (category_id, name, description, storage_location, status)'
                    . ' VALUES (?, ?, ?, ?, ?)'
                );
                $statement->execute([
                    $values['category_id'],
                    $values['name'],
                    $values['description'] !== '' ? $values['description'] : null,
                    $values['storage_location'] !== '' ? $values['storage_location'] : null,
                    $values['status'],
                ]);
                $itemId = (int) $pdo->lastInsertId();
                audit($pdo, (int) $currentUser['id'], 'item.create', $itemId, ['after' => $values]);
                $pdo->commit();
                redirect_with_message('施設・備品を登録しました。');
            }

            $idInput = post_string('id');
            $itemId = ctype_digit($idInput) ? (int) $idInput : 0;
            if ($itemId < 1) {
                throw new DomainException('施設・備品を選び直してください。');
            }

            $pdo->beginTransaction();
            $currentItemStatement = $pdo->prepare(
                'SELECT id, category_id, name, description, storage_location, status'
                . ' FROM items WHERE id = ? FOR UPDATE'
            );
            $currentItemStatement->execute([$itemId]);
            $currentItem = $currentItemStatement->fetch();
            if (!$currentItem) {
                throw new DomainException('対象の施設・備品が見つかりません。');
            }

            if ($action === 'update') {
                [$values, $errors] = validate_item_input($validCategoryIds);
                if ($values['status'] === 'inactive' && $currentItem['status'] !== 'inactive') {
                    $errors[] = '利用停止は専用ボタンから実行してください。';
                }
                if ($errors !== []) {
                    throw new DomainException(implode(' ', $errors));
                }

                $statement = $pdo->prepare(
                    'UPDATE items SET category_id = ?, name = ?, description = ?,'
                    . ' storage_location = ?, status = ? WHERE id = ?'
                );
                $statement->execute([
                    $values['category_id'],
                    $values['name'],
                    $values['description'] !== '' ? $values['description'] : null,
                    $values['storage_location'] !== '' ? $values['storage_location'] : null,
                    $values['status'],
                    $itemId,
                ]);
                audit($pdo, (int) $currentUser['id'], 'item.update', $itemId, [
                    'before' => $currentItem,
                    'after' => $values,
                ]);
                $pdo->commit();
                redirect_with_message('施設・備品を変更しました。');
            }

            $busyStatement = $pdo->prepare(
                "SELECT id FROM reservations WHERE item_id = ? AND status IN ('approved', 'lent') LIMIT 1"
            );
            $busyStatement->execute([$itemId]);
            if ($busyStatement->fetch()) {
                throw new DomainException('承認済みまたは貸出中の予約があるため利用停止にできません。');
            }

            if ($currentItem['status'] === 'inactive') {
                throw new DomainException('この施設・備品はすでに利用停止中です。');
            }

            $statement = $pdo->prepare("UPDATE items SET status = 'inactive' WHERE id = ?");
            $statement->execute([$itemId]);
            audit($pdo, (int) $currentUser['id'], 'item.deactivate', $itemId, [
                'before_status' => $currentItem['status'],
                'after_status' => 'inactive',
            ]);
            $pdo->commit();
            redirect_with_message('施設・備品を利用停止にしました。');
        }

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
        $itemStatement = $pdo->prepare(
            'SELECT i.id, i.category_id, i.name, i.description, i.storage_location, i.status,'
            . ' c.name AS category_name FROM items i'
            . ' JOIN categories c ON c.id = i.category_id ORDER BY i.id'
        );
        $itemStatement->execute();
        $items = $itemStatement->fetchAll();

        $auditStatement = $pdo->prepare(
            'SELECT a.action, a.target_id, a.details, a.created_at, u.name AS actor_name'
            . ' FROM audit_logs a JOIN users u ON u.id = a.actor_user_id'
            . " WHERE a.target_type = 'item' ORDER BY a.id DESC LIMIT 20"
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
  <title>施設・備品管理</title>
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
    .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 14px; }
    label { display: grid; gap: 6px; margin-bottom: 12px; font-weight: 600; }
    input, select, textarea, button { padding: 10px; border-radius: 6px; font: inherit; }
    input, select, textarea { width: 100%; border: 1px solid #aebbd0; background: white; }
    textarea { min-height: 90px; resize: vertical; }
    button { border: 0; background: #215a9c; color: white; cursor: pointer; }
    button.danger { background: #a22f27; }
    details { border-top: 1px solid #dce2eb; padding: 14px 0; }
    summary { cursor: pointer; font-weight: 700; }
    .actions { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
    .inline { display: inline; }
    .pill { display: inline-block; padding: 4px 9px; border-radius: 20px; background: #e9effa; }
    .scroll { overflow-x: auto; }
    table { width: 100%; border-collapse: collapse; font-size: .9rem; }
    th, td { padding: 11px; border-bottom: 1px solid #dce2eb; text-align: left; vertical-align: top; }
    th { background: #f0f4f9; }
    code { overflow-wrap: anywhere; }
  </style>
</head>
<body>
<header>
  <h1>学内施設・備品予約管理システム</h1>
  <p>施設・備品管理</p>
  <nav>
    <a href="/items.php">施設・備品一覧</a>
    <a href="/admin_reservations.php">貸出・返却管理</a>
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
      <h2>施設・備品を登録</h2>
      <form method="post" action="/admin_items.php">
        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="action" value="create">
        <div class="grid">
          <label>名称<input name="name" required maxlength="100"></label>
          <label>分類
            <select name="category_id" required>
              <?php foreach ($categories as $category): ?>
                <option value="<?= e($category['id']) ?>"><?= e($category['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>利用状態
            <select name="status" required>
              <option value="available">利用可能</option>
              <option value="maintenance">メンテナンス</option>
            </select>
          </label>
        </div>
        <label>説明<textarea name="description" maxlength="1000"></textarea></label>
        <label>保管場所<input name="storage_location" maxlength="255"></label>
        <button type="submit">登録する</button>
      </form>
    </section>

    <section class="card">
      <h2>施設・備品を変更・利用停止</h2>
      <?php foreach ($items as $item): ?>
        <details>
          <summary>
            <?= e($item['name']) ?>（<?= e($item['category_name']) ?>・<?= e($item['storage_location'] ?: '保管場所未登録') ?>・<?= e(status_label((string) $item['status'])) ?>）
          </summary>
          <form method="post" action="/admin_items.php">
            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" value="<?= e($item['id']) ?>">
            <div class="grid">
              <label>名称<input name="name" required maxlength="100" value="<?= e($item['name']) ?>"></label>
              <label>分類
                <select name="category_id" required>
                  <?php foreach ($categories as $category): ?>
                    <option value="<?= e($category['id']) ?>" <?= (int) $category['id'] === (int) $item['category_id'] ? 'selected' : '' ?>>
                      <?= e($category['name']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label>利用状態
                <select name="status" required>
                  <?php foreach (['available', 'maintenance'] as $status): ?>
                    <option value="<?= e($status) ?>" <?= $status === $item['status'] ? 'selected' : '' ?>><?= e(status_label($status)) ?></option>
                  <?php endforeach; ?>
                  <?php if ($item['status'] === 'inactive'): ?>
                    <option value="inactive" selected>利用停止</option>
                  <?php endif; ?>
                </select>
              </label>
            </div>
            <label>説明<textarea name="description" maxlength="1000"><?= e($item['description']) ?></textarea></label>
            <label>保管場所<input name="storage_location" maxlength="255" value="<?= e($item['storage_location']) ?>"></label>
            <div class="actions">
              <button type="submit">変更を保存</button>
            </div>
          </form>
          <?php if ($item['status'] !== 'inactive'): ?>
            <form method="post" action="/admin_items.php" class="inline">
              <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
              <input type="hidden" name="action" value="deactivate">
              <input type="hidden" name="id" value="<?= e($item['id']) ?>">
              <button type="submit" class="danger">利用停止にする</button>
            </form>
          <?php else: ?>
            <span class="pill">利用停止中</span>
          <?php endif; ?>
        </details>
      <?php endforeach; ?>
    </section>

    <section class="card">
      <h2>監査ログ（最新20件）</h2>
      <div class="scroll">
        <table>
          <thead><tr><th>日時</th><th>実行者</th><th>操作</th><th>対象ID</th><th>内容</th></tr></thead>
          <tbody>
          <?php if ($auditLogs === []): ?>
            <tr><td colspan="5">監査ログはまだありません。</td></tr>
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
