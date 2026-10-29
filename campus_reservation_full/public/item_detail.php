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

$item = null;
$currentUser = null;
$error = null;
$notFound = false;
$idInput = $_GET['id'] ?? '';

if (!is_string($idInput) || !ctype_digit($idInput) || (int) $idInput < 1) {
    http_response_code(404);
    $notFound = true;
} else {
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
            'SELECT i.id, i.name, i.description, i.status,'
                . ' c.name AS category_name, i.storage_location'
                . ' FROM items i'
                . ' JOIN categories c ON c.id = i.category_id'
                . ' WHERE i.id = ?'
        );
        $itemStatement->execute([(int) $idInput]);
        $item = $itemStatement->fetch() ?: null;

        if ($item === null) {
            http_response_code(404);
            $notFound = true;
        }
    } catch (PDOException) {
        http_response_code(500);
        $error = '施設・備品の詳細を取得できませんでした。時間をおいて再度お試しください。';
    }
}

$isAdmin = ($currentUser['role'] ?? null) === 'admin';
?>
<!doctype html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>施設・備品詳細</title>
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
    main { max-width: 860px; margin: 28px auto; padding: 0 20px; }
    .card { background: white; padding: 23px; border-radius: 10px; box-shadow: 0 2px 12px #182e4615; }
    .alert { padding: 13px 18px; border-radius: 6px; background: #ffe5e2; color: #8a2b22; }
    .details { display: grid; grid-template-columns: 150px minmax(0, 1fr); margin: 22px 0; border-top: 1px solid #dce2eb; }
    .details dt, .details dd { margin: 0; padding: 14px; border-bottom: 1px solid #dce2eb; }
    .details dt { background: #f0f4f9; font-weight: 700; }
    .details dd { white-space: pre-wrap; overflow-wrap: anywhere; }
    .pill { display: inline-block; padding: 4px 9px; border-radius: 20px; background: #e9effa; }
    .actions { display: flex; gap: 14px; align-items: center; flex-wrap: wrap; }
    .button { display: inline-block; padding: 10px 16px; border-radius: 6px; background: #215a9c; color: white; text-decoration: none; }
    .muted { color: #617287; }
    @media (max-width: 600px) { .details { grid-template-columns: 1fr; } .details dd { border-top: 0; } }
  </style>
</head>
<body>
<header>
  <h1>学内施設・備品予約管理システム</h1>
  <p>施設・備品詳細</p>
  <nav>
    <a href="/items.php">施設・備品一覧</a>
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
    <?php if ($error): ?>
      <h2>エラー</h2>
      <p class="alert" role="alert"><?= e($error) ?></p>
    <?php elseif ($notFound): ?>
      <h2>施設・備品が見つかりません</h2>
      <p class="alert" role="alert">指定された施設・備品は存在しないか、利用できません。</p>
    <?php else: ?>
      <h2><?= e($item['name']) ?></h2>
      <dl class="details">
        <?php if ($isAdmin): ?>
          <dt>管理用ID</dt>
          <dd><?= e($item['id']) ?></dd>
        <?php endif; ?>
        <dt>名称</dt>
        <dd><?= e($item['name']) ?></dd>
        <dt>分類</dt>
        <dd><?= e($item['category_name']) ?></dd>
        <dt>説明</dt>
        <dd><?= e($item['description'] ?: '未登録') ?></dd>
        <dt>保管場所</dt>
        <dd><?= e($item['storage_location'] ?: '未登録') ?></dd>
        <dt>利用状態</dt>
        <dd><span class="pill"><?= e(status_label((string) $item['status'])) ?></span></dd>
      </dl>
      <div class="actions">
        <?php if ($item['status'] === 'available' && $currentUser): ?>
          <a class="button" href="/rese_request.php?id=<?= e($item['id']) ?>">予約する</a>
        <?php elseif ($item['status'] === 'available'): ?>
          <a class="button" href="/login.php">ログインして予約する</a>
        <?php else: ?>
          <span class="muted">現在、この施設・備品は予約できません。</span>
        <?php endif; ?>
        <a href="/items.php">一覧へ戻る</a>
      </div>
    <?php endif; ?>
  </section>
</main>
</body>
</html>
