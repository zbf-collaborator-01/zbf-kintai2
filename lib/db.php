<?php
//本番用
// declare(strict_types=1);

// function db(): PDO {
//   static $pdo;
//   if ($pdo) return $pdo;

//   $dsn  = 'mysql:host=localhost;dbname=zerobasefact_portal;charset=utf8mb4';
//   $user = 'zerobasefact_adm';
//   $pass = 'zbfAdmin';

//   $pdo = new PDO($dsn, $user, $pass, [
//     PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
//     PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
//   ]);
//   return $pdo;

//ローカル用(環境に合わせて)
declare(strict_types=1);

function db(): PDO {
    static $pdo;
    if ($pdo) return $pdo;

    // 環境に合わせる
    $dsn = 'mysql:host=127.0.0.1;port=3306;dbname=zbfportal;charset=utf8mb4';
    $user = 'root';
    $pass = ''; 

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    return $pdo;
}


