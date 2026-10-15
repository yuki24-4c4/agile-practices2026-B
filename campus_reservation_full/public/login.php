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

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (
        !isset($_POST['csrf_token'], $_SESSION['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], (string) $_POST['csrf_token'])
    ) {
        http_response_code(403);
        exit(e('不正なリクエストです。'));
    }

    // TODO: TBD-005。失敗回数と制限時間は未決。
    // TODO: REQ-F-001 の利用停止。現行 users に active 列がないため未判定。
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $row = null;
    if ($email === '' || $password === '') {
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
        $error = 'ログイン情報を確認してください';
    }
    if ($error === null) {
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
  <title>ログイン</title>
  <style>
    form.login-form { max-width: 440px; }
    form.login-form .form-error { margin: 4px 0 16px; padding: 12px 14px; border: 1px solid #e7b2ab; border-radius: 6px; background: #ffe5e2; color: #8a2b22; font-weight: 600; }
    form.login-form button { margin-top: 12px; min-width: 9rem; padding: 11px 22px; background: #215a9c; color: white; border: 0; border-radius: 6px; cursor: pointer; }
  </style>
</head>
<body>
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
</body>
</html>