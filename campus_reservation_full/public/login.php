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

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

const LOGIN_MAX_FAILURES = 5;
const LOGIN_LOCK_SECONDS = 30;
const IDLE_TIMEOUT_SECONDS = 1800;

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

if (!empty($_SESSION['uid']) && isset($_SESSION['last_activity'])) {
    $_SESSION['last_activity'] = time();
}

$idleExpired = !empty($_SESSION['idle_expired']);
unset($_SESSION['idle_expired']);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error = $idleExpired ? '一定時間操作がなかったため、再度ログインしてください。' : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (
        !isset($_POST['csrf_token'], $_SESSION['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], (string) $_POST['csrf_token'])
    ) {
        http_response_code(403);
        exit(e('不正なリクエストです。'));
    }

    // TODO: カウント方式は未決。現行スキーマに login_attempts がないため、セッションへ失敗回数と最終失敗時刻を記録する。
    // TODO: REQ-F-001 の利用停止。現行 users に active 列がないため未判定。
    $_SESSION['login_failures'] = (int) ($_SESSION['login_failures'] ?? 0);
    if (
        $_SESSION['login_failures'] >= LOGIN_MAX_FAILURES
        && isset($_SESSION['login_failed_at'])
        && time() - (int) $_SESSION['login_failed_at'] >= LOGIN_LOCK_SECONDS
    ) {
        $_SESSION['login_failures'] = 0;
        unset($_SESSION['login_failed_at']);
    }
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $row = null;
    if ($_SESSION['login_failures'] >= LOGIN_MAX_FAILURES) {
        $error = 'ログインの試行回数が上限に達しました。';
    } elseif ($email === '' || $password === '') {
        $error = 'メールアドレスとパスワードを入力してください';
    } elseif (strlen($password) < 8) {
        $error = 'パスワードは8文字以上で入力してください';
    }

    if ($error === null) {
        try {
            $pdo = new PDO(
                'mysql:host=' . getenv('DB_HOST') . ';dbname=' . getenv('DB_NAME') . ';charset=utf8mb4',
                (string) getenv('DB_USER'),
                (string) getenv('DB_PASSWORD'),
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
            );
            $stmt = $pdo->prepare('SELECT id, password FROM users WHERE email = ?');
            $stmt->execute([$email]);
            $row = $stmt->fetch();
        } catch (PDOException) {
            $error = '処理中にエラーが発生しました';
        }
    }

    if ($error === null && (!$row || !password_verify($password, (string) $row['password']))) {
        $_SESSION['login_failures']++;
        $_SESSION['login_failed_at'] = time();
        $error = $_SESSION['login_failures'] >= LOGIN_MAX_FAILURES
            ? 'ログインの試行回数が上限に達しました。'
            : 'ログイン情報を確認してください';
    }
    if ($error === null) {
        unset($_SESSION['login_failures'], $_SESSION['login_failed_at']);
        session_regenerate_id(true);
        $_SESSION['last_activity'] = time();
        $_SESSION['uid'] = $row['id'];
        // TODO: 設計のセッションキー user_id, role は未採用。
        // TODO: 管理トップの URL は未確定。現行の一覧は /?page=items。
        $_SESSION['flash'] = ['message' => 'ログインしました。', 'error' => false];
        header('Location: /?page=items');
        exit;
    }
}
?>
<!doctype html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ログイン</title>
  <style>
    :root { font-family: system-ui, -apple-system, "Yu Gothic", Meiryo, sans-serif; color: #1d2939; background: #f3f6fb; }
    * { box-sizing: border-box; }
    body { margin: 0; }
    h2 { margin: 0 0 4px; font-size: 1.25rem; font-weight: 700; }
    main { margin: 28px auto; max-width: 1200px; padding: 0 20px; }
    .card { background: #fff; padding: 23px; border-radius: 10px; box-shadow: 0 2px 12px #182e4615; }
    form.login-form { max-width: 440px; }
    label { display: block; margin: 10px 0; font-weight: 600; }
    label input { display: block; width: 100%; margin-top: 5px; padding: 10px; border: 1px solid #aebbd0; border-radius: 6px; font: inherit; background: #fff; }
    form.login-form button { margin-top: 6px; background: #215a9c; color: #fff; padding: 10px 14px; border: 0; border-radius: 6px; cursor: pointer; font: inherit; }
    form.login-form button:hover { background: #143c6d; }
    form.login-form .form-error { margin: 8px 0 16px; padding: 12px 14px; border: 1px solid #e7b2ab; border-radius: 6px; background: #ffe5e2; color: #8a2b22; font-weight: 600; }
  </style>
</head>
<body>
<main>
  <section class="card">
    <h2>ログイン</h2>
    <form method="post" action="/login.php" class="login-form">
      <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
      <?php if ($error): ?><p class="form-error" role="alert"><?= e($error) ?></p><?php endif; ?>
      <!-- TODO: DES-SCR-001 のログインID種別は未決。ここでは現行の email。 -->
      <label>メールアドレス
        <input type="email" name="email" required autocomplete="username" value="<?= e((string) ($_POST['email'] ?? '')) ?>">
      </label>
      <label>パスワード
        <input type="password" name="password" required autocomplete="current-password">
      </label>
      <button type="submit">ログイン</button>
    </form>
  </section>
</main>
</body>
</html>