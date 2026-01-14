<?php
//使用していない
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'httponly' => true,
        'secure'   => false, // HTTPSにしたらtrueに
        'samesite' => 'Lax',
    ]);
    session_start();
}

// 後でDBやSSOに置き換える前提
function login_stub(string $email, string $password): bool {
    $validEmail = 'test@example.com';
    $validPass  = 'Password123!';

    if ($email === $validEmail && $password === $validPass) {
        session_regenerate_id(true);
        $_SESSION['uid']   = 1;
        $_SESSION['email'] = $email;
        return true;
    }
    return false;
}

// 未ログイン時の強制リダイレクト
function require_login(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_set_cookie_params([
            'httponly' => true,
            'secure'   => false,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
    if (empty($_SESSION['uid'])) {
        // 現在スクリプトのディレクトリを基準に /index.php へ返す
        $base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
        header('Location: ' . ($base ?: '/') . '/index.php');
        exit;
    }
}

// ログアウト後の戻り先も同様に
function logout_now(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    $base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
    header('Location: ' . ($base ?: '/') . '/index.php');
    exit;
}

