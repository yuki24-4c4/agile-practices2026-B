<?php
declare(strict_types=1);
require_once __DIR__ . '/audit.php';

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

const IDLE_TIMEOUT_SECONDS = 1800;
// TBD-004: 利用目的の最終最大文字数は未決。詳細設計の仮値。
const PURPOSE_MAX_LENGTH = 500;

if (
    !empty($_SESSION['uid'])
    && isset($_SESSION['last_activity'])
    && time() - (int) $_SESSION['last_activity'] > IDLE_TIMEOUT_SECONDS
) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    session_start();
    $_SESSION['idle_expired'] = true;
    header('Location: /login.php');
    exit;
}

if (empty($_SESSION['uid'])) {
    header('Location: /login.php');
    exit;
}

$_SESSION['last_activity'] = time();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (
        !isset($_POST['csrf_token'], $_SESSION['csrf_token'])
        || !is_string($_POST['csrf_token'])
        || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
    ) {
        http_response_code(403);
        exit(e('不正なリクエストです。'));
    }
}

function e(mixed $value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function valid_date(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}

function utf8_length(string $value): int|false
{
    if (preg_match('//u', $value) !== 1) {
        return false;
    }

    $length = preg_match_all('/./us', $value);

    return $length === false ? false : $length;
}

$currentUser = null;
$item = null;
$error = null;
$formErrors = [];
$notFound = false;
$startDate = '';
$dueDate = '';
$purpose = '';
$flash = null;
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';

if (!$isPost) {
    $flash = $_SESSION['rese_flash'] ?? null;
    unset($_SESSION['rese_flash']);
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

    $userStatement = $pdo->prepare('SELECT id, name, role FROM users WHERE id = ?');
    $userStatement->execute([(int) $_SESSION['uid']]);
    $currentUser = $userStatement->fetch() ?: null;

    if ($currentUser === null) {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
        header('Location: /login.php');
        exit;
    }

    $idInput = $isPost ? ($_POST['item_id'] ?? '') : ($_GET['id'] ?? '');
    $itemId = null;

    if (!is_string($idInput) || !ctype_digit($idInput) || (int) $idInput < 1) {
        if ($isPost) {
            http_response_code(400);
            $formErrors[] = '対象を確認してください。';
        } else {
            http_response_code(404);
            $notFound = true;
        }
    } else {
        $itemId = (int) $idInput;
        $itemStatement = $pdo->prepare('SELECT id, name, status FROM items WHERE id = ?');
        $itemStatement->execute([$itemId]);
        $item = $itemStatement->fetch() ?: null;

        if ($item === null) {
            http_response_code(404);
            $notFound = true;
        }
    }

    if ($isPost && $item !== null && $itemId !== null) {
        $startInput = $_POST['start_date'] ?? '';
        $dueInput = $_POST['due_date'] ?? '';
        $purposeInput = $_POST['purpose'] ?? '';
        $startDate = is_string($startInput) ? trim($startInput) : '';
        $dueDate = is_string($dueInput) ? trim($dueInput) : '';
        $purpose = is_string($purposeInput) ? trim($purposeInput) : '';

        if ($startDate === '') {
            $formErrors[] = '利用開始日を入力してください。';
        } elseif (!valid_date($startDate)) {
            $formErrors[] = '利用開始日を確認してください。';
        }

        if ($dueDate === '') {
            $formErrors[] = '返却予定日を入力してください。';
        } elseif (!valid_date($dueDate)) {
            $formErrors[] = '返却予定日を確認してください。';
        }

        if ($formErrors === [] && $dueDate < $startDate) {
            $formErrors[] = '返却予定日は利用開始日以降の日付にしてください。';
        }

        if (!is_string($purposeInput)) {
            $formErrors[] = '利用目的を確認してください。';
        } elseif ($purpose === '') {
            $formErrors[] = '利用目的を入力してください。';
        } else {
            $purposeLength = utf8_length($purpose);
            if ($purposeLength === false) {
                $formErrors[] = '利用目的を確認してください。';
            } elseif ($purposeLength > PURPOSE_MAX_LENGTH) {
                $formErrors[] = '利用目的は' . PURPOSE_MAX_LENGTH . '文字以内で入力してください。';
            }
        }

        if ($item['status'] !== 'available') {
            $formErrors[] = 'この施設・備品は予約できません。';
        }

        if ($formErrors !== []) {
            http_response_code(400);
        } else {
            $pdo->beginTransaction();
            try {
                $lockStatement = $pdo->prepare(
                    'SELECT id, name, status FROM items WHERE id = ? FOR UPDATE'
                );
                $lockStatement->execute([$itemId]);
                $lockedItem = $lockStatement->fetch() ?: null;

                if ($lockedItem === null) {
                    $pdo->rollBack();
                    http_response_code(404);
                    $notFound = true;
                    $item = null;
                } elseif ($lockedItem['status'] !== 'available') {
                    $pdo->rollBack();
                    http_response_code(400);
                    $item = $lockedItem;
                    $formErrors[] = 'この施設・備品は予約できません。';
                } else {
                    $duplicateStatement = $pdo->prepare(
                        'SELECT id FROM reservations'
                        . ' WHERE user_id = ? AND item_id = ?'
                        . ' AND start_date = ? AND end_date = ?'
                        . " AND status = 'pending'"
                        . ' LIMIT 1'
                    );
                    $duplicateStatement->execute([
                        (int) $currentUser['id'],
                        $itemId,
                        $startDate,
                        $dueDate,
                    ]);

                    if ($duplicateStatement->fetch()) {
                        $pdo->rollBack();
                        http_response_code(400);
                        $item = $lockedItem;
                        $formErrors[] = '同じ内容の申請中予約がすでにあります。';
                    } else {
                        $insertStatement = $pdo->prepare(
                            'INSERT INTO reservations'
                            . ' (user_id, item_id, start_date, end_date, purpose, status)'
                            . " VALUES (?, ?, ?, ?, ?, 'pending')"
                        );
                        $insertStatement->execute([
                            (int) $currentUser['id'],
                            $itemId,
                            $startDate,
                            $dueDate,
                            $purpose,
                        ]);
                        write_audit_log($pdo, (int) $currentUser['id'], 'reservation.create', 'reservation', (int) $pdo->lastInsertId(), [
                            'user_id' => (int) $currentUser['id'], 'item_id' => $itemId, 'after_status' => 'pending',
                        ]);
                        $pdo->commit();
                        $_SESSION['rese_flash'] = '予約申請を受け付けました。';
                        header('Location: /rese_request.php?id=' . $itemId, true, 303);
                        exit;
                    }
                }
            } catch (PDOException $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $exception;
            }
        }
    }
} catch (PDOException) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    $error = '処理中にエラーが発生しました。';
    $item = null;
    $formErrors = [];
}

$showForm = $item !== null && $item['status'] === 'available' && $error === null;
?>
<!doctype html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>予約申請</title>
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
    .alert.success { background: #e2f4e9; color: #146c43; }
    .alert li { margin: 4px 0; }
    label { display: block; margin: 14px 0; font-weight: 600; }
    input, textarea { display: block; width: 100%; margin-top: 6px; padding: 10px; border: 1px solid #aebbd0; border-radius: 6px; font: inherit; }
    input[type="date"] { max-width: 220px; }
    textarea { min-height: 120px; resize: vertical; }
    button[type="submit"] { margin-top: 8px; background: #215a9c; color: white; padding: 10px 16px; border: 0; border-radius: 6px; cursor: pointer; font: inherit; }
    button[type="submit"]:hover { background: #143c6d; }
    .actions { display: flex; gap: 14px; align-items: center; flex-wrap: wrap; margin-top: 18px; }
    .muted { color: #617287; }
    a { color: #215a9c; }
  </style>
</head>
<body>
<header>
  <h1>学内施設・備品予約管理システム</h1>
  <p>予約申請</p>
  <nav>
    <a href="/items.php">施設・備品一覧</a>
    <?php if ($currentUser): ?>
      <span><?= e($currentUser['name']) ?>さん</span>
      <form method="post" action="/logout.php">
        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token'] ?? '') ?>">
        <button type="submit">ログアウト</button>
      </form>
    <?php endif; ?>
  </nav>
</header>
<main>
  <section class="card">
    <?php if ($error): ?>
      <h2>エラー</h2>
      <p class="alert" role="alert"><?= e($error) ?></p>
    <?php elseif ($notFound || $item === null): ?>
      <h2><?= $notFound ? '施設・備品が見つかりません' : '予約申請' ?></h2>
      <?php if ($formErrors): ?>
        <ul class="alert" role="alert">
          <?php foreach ($formErrors as $formError): ?>
            <li><?= e($formError) ?></li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <p class="alert" role="alert">指定された施設・備品は存在しないか、利用できません。</p>
      <?php endif; ?>
      <p><a href="/items.php">一覧へ戻る</a></p>
    <?php else: ?>
      <h2>予約申請：<?= e($item['name']) ?></h2>
      <?php if (is_string($flash) && $flash !== ''): ?>
        <p class="alert success" role="status"><?= e($flash) ?></p>
      <?php endif; ?>
      <?php if ($formErrors): ?>
        <ul class="alert" role="alert">
          <?php foreach ($formErrors as $formError): ?>
            <li><?= e($formError) ?></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <?php if ($showForm): ?>
        <form method="post" action="/rese_request.php">
          <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token'] ?? '') ?>">
          <input type="hidden" name="item_id" value="<?= e($item['id']) ?>">
          <label>利用開始日
            <input type="date" name="start_date" required value="<?= e($startDate) ?>">
          </label>
          <label>返却予定日
            <input type="date" name="due_date" required value="<?= e($dueDate) ?>">
          </label>
          <label>利用目的
            <textarea name="purpose" required maxlength="<?= e((string) PURPOSE_MAX_LENGTH) ?>"><?= e($purpose) ?></textarea>
          </label>
          <button type="submit">予約を申請</button>
        </form>
      <?php else: ?>
        <p class="muted">現在、この施設・備品は予約できません。</p>
      <?php endif; ?>
      <div class="actions">
        <a href="/item_detail.php?id=<?= e($item['id']) ?>">詳細へ戻る</a>
        <a href="/items.php">一覧へ戻る</a>
      </div>
    <?php endif; ?>
  </section>
</main>
</body>
</html>
