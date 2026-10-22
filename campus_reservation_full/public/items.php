<?php
declare(strict_types=1);

header('Content-Type: text/html; charset=UTF-8');
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
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
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

$items = [];
$currentUser = null;
$error = null;

try {
    $pdo = new PDO(
        'mysql:host=' . getenv('DB_HOST')
            . ';dbname=' . getenv('DB_NAME')
            . ';charset=utf8mb4',
        (string) getenv('DB_USER'),
        (string) getenv('DB_PASSWORD'),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    if (!empty($_SESSION['uid'])) {
        $userStatement = $pdo->prepare(
            'SELECT id, name, role FROM users WHERE id = ?'
        );
        $userStatement->execute([(int) $_SESSION['uid']]);
        $currentUser = $userStatement->fetch() ?: null;
    }

    $itemStatement = $pdo->prepare(
        'SELECT i.id, i.name, c.name AS category_name, i.status'
            . ' FROM items i'
            . ' JOIN categories c ON c.id = i.category_id'
            . ' ORDER BY i.id'
    );
    $itemStatement->execute();
    $items = $itemStatement->fetchAll();
} catch (PDOException) {
    http_response_code(500);
    $error = '施設・備品を取得できませんでした。時間をおいて再度お試しください。';
}

$isAdmin = ($currentUser['role'] ?? null) === 'admin';
?>
<!doctype html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>施設・備品一覧</title>
  <style>
    :root { font-family: system-ui, -apple-system, "Yu Gothic", Meiryo, sans-serif; color: #1d2939; background: #f3f6fb; }
    * { box-sizing: border-box; }
    body { margin: 0; }
    header { background: #17365d; color: white; padding: 22px max(4vw, 20px); }
    header h1 { margin: 0 0 6px; font-size: 1.5rem; }
    header p { margin: 0; color: #d7e3f3; }
    nav { display: flex; gap: 14px; margin-top: 16px; align-items: center; }
    nav a { color: white; }
    nav form { margin: 0; }
    nav button { padding: 7px 12px; border: 0; border-radius: 5px; cursor: pointer; }
    main { max-width: 1100px; margin: 28px auto; padding: 0 20px; }
    .card { background: white; padding: 23px; border-radius: 10px; box-shadow: 0 2px 12px #182e4615; }
    .alert { padding: 13px 18px; border-radius: 6px; background: #ffe5e2; color: #8a2b22; }
    .scroll { overflow-x: auto; }
    table { border-collapse: collapse; width: 100%; }
    th, td { padding: 12px; border-bottom: 1px solid #dce2eb; text-align: left; }
    th { background: #f0f4f9; }
    .pill { display: inline-block; padding: 4px 9px; border-radius: 20px; background: #e9effa; }
    .empty { text-align: center; color: #617287; }
  </style>
</head>
<body>
<header>
  <h1>学内施設・備品予約管理システム</h1>
  <p>施設・備品一覧</p>
  <nav>
    <?php if ($currentUser): ?>
      <span><?= e($currentUser['name']) ?>さん</span>
      <form method="post" action="/logout.php">
        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token'] ?? '') ?>">
        <button type="submit">ログアウト</button>
      </form>
    <?php else: ?>
      <a href="/login.php">ログイン</a>
    <?php endif; ?>
  </nav>
</header>
<main>
  <section class="card">
    <h2>施設・備品一覧</h2>
    <?php if ($error): ?>
      <p class="alert" role="alert"><?= e($error) ?></p>
    <?php else: ?>
      <div class="scroll">
        <table>
          <thead>
          <tr>
            <?php if ($isAdmin): ?><th>管理用ID</th><?php endif; ?>
            <th>名称</th>
            <th>分類</th>
            <th>利用状態</th>
          </tr>
          </thead>
          <tbody>
          <?php if (!$items): ?>
            <tr><td class="empty" colspan="<?= $isAdmin ? '4' : '3' ?>">表示できる施設・備品はありません。</td></tr>
          <?php else: ?>
            <?php foreach ($items as $item): ?>
              <tr>
                <?php if ($isAdmin): ?><td><?= e($item['id']) ?></td><?php endif; ?>
                <td><?= e($item['name']) ?></td>
                <td><?= e($item['category_name']) ?></td>
                <td><span class="pill"><?= e(status_label((string) $item['status'])) ?></span></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>
</main>
</body>
</html>
