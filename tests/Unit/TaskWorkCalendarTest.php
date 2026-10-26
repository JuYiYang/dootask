<?php

namespace Tests\Unit;

use App\Exceptions\ApiException;
use App\Module\TaskWorkCalendar;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TaskWorkCalendarTest extends TestCase
{
    private function settings(string $mode = 'single', array $overrides = []): array
    {
        return ['week_mode' => $mode, 'rest_day' => 0, 'overrides' => $overrides];
    }

    public function test_single_rest_sunday_double_rest_weekends_and_statutory_makeup(): void
    {
        $single = $this->settings();
        $double = $this->settings('double');
        $this->assertTrue(TaskWorkCalendar::isWorkday(Carbon::parse('2026-10-17'), $single));
        $this->assertFalse(TaskWorkCalendar::isWorkday(Carbon::parse('2026-10-18'), $single));
        $this->assertFalse(TaskWorkCalendar::isWorkday(Carbon::parse('2026-10-17'), $double));
        foreach ([$single, $double] as $setting) {
            $this->assertFalse(TaskWorkCalendar::isWorkday(Carbon::parse('2026-10-01'), $setting));
            $this->assertTrue(TaskWorkCalendar::isWorkday(Carbon::parse('2026-09-20'), $setting));
        }
        $single['rest_day'] = 6;
        $this->assertFalse(TaskWorkCalendar::isWorkday(Carbon::parse('2026-10-17'), $single));
        $this->assertTrue(TaskWorkCalendar::isWorkday(Carbon::parse('2026-10-18'), $single));
    }

    public function test_overrides_win_and_unavailable_calendars_remain_unknown(): void
    {
        $settings = $this->settings('double', [
            ['date' => '2026-10-01', 'type' => 'work'],
            ['date' => '2026-09-20', 'type' => 'rest'],
        ]);
        $this->assertTrue(TaskWorkCalendar::isWorkday(Carbon::parse('2026-10-01'), $settings));
        $this->assertFalse(TaskWorkCalendar::isWorkday(Carbon::parse('2026-09-20'), $settings));
        $this->assertNull(TaskWorkCalendar::isWorkday(Carbon::parse('2027-01-04'), $settings, ['available' => false]));
    }

    public function test_seven_workdays_include_first_eligible_day_and_skip_holidays(): void
    {
        $hours = ['09:00', '18:00'];
        $this->assertSame(['2026-10-16 09:00', '2026-10-23 18:00'],
            TaskWorkCalendar::defaultRange(Carbon::parse('2026-10-16'), 7, $this->settings(), $hours));
        $this->assertSame(['2026-10-16 09:00', '2026-10-26 18:00'],
            TaskWorkCalendar::defaultRange(Carbon::parse('2026-10-16'), 7, $this->settings('double'), $hours));
        $this->assertSame(['2026-10-08 09:00', '2026-10-15 18:00'],
            TaskWorkCalendar::defaultRange(Carbon::parse('2026-10-01'), 7, $this->settings('double'), $hours));
    }

    public function test_overtime_allows_holidays_without_bypassing_time_order(): void
    {
        TaskWorkCalendar::validateTimes(['2026-10-01 09:00', '2026-10-04 18:00'], true);
        $this->expectException(ApiException::class);
        TaskWorkCalendar::validateTimes(['2026-10-04 18:00', '2026-10-01 09:00'], true);
    }

    public function test_api_cannot_select_a_new_non_workday_without_overtime(): void
    {
        $this->expectException(ApiException::class);
        TaskWorkCalendar::validateTimes(['2026-10-16 09:00', '2026-10-18 18:00'], false, [], $this->settings());
    }

    public function test_historical_endpoints_are_preserved_but_new_endpoints_are_checked(): void
    {
        TaskWorkCalendar::validateTimes(['2026-10-01 09:00', '2026-10-08 18:00'], false,
            ['2026-10-01 09:00', '2026-10-02 18:00'], $this->settings());
        TaskWorkCalendar::validateTimes([], false);
        $this->expectException(ApiException::class);
        TaskWorkCalendar::validateTimes(['2026-10-01 09:00', '2026-10-11 18:00'], false,
            ['2026-10-01 09:00', '2026-10-02 18:00'], $this->settings());
    }

    public function test_settings_reject_duplicate_and_invalid_dates(): void
    {
        $this->assertSame(0, TaskWorkCalendar::validateSettings([])['rest_day']);
        $this->expectException(ApiException::class);
        TaskWorkCalendar::validateSettings($this->settings('single', [
            ['date' => '2026-10-01', 'type' => 'work'], ['date' => '2026-10-01', 'type' => 'rest'],
        ]));
    }

    public function test_remote_calendar_failure_is_cached_without_fallback(): void
    {
        Cache::shouldReceive('get')->once()->andReturn(null);
        Cache::shouldReceive('put')->once()->withArgs(fn ($key, $data, $ttl) => $data['available'] === false);
        Http::fake(['*' => Http::response([], 503)]);
        $this->assertFalse(TaskWorkCalendar::year(2027)['available']);
    }

    public function test_remote_calendar_distinguishes_holidays_from_makeup_days(): void
    {
        Cache::shouldReceive('get')->once()->andReturn(null);
        Cache::shouldReceive('put')->once()->withArgs(fn ($key, $data, $ttl) => $data['available'] === true);
        Http::fake(['*' => Http::response(['year' => 2027, 'days' => [
            ['date' => '2027-01-01', 'isOffDay' => true], ['date' => '2027-01-03', 'isOffDay' => false],
        ]])]);
        $calendar = TaskWorkCalendar::year(2027);
        $this->assertFalse(TaskWorkCalendar::isWorkday(Carbon::parse('2027-01-01'), $this->settings(), $calendar));
        $this->assertTrue(TaskWorkCalendar::isWorkday(Carbon::parse('2027-01-03'), $this->settings(), $calendar));
    }
    public function test_model_entrypoints_reject_holiday_dates_before_writing_tasks(): void
    {
        \Illuminate\Support\Facades\Schema::create('settings', function ($table) {
            $table->increments('id'); $table->string('name'); $table->text('setting');
            $table->timestamps();
        });
        $times = ['2026-10-01 09:00', '2026-10-04 18:00'];
        try {
            \App\Models\ProjectTask::addTask(['parent_id' => 0, 'times' => $times]);
            $this->fail('Creation accepted non-working dates');
        } catch (ApiException $e) {
            $this->assertStringContainsString('非工作日', $e->getMessage());
        }
        $task = \App\Models\ProjectTask::createInstance(['start_at' => '2026-09-28 09:00', 'end_at' => '2026-09-30 18:00']);
        try {
            $task->updateTask(['times' => $times]);
            $this->fail('Update accepted non-working dates');
        } catch (ApiException $e) {
            $this->assertStringContainsString('非工作日', $e->getMessage());
            $this->assertSame('2026-09-30 18:00:00', $task->end_at->toDateTimeString());
        }
    }

    public function test_overtime_migration_preserves_existing_schedule_and_records_flag(): void
    {
        \Illuminate\Support\Facades\Schema::create('project_tasks', function ($table) {
            $table->increments('id'); $table->dateTime('start_at'); $table->dateTime('end_at');
        });
        $db = \Illuminate\Support\Facades\DB::table('project_tasks');
        $db->insert(['id' => 1, 'start_at' => '2026-10-01 09:00:00', 'end_at' => '2026-10-04 18:00:00']);
        $migration = require base_path('database/migrations/2026_10_26_000001_add_task_overtime_flag.php');
        $migration->up();
        $row = $db->first();
        $this->assertSame(0, (int)$row->is_overtime);
        $this->assertSame('2026-10-01 09:00:00', $row->start_at);
        $db->update(['is_overtime' => 1]);
        $this->assertSame(1, (int)\Illuminate\Support\Facades\DB::table('project_tasks')->first()->is_overtime);
        $migration->down();
        $this->assertSame('2026-10-04 18:00:00', \Illuminate\Support\Facades\DB::table('project_tasks')->first()->end_at);
    }

}
