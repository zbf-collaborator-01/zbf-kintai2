<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/auth.php';
require_login();
require_once __DIR__ . '/../lib/db.php';

header('Content-Type: application/json');

$pdo = db();

// JSONで受け取る
$input = json_decode(file_get_contents('php://input'), true);
$email = $input['email'] ?? null;
$dates = $input['dates'] ?? [];

if (!$email || empty($dates)) {
    http_response_code(400);
    echo json_encode(['error' => 'email または dates が指定されていません']);
    exit;
}

try {
    $pdo->beginTransaction();

    // 複数日を削除
    $stmtDay = $pdo->prepare("SELECT kintai_day_id FROM kintai_day WHERE email = :email AND work_date = :date");
    $stmtTime = $pdo->prepare("DELETE FROM kintai_time WHERE kintai_day_id = :day_id");
    $stmtDeleteDay = $pdo->prepare("DELETE FROM kintai_day WHERE kintai_day_id = :day_id");

    foreach ($dates as $date) {
        $stmtDay->execute([':email' => $email, ':date' => $date]);
        $day = $stmtDay->fetch(PDO::FETCH_ASSOC);
        if (!$day) continue;

        $day_id = $day['kintai_day_id'];

        $stmtTime->execute([':day_id' => $day_id]);
        $stmtDeleteDay->execute([':day_id' => $day_id]);
    }

    $pdo->commit();
    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
