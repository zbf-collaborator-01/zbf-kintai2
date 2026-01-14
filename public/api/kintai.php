<?php
declare(strict_types=1);
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/db.php';
require_login();

header('Content-Type: application/json; charset=UTF-8');

$method = $_SERVER['REQUEST_METHOD'];
// ログインユーザー
$currentUser = (string) ($_SESSION['email'] ?? '');
// 管理者権限有無
$isExecutive = !empty($_SESSION['is_executive']);

if ($currentUser === '') {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'unauthorized']);
    exit;
}

// 操作対象ユーザーの決定 (GETパラメータ target_user で指定可能)
$targetUserRaw = filter_input(INPUT_GET, 'target_user');
$targetEmail = $currentUser; // デフォルトは自分

if ($targetUserRaw && is_string($targetUserRaw) && $targetUserRaw !== $currentUser) {
    if ($isExecutive) {
        // 管理者なら対象を切り替え
        $targetEmail = $targetUserRaw;
    } else {
        // 一般ユーザーが他人を指定したら 403 Forbidden
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'forbidden: you are not executive']);
        exit;
    }
}

$pdo = db();

try {
    if ($method === 'GET') {
        $y = filter_input(INPUT_GET, 'y', FILTER_VALIDATE_INT);
        $m = filter_input(INPUT_GET, 'm', FILTER_VALIDATE_INT);
        $first = ($y && $m) ? DateTimeImmutable::createFromFormat('!Y-n-j', "$y-$m-1") : new DateTimeImmutable('first day of this month');
        if ($first === false)
            $first = new DateTimeImmutable('first day of this month');
        $start = $first->format('Y-m-01');
        $end = $first->format('Y-m-t');

        // $targetEmail を使用して検索
        $st = $pdo->prepare("
          SELECT planning_date, status_id, comment
            FROM kintai_info
           WHERE email = ? AND planning_date BETWEEN ? AND ?
           ORDER BY planning_date
        ");
        $st->execute([$targetEmail, $start, $end]);
        $rows = $st->fetchAll();
        $items = array_map(function ($r) {
            return [
                'date' => $r['planning_date'],
                'status_id' => isset($r['status_id']) ? (int) $r['status_id'] : null,
                'comment' => $r['comment'] ?? null,
            ];
        }, $rows);
        echo json_encode(['ok' => true, 'items' => $items]);
        exit;
    }

    if ($method === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true) ?? [];
        $date = (string) ($data['date'] ?? '');
        $sid = $data['status_id'] ?? null;
        $cmt = $data['comment'] ?? null;

        $dt = DateTimeImmutable::createFromFormat('Y-m-d', $date);
        $dateOk = $dt && $dt->format('Y-m-d') === $date;
        $sidOk = in_array($sid, [1, 2, 3, 4, 5, 6], true);
        if (!$dateOk || !$sidOk) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'bad request']);
            exit;
        }

        $cmt = is_string($cmt) ? trim($cmt) : null;
        if ($cmt === '') $cmt = null;
        if ($cmt !== null && mb_strlen($cmt) > 255) $cmt = mb_substr($cmt, 0, 255);

        // $targetEmail を使用して保存
        $sql = "INSERT INTO kintai_info (email, planning_date, status_id, comment, created_at, updated_at)
                VALUES (:email, :d, :sid, :cmt, NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    status_id = VALUES(status_id),
                    comment   = VALUES(comment),
                    updated_at= VALUES(updated_at)";
        $st = $pdo->prepare($sql);
        $st->execute([':email' => $targetEmail, ':d' => $date, ':sid' => $sid, ':cmt' => $cmt]);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($method === 'DELETE') {
        $date = (string) ($_GET['date'] ?? '');
        $dt = DateTimeImmutable::createFromFormat('Y-m-d', $date);
        if (!$dt || $dt->format('Y-m-d') !== $date) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'bad request']);
            exit;
        }
        // $targetEmail を使用して削除
        $st = $pdo->prepare("DELETE FROM kintai_info WHERE email = ? AND planning_date = ?");
        $st->execute([$targetEmail, $date]);
        echo json_encode(['ok' => true]);
        exit;
    }

    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'method not allowed']);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'server error']);
    exit;
}