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
$categories = [];
$currentUser = null;
$error = null;
$searchError = null;
$keywordInput = $_GET['keyword'] ?? '';
$categoryInput = $_GET['category_id'] ?? '';
$keyword = is_string($keywordInput) ? trim($keywordInput) : '';
$categoryId = null;

if (!is_string($keywordInput) || preg_match('//u', $keyword) !== 1) {
    $searchError = '検索キーワードを確認してください。';
} elseif (preg_match_all('/./us', $keyword) > 100) {
    $searchError = '検索キーワードは100文字以内で入力してください。';
}

if ($categoryInput !== '') {
    if (!is_string($categoryInput) || !ctype_digit($categoryInput) || (int) $categoryInput < 1) {
        $searchError = '分類を選び直してください。';
    } else {
        $categoryId = (int) $categoryInput;
    }
}

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

    $categoryStatement = $pdo->prepare('SELECT id, name FROM categories ORDER BY name');
    $categoryStatement->execute();
    $categories = $categoryStatement->fetchAll();

    if ($categoryId !== null) {
        $validCategoryIds = array_map(
            static fn(array $category): int => (int) $category['id'],
            $categories
        );
        if (!in_array($categoryId, $validCategoryIds, true)) {
            $searchError = '分類を選び直してください。';
        }
    }

    if ($searchError === null) {
        $itemSql = 'SELECT i.id, i.name, c.name AS category_name,'
            . ' i.storage_location, i.status'
            . ' FROM items i'
            . ' JOIN categories c ON c.id = i.category_id'
            . ' WHERE 1 = 1';
        $itemParameters = [];

        if ($keyword !== '') {
            $itemSql .= ' AND i.name LIKE ?';
            $itemParameters[] = '%' . $keyword . '%';
        }
        if ($categoryId !== null) {
            $itemSql .= ' AND i.category_id = ?';
            $itemParameters[] = $categoryId;
        }
        $itemSql .= ' ORDER BY i.id';

        $itemStatement = $pdo->prepare($itemSql);
        $itemStatement->execute($itemParameters);
        $items = $itemStatement->fetchAll();
    }
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
    .search { display: grid; grid-template-columns: minmax(220px, 1fr) minmax(180px, 280px) auto auto; gap: 12px; align-items: end; margin-bottom: 20px; }
    .search label { display: grid; gap: 6px; font-weight: 600; }
    .search input, .search select, .search button, .search a { min-height: 42px; padding: 9px 12px; border-radius: 6px; font: inherit; }
    .search input, .search select { border: 1px solid #aebbd0; background: white; }
    .search button { border: 0; background: #215a9c; color: white; cursor: pointer; }
    .search a { display: inline-flex; align-items: center; color: #215a9c; }
    .scroll { overflow-x: auto; }
    table { border-collapse: collapse; width: 100%; }
    th, td { padding: 12px; border-bottom: 1px solid #dce2eb; text-align: left; }
    th { background: #f0f4f9; }
    .pill { display: inline-block; padding: 4px 9px; border-radius: 20px; background: #e9effa; }
    .empty { text-align: center; color: #617287; }
    .detail-link { white-space: nowrap; }
    @media (max-width: 760px) { .search { grid-template-columns: 1fr; } }
  </style>
</head>
<body>
<header>
  <h1>学内施設・備品予約管理システム</h1>
  <p>施設・備品一覧</p>
  <nav>
    <?php if ($currentUser): ?>
      <span><?= e($currentUser['name']) ?>さん</span>
      <?php if ($isAdmin): ?>
        <a href="/admin_reservations.php">貸出・返却管理</a>
        <a href="/admin_items.php">施設・備品管理</a>
      <?php endif; ?>
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
    <form method="get" action="/items.php" class="search">
      <label>
        名称
        <input type="search" name="keyword" maxlength="100" value="<?= e($keyword) ?>" placeholder="例：教室、ノートPC">
      </label>
      <label>
        分類
        <select name="category_id">
          <option value="">すべての分類</option>
          <?php foreach ($categories as $category): ?>
            <option value="<?= e($category['id']) ?>" <?= $categoryId === (int) $category['id'] ? 'selected' : '' ?>>
              <?= e($category['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <button type="submit">検索</button>
      <a href="/items.php">条件をクリア</a>
    </form>
    <?php if ($error): ?>
      <p class="alert" role="alert"><?= e($error) ?></p>
    <?php elseif ($searchError): ?>
      <p class="alert" role="alert"><?= e($searchError) ?></p>
    <?php else: ?>
      <div class="scroll">
        <table>
          <thead>
          <tr>
            <?php if ($isAdmin): ?><th>管理用ID</th><?php endif; ?>
            <th>名称</th>
            <th>分類</th>
            <th>保管場所</th>
            <th>利用状態</th>
            <th>詳細</th>
          </tr>
          </thead>
          <tbody>
          <?php if (!$items): ?>
            <tr><td class="empty" colspan="<?= $isAdmin ? '6' : '5' ?>">検索条件に一致する施設・備品はありません。</td></tr>
          <?php else: ?>
            <?php foreach ($items as $item): ?>
              <tr>
                <?php if ($isAdmin): ?><td><?= e($item['id']) ?></td><?php endif; ?>
                <td><?= e($item['name']) ?></td>
                <td><?= e($item['category_name']) ?></td>
                <td><?= e($item['storage_location'] ?: '未登録') ?></td>
                <td><span class="pill"><?= e(status_label((string) $item['status'])) ?></span></td>
                <td><a class="detail-link" href="/item_detail.php?id=<?= e($item['id']) ?>">詳細を見る</a></td>
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
