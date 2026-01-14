<?php


declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/db.php';
// パスを修正しました
require_once __DIR__ . '/../lib/google_holiday.php';

require_login();
date_default_timezone_set('Asia/Tokyo');

/* ===============================
   JSON受信
================================ */
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!is_array($data)) {
  http_response_code(400);
  echo json_encode(['success' => false, 'message' => 'Invalid JSON']);
  exit;
}

$dates   = $data['dates']   ?? [];
$rows    = $data['rows']    ?? [];
$comment = $data['comment'] ?? '';

/* ===============================
   入力チェック（ここが重要）
================================ */

/* 日付未選択 */
if (empty($dates)) {
  http_response_code(400);
  echo json_encode([
    'success' => false,
    'message' => '日付が選択されていません'
  ]);
  exit;
}

/* 行がない */
if (empty($rows)) {
  http_response_code(400);
  echo json_encode([
    'success' => false,
    'message' => '勤務情報がありません'
  ]);
  exit;
}

/* 各行チェック */
foreach ($rows as $row) {

  $type  = $row['type']  ?? null;
  $start = $row['start'] ?? '';
  $end   = $row['end']   ?? '';

  /* 勤務形態未選択 */
  if ($type === null || $type === '') {
      http_response_code(400);
      echo json_encode(['success' => false, 'message' => '勤務形態を選択してください']);
      exit;
  }

  /* 時間未選択 */
  if ($start === '' || $end === '') {
      http_response_code(400);
      echo json_encode(['success' => false, 'message' => '時間を選択してください']);
      exit;
  }

  /* 時間の前後がおかしい */
  if (strtotime($start) >= strtotime($end)) {
    http_response_code(400);
    echo json_encode([
      'success' => false,
      'message' => '時間を正しく選択してください'
    ]);
    exit;
  }
}

/* ===============================
   ログインユーザー
================================ */
$email = $_SESSION['email'] ?? null;
if (!$email) {
  http_response_code(401);
  echo json_encode(['success' => false, 'message' => 'Not logged in']);
  exit;
}

/* ===============================
   DB保存
================================ */
$pdo = db();

try {
    $pdo->beginTransaction();

    foreach ($dates as $date) {

        // 1. この日付の kintai_day を取得（存在すれば id と status_id を取得）
      $stmt = $pdo->prepare(
          'SELECT kintai_day_id FROM kintai_day WHERE email = :email AND work_date = :work_date'
      );
      $stmt->execute([
          ':email'     => $email,
          ':work_date' => $date
      ]);
      $day = $stmt->fetch(PDO::FETCH_ASSOC);

      if ($day) {
          $kintaiDayId = (int)$day['kintai_day_id'];
          // 既存なら最新の status_id で更新
          // 今回は複数 row の最初の status_id を代表値として設定
          $stmtUpd = $pdo->prepare(
              'UPDATE kintai_day SET status_id = :status_id, updated_at = NOW() WHERE kintai_day_id = :id'
          );
          $stmtUpd->execute([
              ':status_id' => (int)$rows[0]['type'],
              ':id'        => $kintaiDayId
          ]);
      } else {
          // 新規作成
          $stmtIns = $pdo->prepare(
              'INSERT INTO kintai_day (email, work_date, status_id, created_at, updated_at)
              VALUES (:email, :work_date, :status_id, NOW(), NOW())'
          );
          $stmtIns->execute([
              ':email'     => $email,
              ':work_date' => $date,
              ':status_id' => (int)$rows[0]['type']
          ]);
          $kintaiDayId = (int)$pdo->lastInsertId();
      }


        /* ---------- 既存時間帯を削除（上書き保存） ---------- */
        $pdo->prepare(
            'DELETE FROM kintai_time WHERE kintai_day_id = ?'
        )->execute([$kintaiDayId]);

        /* ---------- kintai_time 登録 ---------- */
        $stmt = $pdo->prepare(
            'INSERT INTO kintai_time
             (kintai_day_id, status_id, start_time, end_time, comment)
             VALUES
             (:day_id, :status_id, :start_time, :end_time, :comment)'
        );

        foreach ($rows as $row) {
            $stmt->execute([
                ':day_id'     => $kintaiDayId,
                ':status_id'  => (int)$row['type'],
                ':start_time' => $row['start'],
                ':end_time'   => $row['end'],
                ':comment'    => $comment
            ]);
        }
    }

    $pdo->commit();
    echo json_encode(['success' => true]);

} catch (Throwable $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'DB error',
        'error'   => $e->getMessage()
    ]);
    exit;
}
