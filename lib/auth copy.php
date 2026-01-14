<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'httponly' => true,
        'secure' => false, // 本番は trueにする
        'samesite' => 'Lax',
    ]);
    session_start();
}

/* 基本ログイン  */
function login_with_password(string $email, string $password, bool $remember): bool
{
    $pdo = db();
    $st = $pdo->prepare("SELECT email, password, executive, name FROM members WHERE email = ?");
    $st->execute([$email]);
    $row = $st->fetch();
    if (!$row)
        return false;

    //パスワードがハッシュ値に適合するかどうかを調査する関数
    if (!password_verify($password, $row['password']))
        return false;

    // セッションにログイン状態を載せる
    session_regenerate_id(true);
    $_SESSION['email'] = $row['email'];
    $_SESSION['name'] = $row['name']; // 名前保存
    $_SESSION['is_executive'] = !empty($row['executive']) && (int) $row['executive'] === 1; // 管理者フラグ保存

    if ($remember) {
        issue_remember_cookie($row['email'], 30);
    }
    return true;
}

//  自動ログイン用のトークンを発行
function issue_remember_cookie(string $email, int $days = 30): void
{
    $selector = bin2hex(random_bytes(12));
    $validator = bin2hex(random_bytes(20));

    $hash = hash('sha256', $validator);
    $expires = (new DateTimeImmutable("+{$days} days"))->format('Y-m-d H:i:s');

    $pdo = db();
    $stmt = $pdo->prepare("
        INSERT INTO remember_tokens (email, selector, validator_hash, expires_at)
        VALUES (:email, :selector, :hash, :exp)
    ");
    $stmt->execute([
        ':email' => $email,
        ':selector' => $selector,
        ':hash' => $hash,
        ':exp' => $expires,
    ]);

    $cookieValue = $selector . ':' . $validator;
    setcookie('remember_me', $cookieValue, [
        'expires' => time() + 60 * 60 * 24 * $days,
        'path' => '/',
        'secure' => false, // 本番はtrue
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/* Cookieから自動ログイン */
function try_login_from_cookie(): bool
{
    $cookie = $_COOKIE['remember_me'] ?? '';
    if ($cookie === '' || !str_contains($cookie, ':'))
        return false;

    [$selector, $validator] = explode(':', $cookie, 2);
    if ($selector === '' || $validator === '')
        return false;

    $pdo = db();
    // 1. まずトークンテーブルをチェック
    $st = $pdo->prepare("SELECT email, validator_hash, expires_at FROM remember_tokens WHERE selector = ?");
    $st->execute([$selector]);
    $row = $st->fetch();
    if (!$row) {
        clear_remember_cookie();
        return false;
    }

    // 期限チェック
    if (new DateTimeImmutable($row['expires_at']) < new DateTimeImmutable('now')) {
        delete_token_by_selector($selector);
        clear_remember_cookie();
        return false;
    }

    // バリデータ検証
    $calc = hash('sha256', $validator);
    if (!hash_equals($row['validator_hash'], $calc)) {
        delete_token_by_selector($selector);
        clear_remember_cookie();
        return false;
    }

    // 2. トークンが正しければ、ユーザー情報をmembersテーブルから再取得 
    //    これがないと、自動ログイン時に管理者権限や名前が復元されない。
    $stUser = $pdo->prepare("SELECT name, executive FROM members WHERE email = ?");
    $stUser->execute([$row['email']]);
    $userRow = $stUser->fetch();

    if (!$userRow) {
        // トークンはあるがユーザー自体が削除されている場合など
        delete_token_by_selector($selector);
        clear_remember_cookie();
        return false;
    }

    // OK: セッションに完全なログイン状態を載せる
    session_regenerate_id(true);
    $_SESSION['email'] = $row['email'];
    $_SESSION['name']  = $userRow['name']; // 名前を復元
    $_SESSION['is_executive'] = !empty($userRow['executive']) && (int) $userRow['executive'] === 1; // 権限を復元

    // ワンタイム性を高めるためトークンをローテーション
    delete_token_by_selector($selector);
    issue_remember_cookie($row['email']);
    return true;
}

/* ログインしてなければログイン画面へ飛ばす  */
function require_login(): void
{
    if (!empty($_SESSION['email']))
        return;
    if (try_login_from_cookie())
        return;

    $base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
    header('Location: ' . ($base ?: '/') . '/index.php');
    exit;
}

function logout_all(): void
{
    $cookie = $_COOKIE['remember_me'] ?? '';
    if ($cookie && str_contains($cookie, ':')) {
        [$selector] = explode(':', $cookie, 2);
        delete_token_by_selector($selector);
    }
    clear_remember_cookie();

    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function delete_token_by_selector(string $selector): void
{
    $pdo = db();
    $pdo->prepare("DELETE FROM remember_tokens WHERE selector = ?")->execute([$selector]);
}

function clear_remember_cookie(): void
{
    setcookie('remember_me', '', [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => false, // 本番は true
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}