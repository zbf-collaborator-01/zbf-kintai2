<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $email = trim((string) ($_POST['email']));
  $pass = (string) ($_POST['password']);
  $remember = isset($_POST['remember']);

  if ($email === '' || $pass === '') {
    $error = 'メールアドレスとパスワードは必須です。';
  } elseif (login_with_password($email, $pass, $remember)) {
    $base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
    header('Location: ' . ($base ?: '/') . '/home.php');
    exit;
  } else {
    $error = 'ログインに失敗しました。';
  }
}
?>
<!doctype html>
<html lang="ja">

<head>
  <meta charset="UTF-8">
  <title>Login</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body {
      font-family: system-ui;
      margin: 40px
    }

    .box {
      max-width: 560px;
      padding: 24px;
      border: 1px solid #ddd;
      border-radius: 8px
    }

    label {
      display: block;
      margin: 12px 0 4px
    }

    input {
      width: 100%;
      padding: 8px
    }
  </style>
</head>

<form action="register.php" method="post">
  <input type="email" name="email" value="test@example.com">
  <input type="date" name="work_date" value="2025-01-01">

  <input type="hidden" name="time_blocks[0][status_id]" value="1">
  <input type="hidden" name="time_blocks[0][start_time]" value="09:00">
  <input type="hidden" name="time_blocks[0][end_time]" value="12:00">

  <button type="submit">送信</button>
</form>

<body>
  <div class="box">
    <h1>ログイン</h1>
    <?php if ($error): ?>
      <p style="color:#c00"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
    <?php endif; ?>
    <form method="post" action="">
      <label>メールアドレス</label>
      <input type="email" name="email" required>
      <label>パスワード</label>
      <input type="password" name="password" required>
      <label style="display:flex;gap:.5rem;align-items:center">
        <input type="checkbox" name="remember" value="1" style="width:auto"> 30日間ログイン状態を保持
      </label>
      <button type="submit" style="margin-top:12px;padding:10px 16px">サインイン</button>
    </form>
  </div>
</body>

</html>