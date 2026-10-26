<?php

namespace App\Module;

use App\Exceptions\ApiException;
use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/** Task scheduling only; reporting and attendance retain their existing calendars. */
class TaskWorkCalendar
{
    public static function settings(): array
    {
        return array_replace(['week_mode' => 'single', 'rest_day' => 0, 'overrides' => []],
            Setting::whereName('taskWorkCalendar')->first()?->setting ?? []);
    }

    public static function validateSettings(array $input): array
    {
        $mode = $input['week_mode'] ?? 'single';
        $rest = $input['rest_day'] ?? 0;
        if (!in_array($mode, ['single', 'double'], true) || !in_array($rest, [0, 6, '0', '6'], true)
            || !is_array($input['overrides'] ?? []) || count($input['overrides'] ?? []) > 366) {
            throw new ApiException('工作日历配置无效');
        }
        $overrides = [];
        foreach ($input['overrides'] ?? [] as $row) {
            if (!is_array($row)) throw new ApiException('自定义日期无效或重复');
            $date = $row['date'] ?? '';
            if (!is_string($date) || !self::validDate($date) || !in_array($row['type'] ?? '', ['work', 'rest'], true)
                || isset($overrides[$date])) {
                throw new ApiException('自定义日期无效或重复');
            }
            $overrides[$date] = ['date' => $date, 'type' => $row['type']];
        }
        ksort($overrides);
        return ['week_mode' => $mode, 'rest_day' => (int)$rest, 'overrides' => array_values($overrides)];
    }

    private static function validDate(string $date): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)
            && checkdate((int)substr($date, 5, 2), (int)substr($date, 8, 2), (int)substr($date, 0, 4));
    }

    public static function year(int $year): array
    {
        $known = config('dootask.work_calendar.' . $year);
        if (is_array($known)) {
            return ['available' => true, 'holidays' => $known['holidays'], 'workdays' => $known['workdays']];
        }
        $key = 'task-scheduling-calendar-v1:' . $year;
        $cached = Cache::get($key);
        if (is_array($cached)) return $cached;
        $data = ['available' => false, 'holidays' => [], 'workdays' => []];
        try {
            $response = Http::connectTimeout(3)->timeout(6)->get(
                'https://raw.githubusercontent.com/NateScarlet/holiday-cn/master/' . $year . '.json');
            $json = $response->json();
            if (!$response->successful() || (int)($json['year'] ?? 0) !== $year
                || !is_array($json['days'] ?? null) || !$json['days']) {
                throw new \UnexpectedValueException('Calendar unavailable');
            }
            foreach ($json['days'] as $day) {
                $date = $day['date'] ?? '';
                if (!is_string($date) || !self::validDate($date) || substr($date, 0, 4) !== (string)$year
                    || !is_bool($day['isOffDay'] ?? null)) {
                    throw new \UnexpectedValueException('Invalid calendar');
                }
                if ($day['isOffDay']) $data['holidays'][] = [$date, $date];
                else $data['workdays'][] = $date;
            }
            $data['available'] = true;
        } catch (\Throwable $e) {
            $data = ['available' => false, 'holidays' => [], 'workdays' => []];
        }
        Cache::put($key, $data, $data['available'] ? now()->addDays(1) : now()->addMinutes(5));
        return $data;
    }

    /** null means the statutory calendar has not been verified. */
    public static function isWorkday(Carbon $day, ?array $settings = null, ?array $calendar = null): ?bool
    {
        $day = $day->copy()->timezone('Asia/Shanghai');
        $settings ??= self::settings();
        $date = $day->toDateString();
        foreach ($settings['overrides'] as $row) {
            if ($row['date'] === $date) return $row['type'] === 'work';
        }
        $calendar ??= self::year($day->year);
        if (!$calendar['available']) return null;
        if (in_array($date, $calendar['workdays'], true)) return true;
        foreach ($calendar['holidays'] as [$start, $end]) {
            if ($date >= $start && $date <= $end) return false;
        }
        return $settings['week_mode'] === 'double' ? $day->isWeekday() : $day->dayOfWeek !== (int)$settings['rest_day'];
    }

    public static function dates(int $year): array
    {
        $settings = self::settings();
        $calendar = self::year($year);
        $blocked = [];
        for ($day = Carbon::create($year, 1, 1, 0, 0, 0, 'Asia/Shanghai'); $day->year === $year; $day->addDay()) {
            if (self::isWorkday($day, $settings, $calendar) !== true) $blocked[] = $day->toDateString();
        }
        return ['year' => $year, 'available' => $calendar['available'], 'disabled_dates' => $blocked];
    }

    /** Inclusive: the first eligible date is working day one. */
    public static function defaultRange(?Carbon $from = null, int $days = 7, ?array $settings = null, ?array $hours = null): array
    {
        $day = ($from ?? Carbon::now('Asia/Shanghai'))->copy()->timezone('Asia/Shanghai')->startOfDay();
        $settings ??= self::settings();
        $start = null;
        for ($i = 0, $count = 0; $i < 740; $i++, $day->addDay()) {
            $workday = self::isWorkday($day, $settings);
            if ($workday === null) throw new ApiException('该年度法定节假日日历尚不可用，请配置自定义日期或开启加班');
            if (!$workday) continue;
            $start ??= $day->copy();
            if (++$count >= $days) {
                $hours ??= Base::setting('system')['task_default_time'] ?? ['09:00', '18:00'];
                if (!is_array($hours) || count($hours) !== 2 || !preg_match('/^\d{2}:\d{2}$/', (string)$hours[0]) || !preg_match('/^\d{2}:\d{2}$/', (string)$hours[1])) {
                    $hours = ['09:00', '18:00'];
                }
                return [$start->toDateString() . ' ' . ($hours[0] ?? '09:00'), $day->toDateString() . ' ' . ($hours[1] ?? '18:00')];
            }
        }
        throw new ApiException('无法计算任务工作日周期，请检查工作日历');
    }

    public static function validateTimes($times, bool $overtime, array $previous = [], ?array $settings = null): void
    {
        $values = is_string($times) ? explode(',', $times) : (is_array($times) ? $times : []);
        [$start, $end] = array_pad($values, 2, null);
        if (!$start && !$end) return; // Clearing a schedule remains supported.
        if (!Timer::isDate($start) || !Timer::isDate($end) || Carbon::parse($end)->lte(Carbon::parse($start))) {
            throw new ApiException('任务结束时间必须晚于开始时间');
        }
        if ($overtime) return;
        $settings ??= self::settings();
        foreach ([$start, $end] as $index => $value) {
            $day = Carbon::parse($value, 'Asia/Shanghai');
            // Historical endpoints can remain untouched without retroactive rescheduling.
            if (!empty($previous[$index]) && $day->toDateString() === Carbon::parse($previous[$index], 'Asia/Shanghai')->toDateString()) continue;
            $result = self::isWorkday($day, $settings);
            if ($result === null) throw new ApiException('该年度法定节假日日历尚不可用，请配置自定义日期或开启加班');
            if (!$result) throw new ApiException('任务日期为非工作日，请选择工作日或开启加班');
        }
    }
}
