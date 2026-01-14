<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/auth.php';
logout_all();
    $base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
    header('Location: ' . ($base ?: '/') . '/index.php');
exit;
