<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/auth.php'; // パスは環境に合わせて調整
require_login();
date_default_timezone_set('Asia/Tokyo');

// 現在のログインユーザー情報
$currentUserEmail = $_SESSION['email'];
$isExecutive = !empty($_SESSION['is_executive']);

// 操作対象ユーザーの決定 (管理者のみ変更可能)
$targetUserEmail = $currentUserEmail;
if ($isExecutive) {
  $reqTarget = filter_input(INPUT_GET, 'target_user');
  //メールアドレスとして妥当かを検証
  if ($reqTarget && filter_var($reqTarget, FILTER_VALIDATE_EMAIL)) {
    //操作対象ユーザーを「切り替える」
    $targetUserEmail = $reqTarget;
  }
}

// メンバーリスト取得 (管理者のみ)
$members = [];
if ($isExecutive) {
  require_once __DIR__ . '/../lib/db.php';
  $pdo = db();
  $members = $pdo->query('SELECT name, email FROM members ORDER BY num')->fetchAll(PDO::FETCH_ASSOC);
}

// 年月処
$y = filter_input(INPUT_GET, 'y', FILTER_VALIDATE_INT);
$m = filter_input(INPUT_GET, 'm', FILTER_VALIDATE_INT);
$first = ($y && $m && $m >= 1 && $m <= 12) ? DateTimeImmutable::createFromFormat('!Y-n-j', "$y-$m-1") : new DateTimeImmutable('first day of this month');
if ($first === false)
  $first = new DateTimeImmutable('first day of this month');

$year = (int) $first->format('Y');
$month = (int) $first->format('n');
$daysInMonth = (int) $first->format('t');
$startW = (int) $first->format('w');
$today = new DateTimeImmutable('today');
$prev = $first->modify('-1 month');
$next = $first->modify('+1 month');

// 管理者が他人の勤怠を見ている状態を維持させる
function build_url($y, $m, $targetEmail, $currentUserEmail)
{
  $params = ['y' => $y, 'm' => $m];
  if ($targetEmail !== $currentUserEmail) {
    $params['target_user'] = $targetEmail;
  }
  return '?' . http_build_query($params);
}

// 前月・翌月の計算
$prevMonth = $first->modify('-1 month');
$daysInPrevMonth = (int) $prevMonth->format('t');
$prevYear = (int) $prevMonth->format('Y');
$prevMonthNum = (int) $prevMonth->format('n');
$nextYear = (int) $next->format('Y');
$nextMonthNum = (int) $next->format('n');

//年($year)が決まった後に、祝日データを取得

require_once __DIR__ . '/../lib/google_holiday.php';
// APIキー
$apiKey = 'AIzaSyAZ5EHXC-f9gJN34CeOUEl0GMqJMA4Isa4';

$holidays = [];
if ($apiKey) {
  $repo = new GoogleHolidayRepository($apiKey);
  // 今年・前年・翌年のデータを取得
  $holidays = $repo->getHolidays($year)
    + $repo->getHolidays($year - 1)
    + $repo->getHolidays($year + 1);
}
?>
<!doctype html>
<html lang="ja">


<head>
  <meta charset="UTF-8">
  <title>勤怠カレンダー</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    :root {
      --c-remote: #0891b2;
      --c-paid: #dc2626;
      --c-am: #059669;
      --c-pm: #2563eb;
      --c-sp: #9333ea;
      --c-etc: #52525b;
      --bg-primary: #f8fafc;
      --bg-secondary: #f1f5f9;
      --bg-card: #ffffff;
      --bg-hover: #e2e8f0;
      --accent-primary: #0ea5e9;
      --accent-secondary: #0284c7;
      --text-primary: #0f172a;
      --text-secondary: #334155;
      --text-muted: #64748b;
      --border-color: #e2e8f0;
      --border-bright: #cbd5e1;
    }

    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
    }

    body {
      font-family: 'Inter', system-ui, sans-serif;
      background: var(--bg-primary);
      color: var(--text-primary);
      min-height: 100vh;
      padding: 24px;
      line-height: 1.6;
    }

    .container {
      max-width: 1600px;
      margin: 0 auto;
    }

    header {
      background: rgba(255, 255, 255, 0.85);
      backdrop-filter: blur(16px);
      -webkit-backdrop-filter: blur(16px);
      border: 1px solid rgba(255, 255, 255, 0.6);
      border-bottom: 1px solid rgba(0, 0, 0, 0.05);
      border-radius: 20px;
      padding: 16px 32px;
      margin-bottom: 32px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 24px;
      flex-wrap: wrap;
      box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.02), 0 10px 15px -3px rgba(0, 0, 0, 0.04);
    }

    .user-info {
      display: flex;
      align-items: center;
      gap: 16px;
    }

    .nav {
      display: flex;
      align-items: center;
      gap: 12px;
      flex-wrap: wrap;
    }

    .btn-highlight {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      background: linear-gradient(135deg, var(--accent-primary) 0%, var(--accent-secondary) 100%);
      color: #fff !important;
      padding: 10px 24px;
      border-radius: 999px;
      font-size: 13px;
      font-weight: 700;
      letter-spacing: 0.05em;
      text-decoration: none;
      border: 1px solid rgba(255, 255, 255, 0.2);
      box-shadow: 0 4px 12px rgba(14, 165, 233, 0.3), 0 1px 0 rgba(255, 255, 255, 0.4) inset;
      transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }

    .btn-highlight:hover {
      transform: translateY(-2px);
      box-shadow: 0 8px 20px rgba(14, 165, 233, 0.4), 0 1px 0 rgba(255, 255, 255, 0.4) inset;
      filter: brightness(1.1);
    }

    .btn-icon {
      width: 16px;
      height: 16px;
      fill: currentColor;
    }

    .nav-link {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      padding: 8px 16px;
      border-radius: 12px;
      color: var(--text-secondary);
      font-size: 13px;
      font-weight: 600;
      text-decoration: none;
      background: transparent;
      border: 1px solid transparent;
      transition: all 0.2s ease;
    }

    .nav-link:hover {
      background: rgba(0, 0, 0, 0.03);
      color: var(--text-primary);
    }

    .nav-link.logout {
      color: var(--text-muted);
      font-weight: 500;
      margin-left: 8px;
    }

    .nav-link.logout:hover {
      background: rgba(220, 38, 38, 0.05);
      color: #dc2626;
    }

    .month-display {
      font-family: 'Inter', sans-serif;
      font-size: 20px;
      font-weight: 800;
      color: var(--text-primary);
      padding: 0 16px;
      letter-spacing: -0.02em;
      background: transparent;
      border: none;
    }

    .layout {
      display: flex;
      align-items: flex-start;
      gap: 24px;
      flex-wrap: wrap;
    }

    .main {
      flex: 1 1 700px;
      min-width: 320px;
    }

    .side {
      flex: 0 0 340px;
      background: var(--bg-card);
      border: 1px solid var(--border-color);
      border-radius: 4px;
      padding: 24px;
      position: sticky;
      top: 24px;
    }

    .editor-title {
      font-weight: 600;
      font-size: 12px;
      margin-bottom: 20px;
      color: var(--text-secondary);
      text-transform: uppercase;
      border-bottom: 1px solid var(--border-color);
      padding-bottom: 12px;
    }

    .cal {
      border-collapse: collapse;
      width: 100%;
    }

    .cal th,
    .cal td {
      background: var(--bg-card);
      border: 1px solid var(--border-color);
      width: 14.285%;
      min-height: 100px;
      vertical-align: top;
      padding: 12px;
      position: relative;
    }

    .cal th {
      background: var(--bg-secondary);
      color: var(--text-secondary);
      font-weight: 600;
      text-align: center;
      padding: 16px 12px;
      font-size: 11px;
    }

    .cal td {
      cursor: pointer;
      height: 100px;
    }

    .cal td:hover:not(.other-month) {
      background: var(--bg-hover);
    }

    .cal td.other-month {
      background: var(--bg-primary);
      opacity: 0.5;
      cursor: default;
    }

    .sun {
      color: #e03131 !important;
    }

    .sat {
      color: #1a98ffff !important;
    }

    .daynum {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 32px;
      height: 32px;
      font-weight: 600;
      font-size: 14px;
      margin-bottom: 6px;
    }

    .cal td.today .daynum {
      border-radius: 20px;
      background: var(--accent-primary);
      color: var(--bg-primary);
    }

    td.selected {
      background: rgba(0, 217, 255, 0.1) !important;
      box-shadow: inset 0 0 0 1px var(--accent-primary);
    }

    .badge {
      display: inline-block;
      margin-top: 4px;
      padding: 4px 10px;
      border-radius: 2px;
      font-size: 10px;
      font-weight: 600;
      color: var(--bg-primary);
    }

    .b-1 {
      background: var(--c-remote);
    }

    .b-2 {
      background: var(--c-paid);
    }

    .b-3 {
      background: var(--c-am);
    }

    .b-4 {
      background: var(--c-pm);
    }

    .b-5 {
      background: var(--c-sp);
    }

    .b-6 {
      background: var(--c-etc);
    }

    .b-7 {
      background: var(--c-sp);
    }


    .cmt {
      position: absolute;
      top: 8px;
      right: 8px;
      font-size: 14px;
      cursor: pointer;
    }

    #side-status {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 8px;
      margin-bottom: 20px;
    }

    #side-status label {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 10px;
      border: 1px solid var(--border-color);
      cursor: pointer;
      font-size: 12px;
    }

    #side-status label:has(input:checked) {
      border-color: var(--accent-primary);
      color: var(--accent-primary);
      background: rgba(0, 217, 255, 0.1);
    }

    #side-comment {
      width: 100%;
      min-height: 120px;
      padding: 12px;
      border: 1px solid var(--border-color);
      background: var(--bg-secondary);
    }

    .btn {
      padding: 10px 18px;
      border: 1px solid var(--border-color);
      background: var(--bg-secondary);
      cursor: pointer;
      font-size: 12px;
      font-weight: 600;
      text-transform: uppercase;
    }

    .btn.primary {
      background: var(--accent-primary);
      color: white;
      border-color: var(--accent-primary);
    }

    .btn:disabled {
      opacity: 0.3;
      cursor: not-allowed;
    }

    .btn-group {
      display: flex;
      gap: 8px;
      margin-top: 12px;
    }

    .btn-group .btn {
      flex: 1;
    }

    /* --- 完了モーダル --- */
    dialog {
      position: fixed;   /* 画面に固定 */
      inset: 0;          /* 上下左右端から0の位置に */
      margin: auto;      /* これで自動的に中央に来る */
      z-index: 1000;     /* 最前面に */
      border: none;
      border-radius: 16px;
      padding: 0;
      /* 内部のdivで余白を取るため0に */
      background: transparent;
      /* 背景は中身に任せる */
      max-width: 400px;
      width: 90%;
      box-shadow:
        0 20px 25px -5px rgba(0, 0, 0, 0.1),
        0 8px 10px -6px rgba(0, 0, 0, 0.1);
    }

    /* モーダルの背景（すりガラス効果） */
    dialog::backdrop {
      background: rgba(15, 23, 42, 0.4);
      backdrop-filter: blur(4px);
      -webkit-backdrop-filter: blur(4px);
      transition: all 0.3s ease;
    }

    /* モーダルの中身 */
    .dialog-content {
      background: #ffffff;
      padding: 32px;
      text-align: center;
      display: flex;
      flex-direction: column;
      align-items: center;
    }

    /* 成功アイコンのエリア */
    .success-icon-wrapper {
      width: 64px;
      height: 64px;
      background: #ecfdf5;
      /* 薄い緑 */
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      margin-bottom: 20px;
      color: #059669;
      /* 緑 */
    }

    .success-svg {
      width: 32px;
      height: 32px;
      stroke-width: 3;
      stroke: currentColor;
      fill: none;
      stroke-linecap: round;
      stroke-linejoin: round;
      /* チェックマークのアニメーション */
      stroke-dasharray: 60;
      stroke-dashoffset: 60;
      animation: drawCheck 0.6s 0.2s cubic-bezier(0.65, 0, 0.45, 1) forwards;
    }

    @keyframes drawCheck {
      to {
        stroke-dashoffset: 0;
      }
    }

    /* タイトルとメッセージ */
    .dialog-title {
      font-size: 1.25rem;
      font-weight: 800;
      color: var(--text-primary);
      margin-bottom: 8px;
    }

    #done-msg {
      font-size: 0.95rem;
      color: var(--text-secondary);
      margin-bottom: 24px;
      line-height: 1.5;
    }

    /* OKボタン（全幅で押しやすく） */
    .btn-full {
      width: 100%;
      padding: 12px;
      border-radius: 8px;
      font-size: 14px;
      font-weight: 700;
      color: white;
      background: linear-gradient(135deg, var(--accent-primary), var(--accent-secondary));
      border: none;
      cursor: pointer;
      transition: transform 0.2s, box-shadow 0.2s;
      outline: none;
    }

    .btn-full:hover {
      transform: translateY(-2px);
      box-shadow: 0 4px 12px rgba(14, 165, 233, 0.4);
    }

    .btn-full:active {
      transform: translateY(0);
    }

    /* 出現アニメーション */
    dialog[open] {
      animation: popIn 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275) forwards;
    }

    @keyframes popIn {
      from {
        opacity: 0;
        transform: scale(0.9) translateY(20px);
      }

      to {
        opacity: 1;
        transform: scale(1) translateY(0);
      }
    }

    .select-wrapper {
      position: relative;
      display: inline-flex;
      align-items: center;
      transition: transform 0.2s ease;
    }

    .select-wrapper::before {
      content: '';
      position: absolute;
      left: 14px;
      width: 14px;
      height: 14px;
      background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%2364748b' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2'/%3E%3Ccircle cx='12' cy='7' r='4'/%3E%3C/svg%3E");
      background-repeat: no-repeat;
      background-position: center;
      background-size: contain;
      pointer-events: none;
      z-index: 2;
      transition: all 0.2s ease;
    }

    .select-wrapper::after {
      content: '';
      position: absolute;
      right: 16px;
      top: 50%;
      width: 8px;
      height: 8px;
      border-right: 2px solid var(--text-muted);
      border-bottom: 2px solid var(--text-muted);
      transform: translateY(-60%) rotate(45deg);
      pointer-events: none;
      transition: all 0.2s ease;
    }

    .user-select {
      appearance: none;
      -webkit-appearance: none;
      height: 40px;
      padding: 0 40px 0 38px;
      font-size: 13px;
      font-weight: 600;
      font-family: inherit;
      color: var(--text-primary);
      background-color: #fff;
      border: 1px solid var(--border-color);
      border-radius: 999px;
      cursor: pointer;
      outline: none;
      transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
      box-shadow: 0 2px 6px -1px rgba(0, 0, 0, 0.05);
    }

    .select-wrapper:hover .user-select {
      border-color: var(--accent-primary);
      color: var(--accent-primary);
      box-shadow: 0 4px 12px -2px rgba(14, 165, 233, 0.15);
      background-color: #f0f9ff;
    }

    .select-wrapper:hover::before {
      background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%230ea5e9' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2'/%3E%3Ccircle cx='12' cy='7' r='4'/%3E%3C/svg%3E");
    }

    .select-wrapper:hover::after {
      border-color: var(--accent-primary);
      transform: translateY(-40%) rotate(45deg);
    }

    .user-select:focus {
      border-color: var(--accent-primary);
      box-shadow: 0 0 0 3px rgba(14, 165, 233, 0.2);
    }

    .current-user-name {
      display: inline-flex;
      align-items: center;
      height: 40px;
      padding: 0 20px;
      font-weight: 600;
      font-size: 14px;
      color: var(--text-primary);
      border-radius: 999px;
      border: 1px solid transparent;
    }

    /* 祝日用 */
    .cal td.holiday .daynum {
      color: #19ff00;
    }

    .holiday-name {
      font-size: 15px;
      color: #19ff00;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      margin-top: -4px;
      margin-bottom: 2px;
      font-weight: bold;
    }

    .cal td.holiday {}

    /* --- 確認ダイアログ用の追加スタイル --- */

    /* 警告アイコン（赤背景） */
    .warning-icon-wrapper {
      width: 64px;
      height: 64px;
      background: #fef2f2;
      /* 薄い赤 */
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      margin-bottom: 20px;
      color: #dc2626;
      /* 濃い赤 */
    }

    /* ボタンを横並びにするラッパー */
    .dialog-actions {
      display: flex;
      gap: 12px;
      width: 100%;
      margin-top: 8px;
    }

    /* キャンセルボタン（控えめなグレー） */
    .btn-cancel {
      flex: 1;
      padding: 12px;
      border-radius: 8px;
      font-size: 14px;
      font-weight: 700;
      color: var(--text-secondary);
      background: #f1f5f9;
      border: 1px solid transparent;
      cursor: pointer;
      transition: background 0.2s;
    }

    .btn-cancel:hover {
      background: #e2e8f0;
    }

    /* 削除実行ボタン（赤色） */
    .btn-danger {
      flex: 1;
      padding: 12px;
      border-radius: 8px;
      font-size: 14px;
      font-weight: 700;
      color: white;
      background: linear-gradient(135deg, #ef4444, #dc2626);
      border: none;
      cursor: pointer;
      transition: transform 0.2s, box-shadow 0.2s;
    }

    .btn-danger:hover {
      transform: translateY(-2px);
      box-shadow: 0 4px 12px rgba(220, 38, 38, 0.4);
    }

    /* ===== 複数選択モード ===== */
    .multi-toggle {
      padding: 8px 14px;
      border-radius: 999px;
      font-size: 12px;
      font-weight: 700;
      cursor: pointer;
      border: 1px solid var(--border-color);
      background: var(--bg-secondary);
    }

    .multi-toggle.active {
      background: var(--accent-primary);
      color: white;
      border-color: var(--accent-primary);
    }

    .day-check {
      position: absolute;
      bottom: 8px;
      right: 8px;
      width: 18px;
      height: 18px;
      border: 2px solid var(--border-bright);
      border-radius: 4px;
      display: none;
      align-items: center;
      justify-content: center;
      font-size: 12px;
      background: white;
    }

    .multi-mode .day-check {
      display: flex;
    }

    .day-check.checked {
      background: var(--accent-primary);
      color: white;
      border-color: var(--accent-primary);
    }

    /* ===== 勤務区間入力 ===== */

    .editor-title-row {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 12px;
    }

    .work-row {
      display: flex;
      align-items: center;
      gap: 6px;
      margin-bottom: 8px;
    }

    .time-select {
      padding: 4px 6px;
      border-radius: 4px;
      border: 1px solid var(--border-color);
      font-size: 12px;
    }

    .time-sep {
      font-size: 12px;
    }


    .add-btn,
    .remove-btn {
      padding: 4px 8px;
      border-radius: 50%;
      border: 1px solid var(--border-color);
      background: var(--bg-secondary);
      cursor: pointer;
      font-size: 14px;
    }

    .type-select {
      position: relative;
      width: 100%;
      min-width: 140px;
    }

    .type-btn {
      width: 100%;
      padding: 10px;
      border-radius: 8px;
      border: 1px solid var(--border-color);
      background: #fff;
      font-weight: 600;
      font-size: 12px;
      cursor: pointer;
      text-align: left;
      color: var(--text-primary);
    }

    .type-options {
      display: none;
      position: absolute;
      top: 110%;
      left: 0;
      width: 100%;
      background: #fff;
      border: 1px solid #ccc;
      border-radius: 8px;
      box-shadow: 0 6px 16px rgba(0,0,0,0.15);
      z-index: 100;
    }

    .type-options div {
      padding: 10px;
      cursor: pointer;
    }

    .type-options div:hover {
      background: #f1f5f9;
    }

    .type-select.open .type-options {
      display: block;
    }

    /* ===== 勤務形態カラー（パステル） ===== */

    .type-btn {
      background: #ffffff;
      color: #000;
      border: 1px solid #ccc;
    }

    /* 在宅：青 */
    .type-btn.type-remote {
      background: rgba(8, 145, 178, 0.15);
    }

    /* 出勤：緑 */
    .type-btn.type-office {
      background: rgba(5, 150, 105, 0.15);
    }

    /* 有給：赤 */
    .type-btn.type-paid {
      background: rgba(220, 38, 38, 0.15);
    }

    /* 休暇：紫 */
    .type-btn.type-leave {
      background: rgba(147, 51, 234, 0.15);
    }

    /* 時間有給：オレンジ */
    .type-btn.type-hourly {
      background: rgba(234, 88, 12, 0.15);
    }

    /* 移動：グレー */
    .type-btn.type-move {
      background: rgba(82, 82, 91, 0.15);
    }

    /* 特別有給：濃い紫 */
    .type-btn.type-special-paid {
      background: rgba(126, 34, 206, 0.18);
    }



    


  </style>
</head>

<body>
  <div class="container">
    <header>
      <div class="user-info">
        <?php if ($isExecutive): ?>
          <div class="select-wrapper">
            <select class="user-select"
              onchange="location.href='?y=<?= $year ?>&m=<?= $month ?>&target_user=' + this.value">
              <?php foreach ($members as $mem): ?>
                <option value="<?= htmlspecialchars($mem['email'], ENT_QUOTES) ?>" <?= $mem['email'] === $targetUserEmail ? 'selected' : '' ?>>
                  <?= htmlspecialchars($mem['name'], ENT_QUOTES) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        <?php else: ?>
          <span class="current-user-name">
            <?= htmlspecialchars($_SESSION['name'] ?? $targetUserEmail, ENT_QUOTES) ?>
          </span>
        <?php endif; ?>
      </div>

      <nav class="nav">
        <a href="attendance_gantt.php" class="btn-highlight">
          <svg class="btn-icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
            <path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01" stroke="currentColor" stroke-width="3"
              stroke-linecap="round" stroke-linejoin="round" />
          </svg>
          一覧表示
        </a>
        <span style="width:12px"></span>
        <a href="<?= build_url($prev->format('Y'), $prev->format('n'), $targetUserEmail, $currentUserEmail) ?>"
          class="nav-link" aria-label="前月">⇐前月</a>
        <span class="month-display"><?= $year ?><span
            style="color:var(--text-muted);font-weight:300">.</span><?= sprintf('%02d', $month) ?></span>
        <a href="<?= build_url($next->format('Y'), $next->format('n'), $targetUserEmail, $currentUserEmail) ?>"
          class="nav-link" aria-label="次月">次月⇒</a>
        <a href="<?= build_url((int) $today->format('Y'), (int) $today->format('n'), $targetUserEmail, $currentUserEmail) ?>"
          class="nav-link">今月</a>
        <a href="/zbf-kintai/public/logout.php" class="nav-link logout">ログアウト</a>
      </nav>
    </header>

    <div class="layout">
      <!-- ===== カレンダー ===== -->
      <div class="main">
        <table class="cal" id="cal" aria-label="勤怠カレンダー">
          <thead>
            <tr>
              <th class="sun">SUN</th>
              <th>MON</th>
              <th>TUE</th>
              <th>WED</th>
              <th>THU</th>
              <th>FRI</th>
              <th class="sat">SAT</th>
            </tr>
          </thead>
          <tbody>
            <?php
            $day = 1;
            $nextDay = 1;
            for ($week = 0; $week < 6; $week++) {
              echo "<tr>";
              for ($w = 0; $w < 7; $w++) {
                // 前月分
                if ($week === 0 && $w < $startW) {
                  $prevDay = $daysInPrevMonth - ($startW - $w - 1);
                  $cls = ['other-month'];
                  if ($w === 0)
                    $cls[] = 'sun';
                  if ($w === 6)
                    $cls[] = 'sat';
                  echo "<td class='" . implode(' ', $cls) . "' data-date='" . sprintf('%04d-%02d-%02d', $prevYear, $prevMonthNum, $prevDay) . "'><div class='daynum'>{$prevDay}</div><div class='slot'></div></td>";
                  continue;
                }
                // 翌月分
                if ($day > $daysInMonth) {
                  $cls = ['other-month'];
                  if ($w === 0)
                    $cls[] = 'sun';
                  if ($w === 6)
                    $cls[] = 'sat';
                  echo "<td class='" . implode(' ', $cls) . "' data-date='" . sprintf('%04d-%02d-%02d', $nextYear, $nextMonthNum, $nextDay) . "'><div class='daynum'>{$nextDay}</div><div class='slot'></div></td>";
                  $nextDay++;
                  continue;
                }
                // 当月分
                $isToday = ((int) $today->format('Y') === $year && (int) $today->format('n') === $month && (int) $today->format('j') === $day);
                $cls = [];
                if ($w === 0)
                  $cls[] = 'sun';
                if ($w === 6)
                  $cls[] = 'sat';
                if ($isToday)
                  $cls[] = 'today';

                // 祝日判定ロジック (ループ内で実行) 
                $dateStr = sprintf('%04d-%02d-%02d', $year, $month, $day);
                $isHoliday = isset($holidays[$dateStr]);
                $holidayName = $isHoliday ? $holidays[$dateStr] : '';
                if ($isHoliday) {
                  $cls[] = 'holiday';
                }
            
                $classAttr = $cls ? ' class="' . implode(' ', $cls) . '"' : '';

                // セル描画
                echo "<td{$classAttr} data-date='{$dateStr}'>
                        <div class='daynum'>{$day}</div>
                        " . ($isHoliday ? "<div class='holiday-name'>" . htmlspecialchars($holidayName, ENT_QUOTES) . "</div>" : "") . "
                        <div class='slot'></div>
                        <div class='day-check'></div>
                      </td>";
                $day++;
              }
              echo "</tr>";
              if ($day > $daysInMonth && $nextDay > 7)
                break;
            }
            ?>
          </tbody>
        </table>
      </div>


      <!-- Editorform -->
      <aside class="side" aria-label="操作パネル">
        <div class="editor">
          <div class="editor-title-row">
            <div class="editor-title">Edit Form</div>
            <button type="button" id="btn-multi" class="multi-toggle">選択</button>
          </div>

          <!-- 勤務区間行 -->
          <div id="work-rows">
            <div class="work-row">
              <select class="time-select start-time"></select>
              <span class="time-sep">～</span>
              <select class="time-select end-time"></select>

              <div class="type-select">
                <button type="button" class="type-btn">勤務形態 ▼</button>

                <div class="type-options">
                  <div data-type="在宅" data-status-id="1">在宅</div>
                  <div data-type="出勤" data-status-id="2">出勤</div>
                  <div data-type="有給" data-status-id="3">有給</div>
                  <div data-type="休暇" data-status-id="4">休暇</div>
                  <div data-type="時間有給" data-status-id="5">時間有給</div>
                  <div data-type="移動" data-status-id="6">移動</div>
                  <div data-type="特別有給" data-status-id="7">特別有給</div>
                </div>
              </div>

              <!--1行目はなし-->
              <button type="button" class="add-btn">＋</button>
            </div>
          </div>

          <label class="label-text">Comment</label>
          <textarea id="side-comment" maxlength="255" placeholder="例: 午前中は通院"></textarea>

          <div class="btn-group">
            <button class="btn primary" id="btn-save-side" disabled>登録</button>
          </div>
        </div>

        <button class="btn" id="btn-clear-side" style="width:100%;margin-top:16px">削除</button>
        <div class="info-text">セルをクリック → 編集フォームで入力 → 登録</div>
      </aside>


    </div>
    <dialog id="done-dialog">
      <div class="dialog-content">
        <div class="success-icon-wrapper">
          <svg class="success-svg" viewBox="0 0 24 24">
            <polyline points="4 12 9 17 20 6"></polyline>
          </svg>
        </div>

        <div class="dialog-title">Success!</div>
        <div id="done-msg">処理が完了しました。</div>

        <button type="button" class="btn-full" id="done-ok">OK</button>
      </div>
    </dialog>

    <dialog id="confirm-dialog">
      <div class="dialog-content">
        <div class="warning-icon-wrapper">
          <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
            stroke-linecap="round" stroke-linejoin="round">
            <polyline points="3 6 5 6 21 6"></polyline>
            <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
            <line x1="10" y1="11" x2="10" y2="17"></line>
            <line x1="14" y1="11" x2="14" y2="17"></line>
          </svg>
        </div>

        <div class="dialog-title">確認</div>
        <div id="confirm-msg" style="margin-bottom:24px; color:var(--text-secondary);">削除してもよろしいですか？</div>

        <div class="dialog-actions">
          <button type="button" class="btn-cancel" id="confirm-cancel">キャンセル</button>
          <button type="button" class="btn-danger" id="confirm-yes">削除する</button>
        </div>
      </div>
    </dialog>
  </div>

  <script>
  (function () {
    const cal = document.getElementById('cal');
    const btnMulti = document.getElementById('btn-multi');
    const btnSaveSide = document.getElementById('btn-save-side');
    const sideComment = document.getElementById('side-comment');
    const btnClearSide = document.getElementById('btn-clear-side');

    let multiMode = false;
    const selected = new Set();

    function updateSaveEnabled() {
      btnSaveSide.disabled = selected.size === 0;
    }

    btnMulti.addEventListener('click', () => {
      multiMode = !multiMode;
      cal.classList.toggle('multi-mode', multiMode);
      btnMulti.classList.toggle('active', multiMode);

      selected.clear();
      cal.querySelectorAll('.selected').forEach(td => td.classList.remove('selected'));
      cal.querySelectorAll('.day-check.checked').forEach(c => c.classList.remove('checked'));
      updateSaveEnabled();
    });

    cal.addEventListener('click', e => {
      const td = e.target.closest('td[data-date]');
      if (!td || td.classList.contains('other-month')) return;

      const date = td.dataset.date;
      const check = td.querySelector('.day-check');

      if (!multiMode) {
        if (selected.has(date)) {
          // 通常モード：再クリックで解除
          selected.clear();
          td.classList.remove('selected');
        } else {
          cal.querySelectorAll('.selected').forEach(t => t.classList.remove('selected'));
          selected.clear();
          selected.add(date);
          td.classList.add('selected');
          loadAttendance(date);
        }
      } else {
        if (selected.has(date)) {
          selected.delete(date);
          td.classList.remove('selected');
          check.classList.remove('checked');
        } else {
          selected.add(date);
          td.classList.add('selected');
          check.classList.add('checked');
        }
      }

      updateSaveEnabled();
    });

  })();
  </script>

  <script>
    function renderCalendarBadges(list) {
    // いったん全slotをクリア
    document.querySelectorAll('.cal td .slot').forEach(s => s.innerHTML = '');

    // 日付ごとにまとめる
    const grouped = {};
    list.forEach(row => {
      if (!grouped[row.work_date]) grouped[row.work_date] = [];
      grouped[row.work_date].push(row);
    });

    const statusMap = {1:'在宅',2:'出勤',3:'有給',4:'休暇',5:'時間有給',6:'移動',7:'特別有給'};

    Object.entries(grouped).forEach(([date, rows]) => {
      const td = document.querySelector(`.cal td[data-date="${date}"]`);
      if (!td) return;

      const slot = td.querySelector('.slot');
      rows.forEach(row => {
        const badge = document.createElement('div');
        badge.className = 'badge b-' + row.status_id;
        badge.textContent = statusMap[row.status_id] ?? '';
        // 小さくして横に並べる
        badge.style.display = 'inline-block';
        badge.style.margin = '1px';
        badge.style.padding = '2px 4px';
        badge.style.fontSize = '0.7em';
        slot.appendChild(badge);
      });
    });
  }

  </script>



  <script>
    function fillEditForm(list) {
      
    if (!Array.isArray(list) || list.length === 0) {
      resetForm();
      return;
    }
    const container = document.getElementById('work-rows');

    // ★ 修正①：最初の .work-row は残す
    const baseRow = document.querySelector('.work-row');
    container.innerHTML = '';
    container.appendChild(baseRow);


    list.forEach((b, index) => {

        // 1行目は既存行を使う、それ以外は追加
        let row;
        if (index === 0) {
          row = baseRow;
        } else {
          row = addWorkRow();
        }

        // ★ 修正②：null 安全
        row.querySelector('.start-time').value = b.start_time ? b.start_time.slice(0, 5) : '';
        row.querySelector('.end-time').value = b.end_time ? b.end_time.slice(0, 5) : '';

        const typeBtn = row.querySelector('.type-btn');
        typeBtn.dataset.statusId = b.status_id;

        const statusMap = {
          1: '在宅',
          2: '出勤',
          3: '有給',
          4: '休暇',
          5: '時間有給',
          6: '移動',
          7: '特別有給'
        };
        typeBtn.textContent = statusMap[b.status_id] ?? '勤務形態';
        // ★ 修正③：既存クラスを一度クリア
        typeBtn.className = 'type-btn';
        typeBtn.classList.add('type-' + b.status_id);
      });

      // コメントは日単位なので先頭から取得
      document.getElementById('side-comment').value =
        list[0].comment ?? '';
    }
    </script>



  <script>
    function loadAttendance(date) {
      fetch('attendance_get.php?email=<?= urlencode($targetUserEmail) ?>&work_date=' + date)
        .then(res => res.json())
        .then(list => {
          fillEditForm(list);
        });
    }
  </script>

  <script>
  function loadMonthAttendance() {
     fetch(
        'attendance_month.php'
        + '?email=<?= urlencode($targetUserEmail) ?>'
        + '&y=<?= $year ?>'
        + '&m=<?= $month ?>'
      )
        .then(res => {
          console.log('HTTP status:', res.status);
          return res.json();
        })
        .then(list => {
          console.log(list);
          renderCalendarBadges(list);
        })
        .catch(err => {
          console.error('Fetch failed:', err);
        });
    }

  // 初期表示時に実行
  loadMonthAttendance();
  </script>


  <script>

    function addWorkRow() {
      const base = document.querySelector('.work-row');
      const newRow = base.cloneNode(true);

      // 初期化
      newRow.querySelector('.start-time').selectedIndex = 0;
      newRow.querySelector('.end-time').selectedIndex = 0;

      const typeBtn = newRow.querySelector('.type-btn');
      typeBtn.textContent = '勤務形態 ▼';
      delete typeBtn.dataset.statusId;

      workRows.appendChild(newRow);
      setupRow(newRow);

      return newRow;
    }
  </script>




  <script>
  /* ===== 勤務区間入力 ===== */

  const workRows = document.getElementById('work-rows');
  const saveBtn = document.getElementById('btn-save-side');

  function buildTimeOptions(select) {
    select.innerHTML = '';
    for (let h = 0; h < 24; h++) {
      for (let m = 0; m < 60; m += 15) {
        const v = String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0');
        const opt = document.createElement('option');
        opt.value = v;
        opt.textContent = v;
        select.appendChild(opt);
      }
    }
  }

  function setupRow(row) {
    const start = row.querySelector('.start-time');
    const end = row.querySelector('.end-time');

    buildTimeOptions(start);
    buildTimeOptions(end);

    start.addEventListener('change', () => saveBtn.disabled = false);
    end.addEventListener('change', () => saveBtn.disabled = false);
  }

  setupRow(workRows.querySelector('.work-row'));
  </script>

  <script>
  /* ===== 勤務形態 dropdown ===== */
  document.addEventListener('click', (e) => {

    /* ボタン押下 */
    const btn = e.target.closest('.type-btn');
    if (btn) {
      btn.closest('.type-select').classList.toggle('open');
      return;
    }

    /* 選択肢クリック */
    const opt = e.target.closest('.type-options div');
    if (opt) {
      const wrap = opt.closest('.type-select');
      const btn = wrap.querySelector('.type-btn');
      const statusId = opt.dataset.statusId;
      const typeText = opt.textContent;

      /* 表示文字（▼なし） */
      btn.dataset.statusId = statusId;
      btn.textContent = typeText;

      /* 既存クラス削除 */
      btn.classList.remove(
        'type-remote',
        'type-office',
        'type-paid',
        'type-leave',
        'type-hourly',
        'type-move'
      );

      /* 勤務形態ごとに色付与 */
      switch (typeText) {
        case '在宅':
          btn.classList.add('type-remote');
          break;
        case '出勤':
          btn.classList.add('type-office');
          break;
        case '有給':
          btn.classList.add('type-paid');
          break;
        case '休暇':
          btn.classList.add('type-leave');
          break;
        case '時間有給':
          btn.classList.add('type-hourly');
          break;
        case '移動':
          btn.classList.add('type-move');
          break;
        case '特別有給':   // ← 追加
          btn.classList.add('type-special-paid');
          break;
      }

      wrap.classList.remove('open');
      saveBtn.disabled = false;
      return;
    }

    /* 外クリックで閉じる */
    document.querySelectorAll('.type-select.open')
      .forEach(el => el.classList.remove('open'));
  });
  </script>

    <script>
    /* ===== 行追加・削除 ===== */

    workRows.addEventListener('click', (e) => {
    const addBtn = e.target.closest('.add-btn');
    if (addBtn) {
      const row = addBtn.closest('.work-row');
      const newRow = row.cloneNode(true);

      // 時間リセット
      newRow.querySelector('.start-time').selectedIndex = 0;
      newRow.querySelector('.end-time').selectedIndex = 0;

      // 勤務形態リセット
      const typeBtn = newRow.querySelector('.type-btn');
      typeBtn.textContent = '勤務形態 ▼';
      delete typeBtn.dataset.statusId;

      // 色クラスをすべて削除して透明に
      typeBtn.classList.remove(
        'type-remote',
        'type-office',
        'type-paid',
        'type-leave',
        'type-hourly',
        'type-move',
        'type-special-paid'
      );

      // －ボタンがなければ追加
      if (!newRow.querySelector('.remove-btn')) {
        const removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'remove-btn';
        removeBtn.textContent = '－';

        newRow.querySelector('.add-btn').before(removeBtn);
      }

      workRows.appendChild(newRow);

      // 時間セレクト再セットとクリックイベント初期化
      setupRow(newRow);
      saveBtn.disabled = false;
      return;
    }

    // －ボタンで行削除
    const removeBtn = e.target.closest('.remove-btn');
    if (removeBtn) {
      const row = removeBtn.closest('.work-row');
      row.remove();
      saveBtn.disabled = false;
      return;
    }
  });

  
    </script>

    <script>
    /* ===== 登録ボタン ===== */
    document.getElementById('btn-save-side').addEventListener('click', () => {
      if (document.getElementById('btn-save-side').disabled) return;

      const rows = [];
      document.querySelectorAll('.work-row').forEach(row => {
        const start = row.querySelector('.start-time').value;
        const end   = row.querySelector('.end-time').value;
        const statusId = row.querySelector('.type-btn').dataset.statusId;

        if (start && end && statusId) {
          rows.push({
            start,
            end,
            type: Number(statusId)
          });
        }
      });

      const dates = Array.from(
        document.querySelectorAll('.cal td.selected')
      ).map(td => td.dataset.date);

      const comment = document.getElementById('side-comment').value;

      fetch('attendance_save.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          dates,
          rows,
          comment
        })
      })
      .then(res => {
        return res.json().then(data => {
          if (!res.ok) {
            // 400 / 401 / 500 でも PHP の message を使う
            throw data;
          }
          return data;
        });
      })
      .then(data => {
        if (data.success) {
          loadMonthAttendance(); // ★追加
          document.getElementById('done-msg').textContent = '登録しました';
          document.getElementById('done-dialog').showModal();
          document.getElementById('btn-save-side').disabled = true;
        } else {
          alert(data.message ?? '保存に失敗しました');
        }
      })
      .catch(err => {
        alert(err.message ?? '通信エラー');
      });
    })
    </script>

    <script>
    /* ===== 削除ボタン ===== */
    document.getElementById('btn-clear-side').addEventListener('click', () => {
      const dates = Array.from(
        document.querySelectorAll('.cal td.selected')
      ).map(td => td.dataset.date);

      if (dates.length === 0) return;

      fetch('attendance_delete.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ email: '<?= $targetUserEmail ?>', dates })
      })
      .then(res => res.json())
      .then(data => {
        if (data.success) {
          loadMonthAttendance(); // ★追加
          document.getElementById('done-msg').textContent = '削除しました';
          document.getElementById('done-dialog').showModal();
        } else {
          alert('削除に失敗しました');
        }
      });
    });
    </script>

    <script>
      document.getElementById('done-ok').addEventListener('click', () => {
        const dialog = document.getElementById('done-dialog');
        dialog.close();

        // カレンダー画面を初期状態に戻す
        location.reload();
      });
    </script>



  

</body>

</html>