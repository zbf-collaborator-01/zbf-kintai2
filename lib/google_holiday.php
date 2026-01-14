<?php
declare(strict_types=1);

class GoogleHolidayRepository
{
    private string $apiKey;
    private string $cacheDir;
    // 日本の祝日カレンダーID
    private const CALENDAR_ID = 'ja.japanese#holiday@group.v.calendar.google.com';

    public function __construct(string $apiKey)
    {
        $this->apiKey = $apiKey;
        // キャッシュ保存先 (libの親ディレクトリのcacheフォルダ)
        $this->cacheDir = dirname(__DIR__) . '/cache';
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0777, true);
        }
    }

    /**
     * 指定した年の祝日リストを取得する
     * @return array<string, string> ['YYYY-MM-DD' => '祝日名']
     */
    public function getHolidays(int $year): array
    {
        $holidays = [];

        // 1. キャッシュがあればそれを使う
        $cacheFile = $this->cacheDir . "/holidays_{$year}.json";
        if (file_exists($cacheFile)) {
            // キャッシュ有効期限: 30日
            if (time() - filemtime($cacheFile) < 60 * 60 * 24 * 30) {
                $holidays = json_decode(file_get_contents($cacheFile), true) ?? [];
            }
        }

        // 2. キャッシュがない、または期限切れならAPIから取得
        if (empty($holidays)) {
            $holidays = $this->fetchFromApi($year, $cacheFile);
        }

        /* ==== 不要な休日を除外するフィルター ==== 
           祝日にしたくない日付はここで設定(ハードコーディング)
        */
        $ignoreKeywords = ['大晦日', 'クリスマス', '節分', '雛祭り', '母の日', '七夕', '七五三', '銀行休業日', '銀行休日'];

        foreach ($holidays as $date => $name) {
            foreach ($ignoreKeywords as $keyword) {
                // 祝日名に除外キーワードが含まれていたら削除
                if (mb_strpos($name, $keyword) !== false) {
                    unset($holidays[$date]);
                    break;
                }
            }
        }

        return $holidays;
    }

    private function fetchFromApi(int $year, string $cacheFile): array
    {
        // 期間指定: その年の1月1日〜12月31日
        $timeMin = date('c', strtotime("{$year}-01-01 00:00:00"));
        $timeMax = date('c', strtotime("{$year}-12-31 23:59:59"));

        $url = 'https://www.googleapis.com/calendar/v3/calendars/' . urlencode(self::CALENDAR_ID) . '/events?' . http_build_query([
            'key' => $this->apiKey,
            'timeMin' => $timeMin,
            'timeMax' => $timeMax,
            'singleEvents' => 'true',
            'orderBy' => 'startTime',
            'maxResults' => 100,
        ]);

        $json = @file_get_contents($url);

        if ($json === false) {
            // 通信エラー時は空配列を返す（キャッシュ更新もしない）
            // 必要ならログ出力などを追加
            return [];
        }

        $data = json_decode($json, true);
        $holidays = [];

        if (isset($data['items'])) {
            foreach ($data['items'] as $item) {
                // 日付 ('2025-01-01')
                $date = $item['start']['date'] ?? substr($item['start']['dateTime'] ?? '', 0, 10);
                // 祝日名 ('元日')
                $name = $item['summary'] ?? 'Holiday';
                $holidays[$date] = $name;
            }
        }

        // キャッシュに保存
        file_put_contents($cacheFile, json_encode($holidays, JSON_UNESCAPED_UNICODE));

        return $holidays;
    }
}