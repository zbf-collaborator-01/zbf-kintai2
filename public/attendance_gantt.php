<?php
// 型を厳密に扱う
declare(strict_types=1);

// 別ファイルを読み込み
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/db.php'; // DB接続用
// パスを修正しました
require_once __DIR__ . '/../lib/google_holiday.php';

// ログイン必須
require_login();
date_default_timezone_set('Asia/Tokyo');

/* ==== 基準日パラメータ ==== */
$baseDate = filter_input(INPUT_GET, 'base_date', FILTER_DEFAULT); 

if (is_string($baseDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $baseDate)) {
  $base = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', "$baseDate 00:00:00")
    ?: new DateTimeImmutable('today 00:00:00');
} else {
  $base = new DateTimeImmutable('today 00:00:00');
}

if ($base === false)
  $base = new DateTimeImmutable('today 00:00:00');

// 10日間の範囲を計算
$startDate = $base;
$endDate = $base->modify('+9 days');

// 前後10日間のリンク用日付
$prevBase = $base->modify('-10 days');
$nextBase = $base->modify('+10 days');

// 表示用の年月
$startYear = (int) $startDate->format('Y');
$startMonth = (int) $startDate->format('n');
$endYear = (int) $endDate->format('Y');
$endMonth = (int) $endDate->format('n');
$displayYear = ($startMonth !== $endMonth || $startYear !== $endYear) ? $endYear : $startYear;
$displayMonth = ($startMonth !== $endMonth || $startYear !== $endYear) ? $endMonth : $startMonth;

/* ==== 祝日データの取得 (ここを追加・修正) ==== */
$apiKey = 'AIzaSyAZ5EHXC-f9gJN34CeOUEl0GMqJMA4Isa4'; // あなたのAPIキー

$holidays = [];
if ($apiKey) {
    $repo = new GoogleHolidayRepository($apiKey);
    // 表示範囲の年と、その前後を含めて取得しておくと安心
    $holidays = $repo->getHolidays($startYear) 
              + $repo->getHolidays($startYear - 1) 
              + $repo->getHolidays($startYear + 1);
}

/* ==== DB接続 ==== */
$pdo = db();

/* ==== 1) 社員一覧 ==== */
$members = $pdo->query('SELECT name, email FROM members ORDER BY num')->fetchAll();
$nameByEmail = [];
$emails = [];
foreach ($members as $row) {
  $nameByEmail[$row['email']] = $row['name'];
  $emails[] = $row['email'];
}

/* ==== 2) ステータスマスタ ==== */
$rows = $pdo->query('SELECT status_id, status_name, short_name FROM mst_kintai_status ORDER BY status_id')->fetchAll();
$labels = [];
$labelsShort = [];
foreach ($rows as $r) {
  $sid = (int) $r['status_id'];
  $labels[$sid] = $r['status_name'];
  $labelsShort[$sid] = $r['short_name'] ?: $r['status_name'];
}

/* ==== 3) 10日間の勤怠データ取得（新テーブル版） ==== */
$sql = '
    SELECT
        d.email,
        d.work_date AS d,
        t.status_id,
        s.short_name,
        s.status_name,
        t.start_time,
        t.end_time,
        t.comment,
        t.updated_at
    FROM kintai_day d
    JOIN kintai_time t
        ON d.kintai_day_id = t.kintai_day_id
    JOIN mst_kintai_status s
        ON t.status_id = s.status_id
    WHERE d.work_date BETWEEN :start AND :end
    ORDER BY
        d.email,
        d.work_date,
        t.start_time
';

$st = $pdo->prepare($sql);
$st->execute([
    ':start' => $startDate->format('Y-m-d'),
    ':end'   => $endDate->format('Y-m-d'),
]);
$rows = $st->fetchAll();

$byEmailDays = [];

foreach ($rows as $r) {
    $email = $r['email'];

    if (!isset($nameByEmail[$email])) {
        continue;
    }

    $dateKey = $r['d'];

    $byEmailDays[$email][$dateKey][] = [
        'sid'        => is_numeric($r['status_id']) ? (int)$r['status_id'] : null,
        'short_name' => $r['short_name'] ?? '',
        'start'      => $r['start_time'],
        'end'        => $r['end_time'],
        'cmt'        => (string)($r['comment'] ?? ''),
    ];
}


/* ==== 10日分の日付配列生成 ==== */
$days = [];
for ($i = 0; $i < 10; $i++) {
  $days[] = $startDate->modify("+{$i} days");
}

/* ==== ビューヘルパー ==== */
function h(string $s): string
{
  return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}


function timeToX(float $min, float $BASE_START, float $BASE_END,
                float $LEFT_ZONE, float $BASE_ZONE): float {

                // 基準内（9–18）
                if ($min >= $BASE_START && $min <= $BASE_END) {
                    return $LEFT_ZONE +
                        (($min - $BASE_START) / ($BASE_END - $BASE_START)) * $BASE_ZONE;
                }

                // 早出（左はみ出し）
                if ($min < $BASE_START) {
                    $diff = $BASE_START - $min; // 分
                    return max(0, $LEFT_ZONE - ($diff / 60) * 0.05);
                }

                // 残業（右はみ出し）
                $diff = $min - $BASE_END;
                return min(1, $LEFT_ZONE + $BASE_ZONE + ($diff / 60) * 0.05);
            }


$today = new DateTimeImmutable('today');
$WD = ['日', '月', '火', '水', '木', '金', '土'];
?>
<!doctype html>
<html lang="ja">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>勤怠一覧</title>
  <style>
    :root {
      --label-width: 220px;
      --row-height: 44px;
      --timeline-header-height: 55px;
      --primary: #0f172a;
      --secondary: #475569;
      --border: #e2e8f0;
      --bg-main: #ffffff;
      --bg-alt: #f8fafc;
      --grid: #f1f5f9;
      --header-bg: #0f172a;
      --header-text: #ffffff;
      --shadow: 0 1px 3px 0 rgb(0 0 0 / 0.1);
      --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1);
    }

    * { box-sizing: border-box; margin: 0; padding: 0 }
    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, "Noto Sans", sans-serif; color: var(--primary); background: #f1f5f9; line-height: 1.5; font-size: 14px; overflow: hidden; }

    /* Header */
    .header { display: flex; align-items: center; justify-content: space-between; padding: 0 24px; height: 64px; background: var(--header-bg); box-shadow: var(--shadow-lg); position: sticky; top: 0; z-index: 100; }
    .header-left { display: flex; align-items: center; gap: 16px; }
    .month-title { font-size: 18px; font-weight: 700; color: var(--header-text); letter-spacing: -0.01em; }
    .nav-btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; border: 1px solid rgba(255, 255, 255, 0.2); border-radius: 8px; background: rgba(255, 255, 255, 0.1); color: var(--header-text); text-decoration: none; font-weight: 600; font-size: 13px; transition: all .2s cubic-bezier(.4, 0, .2, 1); backdrop-filter: blur(8px); cursor: pointer; }
    .nav-btn:hover { background: rgba(255, 255, 255, 0.15); border-color: rgba(255, 255, 255, 0.3); transform: translateY(-1px); }
    .home-btn { padding: 8px 20px; border: none; border-radius: 8px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: #fff; text-decoration: none; font-weight: 600; font-size: 13px; transition: all .2s cubic-bezier(.4, 0, .2, 1); box-shadow: 0 4px 6px -1px rgb(0 0 0 / .1); }
    .home-btn:hover { transform: translateY(-2px); box-shadow: 0 10px 15px -3px rgb(0 0 0 / .2); }

    /* Calendar Picker */
    .calendar-picker { position: relative; display: inline-block; }
    .calendar-dropdown { display: none; position: absolute; top: calc(100% + 8px); left: 50%; transform: translateX(-50%); background: #fff; border-radius: 12px; box-shadow: 0 20px 25px -5px rgb(0 0 0 / 0.15), 0 10px 10px -5px rgb(0 0 0 / 0.1); padding: 16px; z-index: 200; min-width: 280px; border: 1px solid var(--border); }
    .calendar-dropdown.active { display: block; animation: slideDown 0.2s ease; }
    @keyframes slideDown { from { opacity: 0; transform: translateX(-50%) translateY(-10px); } to { opacity: 1; transform: translateX(-50%) translateY(0); } }
    .calendar-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px; margin-top: 12px; }
    .calendar-weekday { text-align: center; font-size: 11px; font-weight: 700; color: var(--secondary); padding: 6px 0; text-transform: uppercase; }
    .calendar-day { aspect-ratio: 1; display: flex; align-items: center; justify-content: center; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer; transition: all .15s ease; color: var(--primary); background: var(--bg-alt); }
    .calendar-day:hover { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: #fff; transform: scale(1.05); }
    .calendar-day.other-month { color: var(--border); cursor: default; }
    .calendar-day.other-month:hover { background: var(--bg-alt); color: var(--border); transform: none; }
    .calendar-day.today { background: linear-gradient(135deg, #06b6d4 0%, #0ea5e9 100%); color: #fff; box-shadow: 0 0 0 2px rgba(6, 182, 212, 0.2); }
    .calendar-header { text-align: center; font-size: 15px; font-weight: 700; color: var(--primary); padding-bottom: 8px; border-bottom: 2px solid var(--border); }
    .cal-head { display: flex; align-items: center; gap: 8px; justify-content: space-between; margin-bottom: 8px }
    .cal-title { font-weight: 800; letter-spacing: .02em }
    .cal-nav { width: 36px; height: 32px; border: 1px solid #e5e7eb; background: #fff; border-radius: 8px; cursor: pointer }
    .cal-nav:hover { background: #f3f4f6 }

    /* Layout */
    .container { display: flex; margin: 0; overflow: hidden; background: var(--bg-main); height: calc(100vh - 64px); }
    .sidebar { width: var(--label-width); background: var(--bg-alt); border-right: 2px solid var(--border); flex-shrink: 0; display: flex; flex-direction: column; overflow: hidden; }
    .sidebar-header { height: var(--timeline-header-height); display: flex; align-items: center; padding: 0 16px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: #fff; font-weight: 700; font-size: 13px; letter-spacing: .05em; text-transform: uppercase; border-bottom: 2px solid var(--border); flex-shrink: 0; }
    .sidebar-rows { flex: 1; overflow-y: auto; overflow-x: hidden; scrollbar-width: none; -ms-overflow-style: none; }
    .sidebar-rows::-webkit-scrollbar { display: none; }
    .employee-row { height: var(--row-height); display: flex; align-items: center; padding: 0 16px; border-bottom: 1px solid var(--border); font-weight: 500; color: var(--secondary); transition: background .15s ease; }
    .employee-row:hover { background: rgba(100, 116, 139, .04); }

    .chart-area { flex: 1; overflow: hidden; background: var(--bg-main); display: flex; flex-direction: column; min-width: 0; }
    .chart-card { background: #fff; display: flex; flex-direction: column; flex: 1; min-height: 0; border: 1px solid #e5e7eb; border-radius: 12px; box-shadow: 0 10px 25px -5px rgb(0 0 0 / 0.15); overflow: hidden; }
    .timeline-header { background: var(--bg-alt); border-bottom: 2px solid var(--border); box-shadow: var(--shadow); flex-shrink: 0; height: var(--timeline-header-height); }
    .timeline-scale { position: relative; height: 100%; width: 100%; background: linear-gradient(to right, var(--grid) 1px, transparent 1px); background-size: 10% 100%; }

    .day-cell { position: absolute; top: 50%; transform: translate(-50%, -50%); text-align: center; user-select: none; width: 0; }
    .day-num { font-weight: 800; line-height: 1; font-variant-numeric: tabular-nums; transition: all .2s ease; }
    .day-wd { margin-top: 2px; font-size: 11px; line-height: 1; font-weight: 600; opacity: .9; transition: all .2s ease; }
    .day-cell.sun .day-num, .day-cell.sun .day-wd { color: #dc2626; }
    .day-cell.sat .day-num, .day-cell.sat .day-wd { color: #0ea5e9; }
    .day-num.today { position: relative; padding: 6px 10px; border-radius: 12px; background: linear-gradient(135deg, #06b6d4 0%, #0ea5e9 100%); color: #fff !important; box-shadow: 0 0 0 3px rgba(6, 182, 212, 0.15), 0 4px 12px -2px rgba(6, 182, 212, 0.3); animation: todayPulse 2s ease-in-out infinite; }
    .day-cell.sun .day-num.today, .day-cell.sat .day-num.today, .day-cell .day-num.today { color: #fff !important; }
    .day-num.today+.day-wd { color: #06b6d4; font-weight: 700; }
    @keyframes todayPulse { 0%, 100% { box-shadow: 0 0 0 3px rgba(6, 182, 212, 0.15), 0 4px 12px -2px rgba(6, 182, 212, 0.3); } 50% { box-shadow: 0 0 0 6px rgba(6, 182, 212, 0.25), 0 6px 16px -2px rgba(6, 182, 212, 0.4); } }

    /* 祝日赤字 */
    .day-cell.holiday .day-num, .day-cell.holiday .day-wd { color: #4cff38; }

    .chart-rows-container { flex: 1; overflow-y: auto; overflow-x: hidden; min-height: 0; }
    .chart-rows { position: relative; }
    .chart-row { position: relative; min-height: var(--row-height); height: auto; width: 100%; border-bottom: 1px solid var(--border); background: linear-gradient(to right, var(--grid) 1px, transparent 1px); background-size: 10% 100%; transition: background .15s ease; }
    .chart-row:hover { background-color: rgba(100, 116, 139, .02); background-image: linear-gradient(to right, var(--grid) 1px, transparent 1px); background-size: 10% 100%; }
    .status-comment-icon { position: absolute; top: 2px; right: 4px; font-size: 16px; line-height: 1; filter: drop-shadow(0 1px 2px rgba(0, 0, 0, 0.3)); pointer-events: none; }
    .b-1 { background: linear-gradient(135deg, #0891b2 0%, #06b6d4 100%); }
    .b-2 { background: linear-gradient(135deg, #dc2626 0%, #ef4444 100%); }
    .b-3 { background: linear-gradient(135deg, #059669 0%, #10b981 100%); }
    .b-4 { background: linear-gradient(135deg, #2563eb 0%, #3b82f6 100%); }
    .b-5 { background: linear-gradient(135deg, #9333ea 0%, #a855f7 100%); }
    .b-6 { background: linear-gradient(135deg, #52525b 0%, #71717a 100%); }

    /* 背景色 */
    .col-bg { position: absolute; top: 0; bottom: 0; width: 10%; z-index: 0; pointer-events: none; border-right: 1px solid rgba(0, 0, 0, 0.03); }
    .bg-sat { background-color: rgba(224, 242, 254, 0.9); }
    .bg-sun { background-color: rgba(254, 202, 202, 0.9); }
    .bg-today { z-index: 1; }
    .bg-holiday { background-color: rgb(127 237 108 / 60%); }

    .chart-rows-container::-webkit-scrollbar { width: 8px; height: 8px; }
    .chart-rows-container::-webkit-scrollbar-track { background: var(--bg-alt); }
    .chart-rows-container::-webkit-scrollbar-thumb { background: var(--secondary); border-radius: 4px; }
    .chart-rows-container::-webkit-scrollbar-thumb:hover { background: var(--primary); }
    @media (max-width: 768px) { .header { padding: 0 16px; height: 56px; } .month-title { font-size: 16px; } .nav-btn, .home-btn { font-size: 12px; padding: 6px 12px; } .sidebar { width: 180px; } :root { --label-width: 180px; } }
  
  

  


  .day-cell {
    position: absolute;
    top: 50%;
    transform: translate(-50%, -50%);
    text-align: center;
    user-select: none;
    width: 0;
  }

  .day-comment-icon {
    position: absolute;
    top: 2px;         /* 日付セルの上部に配置 */
    right: -2px;       /* 日付セルの右端に少しはみ出す */
    font-size: 14px;
    cursor: pointer;
    z-index: 5;
    pointer-events: auto; /* ホバーで title が表示されるように */
  }



  .status-bar {
    position: absolute;
    height: 24px;
    padding: 0 6px;
    display: flex;
    align-items: center;
    gap: 6px;

    font-size: 11px;
    font-weight: 600;
    color: #fff;

    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;

    border-radius: 6px;
    box-shadow: 0 2px 4px rgba(0,0,0,.1);
  }

  .status-bar .time {
    font-size: 10px;
    opacity: .85;
    flex-shrink: 0;
  }

  .status-bar .label {
    overflow: hidden;
    text-overflow: ellipsis;
  }
  


  </style>
</head>

<body>

  <header class="header">
    <div class="header-left">
      <a class="nav-btn" href="<?= h('?base_date=' . $prevBase->format('Y-m-d')) ?>">← 前の10日間</a>

      <div class="calendar-picker">
        <button class="nav-btn" id="calendarBtn">📅 カレンダー</button>
        <div class="calendar-dropdown" id="calendarDropdown" data-year="<?= (int) $displayYear ?>"
          data-month="<?= (int) $displayMonth ?>">
          <div class="cal-head">
            <button type="button" class="cal-nav" data-act="prevY" aria-label="前年">«</button>
            <button type="button" class="cal-nav" data-act="prevM" aria-label="前月">‹</button>
            <div class="cal-title" id="calTitle"></div>
            <button type="button" class="cal-nav" data-act="nextM" aria-label="次月">›</button>
            <button type="button" class="cal-nav" data-act="nextY" aria-label="翌年">»</button>
          </div>
          <div class="calendar-grid" id="calGrid"></div>
        </div>
      </div>
      <span class="month-title"><?= h("{$displayYear}.{$displayMonth}") ?></span>

      <a class="nav-btn" href="<?= h('?base_date=' . $nextBase->format('Y-m-d')) ?>">次の10日間 →</a>
    </div>
    <div>
      <a class="home-btn" href="home.php">カレンダーへ戻る</a>
    </div>
  </header>

  <div class="container">
    <aside class="sidebar">
      <div class="sidebar-header">社員</div>
      <div class="sidebar-rows" id="sidebarRows">
        <?php foreach ($members as $m): ?>
          <div class="employee-row"><?= h($m['name']) ?></div>
        <?php endforeach; ?>
      </div>
    </aside>

    <main class="chart-area">
      <div class="chart-card">
        <div class="timeline-header">
          <div class="timeline-scale">
            <?php foreach ($days as $i => $date):
              $dStr = $date->format('Y-m-d');
              $day = (int) $date->format('j');
              $dow = (int) $date->format('w');
              $isToday = $date->format('Y-m-d') === $today->format('Y-m-d');
              $isHoliday = isset($holidays[$dStr]);

              $cls = '';
              if ($isHoliday) {
                $cls = 'holiday';
              } elseif ($dow === 0) {
                $cls = 'sun';
              } elseif ($dow === 6) {
                $cls = 'sat';
              }
              
              $leftPct = (($i + 0.5) / 10) * 100;
              ?>
              <div class="day-cell <?= $cls ?>" style="left:<?= $leftPct ?>%;">
                <div class="day-num<?= $isToday ? ' today' : '' ?>"><?= $day ?></div>
                <div class="day-wd"><?= $WD[$dow] ?></div>
                
              </div>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="chart-rows-container" id="chartContainer">
          <div class="chart-rows">
            <?php
              /* ==== タイムライン基準設定（1回だけ） ==== */
              $BASE_START = 9 * 60;   // 09:00
              $BASE_END   = 18 * 60;  // 18:00

              $LEFT_ZONE  = 0.1;      // 左余白 10%
              $BASE_ZONE  = 0.8;      // 基準帯 80%

              $rel9  = $LEFT_ZONE + (($BASE_START - $BASE_START)/($BASE_END-$BASE_START))*$BASE_ZONE;  // = LEFT_ZONE
              $rel18 = $LEFT_ZONE + (($BASE_END   - $BASE_START)/($BASE_END-$BASE_START))*$BASE_ZONE;  // = LEFT_ZONE + BASE_ZONE
            ?>

            
            <?php foreach ($members as $m):
              $email = $m['email']; ?>
              <div class="chart-row">

                <?php foreach ($days as $i => $date):
                  $dStr = $date->format('Y-m-d'); // ここを定義
                  $dow = (int) $date->format('w');
                  $isToday = $date->format('Y-m-d') === $today->format('Y-m-d');
                  
                  // 祝日判定
                  $isHoliday = isset($holidays[$dStr]);

                  // 背景クラスを判定
                  $bgCls = '';
                  if ($isToday) {
                    $bgCls = 'bg-today';
                  } elseif ($isHoliday) { // 祝日優先
                    $bgCls = 'bg-holiday';
                  } elseif ($dow === 0) { // 日曜
                    $bgCls = 'bg-sun';
                  } elseif ($dow === 6) { // 土曜
                    $bgCls = 'bg-sat';
                  }

                  if ($bgCls):
                    $leftPct = $i * 10; 
                    ?>
                    <div class="col-bg <?= $bgCls ?>" style="left:<?= $leftPct ?>%;"></div>
                  <?php endif; endforeach; ?>

                <?php foreach ($days as $i => $date):
                    $dateKey = $date->format('Y-m-d');

                    // その日の全ブロック（配列）
                    $items = $byEmailDays[$email][$dateKey] ?? [];
                    if (!$items) {
                        continue;
                    }
                    // ★ レーン管理（この日専用）
                    $lanes = [];
                  

                    foreach ($items as $item) {
                        if (empty($item['start']) || empty($item['end'])) 
                          continue;

                          [$sh,$sm] = array_map('intval', explode(':', substr($item['start'],0,5)));
                          [$eh,$em] = array_map('intval', explode(':', substr($item['end'],0,5)));

                          $startMin = $sh*60 + $sm;
                          $endMin   = $eh*60 + $em;
                          if ($endMin <= $startMin) continue;

                          // レーン決定
                          $lane = 0;
                          while (isset($lanes[$lane]) && $startMin < $lanes[$lane]) {
                              $lane++;
                          }
                          $lanes[$lane] = $endMin;

                          // 位置計算
                          $dayLeftPct  = ($i / 10) * 100;
                          $dayWidthPct = 10;
                          $topPx = 10 + ($lane * 26);
                          $dayLeftPct  = ($i / 10) * 100;
                          $dayWidthPct = 10;
                          $rel9  = $LEFT_ZONE;          // 9時（相対0〜1）
                          $rel18 = $LEFT_ZONE + $BASE_ZONE; // 18時

                          $line9Pct  = $dayLeftPct + ($rel9  * $dayWidthPct);
                          $line18Pct = $dayLeftPct + ($rel18 * $dayWidthPct);
                          
                          
                          // 相対X（0〜1）
                          $relStart = timeToX($startMin, $BASE_START, $BASE_END, $LEFT_ZONE, $BASE_ZONE);
                          $relEnd   = timeToX($endMin,   $BASE_START, $BASE_END, $LEFT_ZONE, $BASE_ZONE);

                          // 実際の％位置
                          $leftPct  = $dayLeftPct + ($relStart * $dayWidthPct);
                          $widthPct = max(0.2, ($relEnd - $relStart) * $dayWidthPct); // 最低幅保証

                          $topPx = 10 + ($lane * 26);
                          $sid = $item['sid'];
                          $cmt = trim($item['cmt']);
                          $cls = 'b-' . $sid;
                          $text = $labelsShort[$sid] ?? ('#'.$sid);
                          $title = ($labels[$sid] ?? '') . ($cmt ? ' / '.$cmt : '');
                  ?>


                  <?php
                    $isNarrow = $widthPct < 6;

                    // 表示用ラベル
                    $shortLabel = mb_substr($text, 0, 1); // 出勤→出、休暇→休 など

                    // hover 用 tooltip
                    $tooltipParts = [];
                    $tooltipParts[] = $item['start'] . '–' . $item['end'];
                    $tooltipParts[] = $text;
                    if (!empty($cmt)) {
                      $tooltipParts[] = $cmt;
                    }
                    $tooltip = implode("\n", $tooltipParts);
                  ?>

            


                        <div class="status-bar <?= h($cls) ?>"
                            style="left:<?= $leftPct ?>%; width:<?= $widthPct ?>%; top:<?= $topPx ?>px;"
                            title="<?= h(
                                ($labels[$sid] ?? '') . ' ' .
                                substr($item['start'],0,5) . '–' . substr($item['end'],0,5) .
                                ($cmt ? ' / '.$cmt : '')
                            ) ?>">

                          <span class="time start"><?= h(substr($item['start'],0,5)) ?></span>
                          <span class="label"><?= h($text) ?></span>
                          <span class="time end"><?= h(substr($item['end'],0,5)) ?></span>

                        </div>

                <?php
                    }
                endforeach;
                ?>

                    

              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </main>
  </div>

  <script>
    (function () {
      const calendarBtn = document.getElementById('calendarBtn');
      const dropdown = document.getElementById('calendarDropdown');
      const grid = document.getElementById('calGrid');
      const title = document.getElementById('calTitle');
      const WD = ['日', '月', '火', '水', '木', '金', '土'];
      const today = new Date();
      function pad(n) { return String(n).padStart(2, '0'); }
      function fmt(y, m, d) { return `${y}-${pad(m)}-${pad(d)}`; }
      function daysInMonth(y, m) { return new Date(y, m, 0).getDate(); } 
      function makeCell(y, m, d, inMonth) {
        const dt = new Date(y, m - 1, d);
        const dow = dt.getDay();
        const cls = [
          'calendar-day',
          inMonth ? '' : 'other-month',
          dow === 0 ? 'sun' : '',
          dow === 6 ? 'sat' : '',
          (dt.getFullYear() === today.getFullYear() && dt.getMonth() === today.getMonth() && dt.getDate() === today.getDate()) ? 'today' : ''
        ].filter(Boolean).join(' ');
        return `<button type="button" class="${cls}" data-date="${fmt(y, m, d)}">${d}</button>`;
      }

      let viewY = parseInt(dropdown.dataset.year, 10);
      let viewM = parseInt(dropdown.dataset.month, 10); 

      function renderCalendar() {
        title.textContent = `${viewY}年${pad(viewM)}月`;
        let html = WD.map((w, i) => `<div class="calendar-weekday${i === 0 ? ' sun' : ''}${i === 6 ? ' sat' : ''}">${w}</div>`).join('');
        const first = new Date(viewY, viewM - 1, 1);
        const startDow = first.getDay(); 
        const dim = daysInMonth(viewY, viewM);
        const dimPrev = daysInMonth(viewY, viewM - 1 <= 0 ? 12 : viewM - 1);
        for (let i = 0; i < 42; i++) {
          const dayNum = i - startDow + 1;
          if (dayNum < 1) {
            const m = viewM - 1 <= 0 ? 12 : viewM - 1;
            const y = viewM - 1 <= 0 ? viewY - 1 : viewY;
            html += makeCell(y, m, dimPrev + dayNum, false);
          } else if (dayNum > dim) {
            const m = viewM + 1 > 12 ? 1 : viewM + 1;
            const y = viewM + 1 > 12 ? viewY + 1 : viewY;
            html += makeCell(y, m, dayNum - dim, false);
          } else {
            html += makeCell(viewY, viewM, dayNum, true);
          }
        }
        grid.innerHTML = html;
      }
      renderCalendar();

      calendarBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        dropdown.classList.toggle('active');
      });
      document.addEventListener('click', (e) => {
        if (!dropdown.contains(e.target) && e.target !== calendarBtn) {
          dropdown.classList.remove('active');
        }
      });
      dropdown.addEventListener('click', (e) => {
        const act = e.target?.dataset?.act;
        if (!act) return;
        if (act === 'prevM') { viewM--; if (viewM <= 0) { viewM = 12; viewY--; } }
        if (act === 'nextM') { viewM++; if (viewM > 12) { viewM = 1; viewY++; } }
        if (act === 'prevY') { viewY--; }
        if (act === 'nextY') { viewY++; }
        renderCalendar();
      });
      dropdown.addEventListener('click', (e) => {
        const cell = e.target.closest('.calendar-day');
        if (!cell) return;
        const date = cell.dataset.date;
        if (date) {
          location.href = '?base_date=' + date;
        }
      });
    })();

    const sidebarRows = document.getElementById('sidebarRows');
    const chartContainer = document.getElementById('chartContainer');
    let isSyncingLeft = false;
    let isSyncingRight = false;
    sidebarRows.addEventListener('scroll', function () {
      if (!isSyncingLeft) { isSyncingRight = true; chartContainer.scrollTop = this.scrollTop; }
      isSyncingLeft = false;
    });
    chartContainer.addEventListener('scroll', function () {
      if (!isSyncingRight) { isSyncingLeft = true; sidebarRows.scrollTop = this.scrollTop; }
      isSyncingRight = false;
    });
  </script>

  <script>
    const sidebarRows = document.getElementById('sidebarRows');
    const chartContainer = document.getElementById('chartContainer');
    let isSyncingLeft = false;
    let isSyncingRight = false;

    sidebarRows.addEventListener('scroll', function () {
      if (!isSyncingLeft) {
        isSyncingRight = true;
        chartContainer.scrollTop = this.scrollTop;
      }
      isSyncingLeft = false;
    });

    chartContainer.addEventListener('scroll', function () {
      if (!isSyncingRight) {
        isSyncingLeft = true;
        sidebarRows.scrollTop = this.scrollTop;
      }
      isSyncingRight = false;
    });
  </script>

</body>
</html>