<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/db.php';
header('Content-Type: application/json; charset=UTF-8');

/* Editformに登録情報を反映 */
$email    = $_GET['email'] ?? null;
$workDate = $_GET['work_date'] ?? null;

if (!$email || !$workDate) {
    http_response_code(400);
    exit('invalid input');
}

$pdo = db();

/* day_id 取得 */
$stmt = $pdo->prepare(
    'SELECT kintai_day_id
     FROM kintai_day
     WHERE email = ? AND work_date = ?'
);
$stmt->execute([$email, $workDate]);
$day = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$day) {
    // 未登録日
    echo json_encode([]);
    exit;
}

/* time 情報取得 */
$stmt = $pdo->prepare(
    'SELECT
        status_id,
        start_time,
        end_time,
        comment
     FROM kintai_time
     WHERE kintai_day_id = ?
     ORDER BY start_time'
);
$stmt->execute([$day['kintai_day_id']]);

echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
