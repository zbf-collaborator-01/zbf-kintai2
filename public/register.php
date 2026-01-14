<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    exit('direct access denied');
}

$email      = $_POST['email'] ?? null;
$workDate   = $_POST['work_date'] ?? null;
$timeBlocks = $_POST['time_blocks'] ?? [];

if (!$email || !$workDate || empty($timeBlocks)) {
    http_response_code(400);
    exit('invalid input');
}

$pdo = db();
$pdo->beginTransaction();

try {
    // kintai_day 確保
    $stmt = $pdo->prepare(
        'INSERT INTO kintai_day (email, work_date)
         VALUES (:email, :work_date)
         ON DUPLICATE KEY UPDATE
           kintai_day_id = LAST_INSERT_ID(kintai_day_id)'
    );
    $stmt->execute([
        ':email' => $email,
        ':work_date' => $workDate
    ]);

    $kintaiDayId = (int)$pdo->lastInsertId();

    // 既存 time を削除（上書き保存）
    $pdo->prepare(
        'DELETE FROM kintai_time WHERE kintai_day_id = ?'
    )->execute([$kintaiDayId]);

    // kintai_time 登録
    $stmt = $pdo->prepare(
        'INSERT INTO kintai_time
         (kintai_day_id, status_id, start_time, end_time, comment)
         VALUES
         (:day_id, :status_id, :start, :end, :comment)'
    );

    foreach ($timeBlocks as $b) {
        $stmt->execute([
            ':day_id'   => $kintaiDayId,
            ':status_id'=> $b['status_id'],
            ':start'    => $b['start_time'],
            ':end'      => $b['end_time'],
            ':comment'  => $b['comment'] ?? null
        ]);
    }

    $pdo->commit();
    echo 'OK';

} catch (Throwable $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo 'NG';
}
