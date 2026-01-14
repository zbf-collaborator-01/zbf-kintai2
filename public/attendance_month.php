<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/auth.php';
require_login();
require_once __DIR__ . '/../lib/db.php';

$pdo = db();

$email = filter_input(INPUT_GET, 'email');
$y = filter_input(INPUT_GET, 'y', FILTER_VALIDATE_INT);
$m = filter_input(INPUT_GET, 'm', FILTER_VALIDATE_INT);

if (!$email || !$y || !$m) {
  http_response_code(400);
  echo json_encode([]);
  exit;
}

$start = sprintf('%04d-%02d-01', $y, $m);
$end   = date('Y-m-t', strtotime($start));

$sql = "
SELECT 
    kd.work_date, 
    kt.status_id, 
    kt.start_time, 
    kt.end_time
FROM kintai_time kt
JOIN kintai_day kd ON kt.kintai_day_id = kd.kintai_day_id
WHERE kd.email = :email
  AND kd.work_date BETWEEN :start AND :end
ORDER BY kd.work_date, kt.start_time
";



$stmt = $pdo->prepare($sql);
$stmt->execute([
  ':email' => $email,
  ':start' => $start,
  ':end'   => $end
]);

echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
