<?php

namespace App\Module;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ChineseWorkday
{
    public static function isWorkday(Carbon $now): bool
    {
        $day = $now->copy()->timezone('Asia/Shanghai');
        $date = $day->toDateString();
        $calendar = config('dootask.work_calendar.' . $day->year);
        if (is_array($calendar)) {
            if (in_array($date, $calendar['workdays'] ?? [], true)) {
                return true;
            }
            foreach ($calendar['holidays'] ?? [] as [$start, $end]) {
                if ($date >= $start && $date <= $end) {
                    return false;
                }
            }
            return $day->isWeekday();
        }

        // 查询与现有 Extranet 节假日判断相同的日历源；失败时不退回周一至周五，避免假日误发。
        $month = $day->format('Ym');
        $key = 'task-report-work-calendar:' . $month;
        $calendar = Cache::get($key);
        if (!is_array($calendar)) {
            $calendar = self::fetchMonth($month);
            Cache::put($key, $calendar, $calendar['verified'] ? now()->addDays(30) : now()->addMinutes(5));
        }
        return $calendar['verified'] && !in_array($day->format('Ymd'), $calendar['holidays'], true);
    }

    private static function fetchMonth(string $month): array
    {
        try {
            $response = Http::connectTimeout(3)->timeout(5)->get('https://api.apihubs.cn/holiday/get', [
                'field' => 'date', 'month' => $month, 'workday' => 2, 'size' => 31,
            ]);
            $data = $response->json();
            $list = $data['data']['list'] ?? null;
            if (!$response->successful() || ($data['code'] ?? -1) !== 0
                || !is_array($list) || !$list || (int)($data['data']['total'] ?? -1) !== count($list)) {
                throw new \UnexpectedValueException('Incomplete work calendar');
            }
            $holidays = [];
            foreach ($list as $item) {
                $date = (string)($item['date'] ?? '');
                if (!preg_match('/^' . preg_quote($month, '/') . '\d{2}$/', $date)) {
                    throw new \UnexpectedValueException('Invalid work calendar date');
                }
                $holidays[] = $date;
            }
            return ['verified' => true, 'holidays' => $holidays];
        } catch (\Throwable $e) {
            Log::warning('Task report skipped: work calendar unavailable', ['month' => $month]);
            return ['verified' => false, 'holidays' => []];
        }
    }
}
