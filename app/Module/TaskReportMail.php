<?php

namespace App\Module;

use App\Models\Setting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

class TaskReportMail
{
    public static function due(array $setting, Carbon $now): bool
    {
        if (($setting['task_report_enabled'] ?? 'close') !== 'open') {
            return false;
        }
        $time = (string)($setting['task_report_time'] ?? '');
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time)
            || $now->format('H:i') < $time) {
            return false;
        }
        return ($setting['task_report_days'] ?? 'daily') !== 'weekdays' || $now->isWeekday();
    }

    public static function tasksForUser(int $userId, string $scope, Carbon $now): array
    {
        $assigned = DB::table('project_task_users')
            ->select('task_id')->selectRaw('MAX(owner) AS owner')
            ->where('userid', $userId)->whereIn('owner', [0, 1])->groupBy('task_id');
        $query = DB::table('project_tasks as task')
            ->joinSub($assigned, 'assigned', 'assigned.task_id', '=', 'task.id')
            ->join('projects as project', 'project.id', '=', 'task.project_id')
            ->join('project_users as member', function ($join) use ($userId) {
                $join->on('member.project_id', '=', 'project.id')->where('member.userid', $userId);
            })
            ->whereNull('task.deleted_at')->whereNull('task.archived_at')
            ->whereNull('task.complete_at')->whereNull('project.deleted_at')
            ->whereNull('project.archived_at')
            ->select('task.id', 'task.name', 'task.end_at', 'task.flow_item_name',
                'project.name as project_name', 'assigned.owner')
            ->distinct();
        if ($scope === 'due') {
            $query->whereNotNull('task.end_at')
                ->where('task.end_at', '<', $now->copy()->endOfDay());
        }
        return $query->orderBy('task.end_at')->orderBy('task.id')->get()->all();
    }

    public static function run(?Carbon $now = null, ?callable $sender = null): array
    {
        $now ??= Carbon::now();
        $setting = Base::setting('emailSetting');
        if (!self::due($setting, $now)
            || empty($setting['smtp_server']) || empty($setting['port'])
            || !Base::isEmail($setting['account'] ?? '') || empty($setting['password'])) {
            return ['sent' => 0, 'failed' => 0];
        }

        $scope = ($setting['task_report_scope'] ?? 'all') === 'due' ? 'due' : 'all';
        $date = $now->toDateString();
        $ids = DB::table('project_task_users as assigned')
            ->join('project_tasks as task', 'task.id', '=', 'assigned.task_id')
            ->join('projects as project', 'project.id', '=', 'task.project_id')
            ->join('project_users as member', function ($join) {
                $join->on('member.project_id', '=', 'project.id')
                    ->on('member.userid', '=', 'assigned.userid');
            })
            ->whereIn('assigned.owner', [0, 1])
            ->whereNull('task.deleted_at')->whereNull('task.archived_at')
            ->whereNull('task.complete_at')->whereNull('project.deleted_at')
            ->whereNull('project.archived_at')
            ->distinct()->pluck('assigned.userid')->all();

        $result = ['sent' => 0, 'failed' => 0];
        foreach (array_chunk($ids, 100) as $batch) {
            $users = User::whereIn('userid', $batch)->get();
            foreach ($users as $user) {
                if ($user->isDisable(true) || !Base::isEmail($user->email)
                    || !Setting::validateAddr($user->email, null)) {
                    continue;
                }
                $lock = Cache::lock("task-report-mail:{$date}:{$user->userid}", 300);
                if (!$lock->get()) {
                    continue;
                }
                try {
                    if (DB::table('task_report_mail_deliveries')->where('userid', $user->userid)
                        ->where('report_date', $date)->exists()) {
                        continue;
                    }
                    $tasks = self::tasksForUser((int)$user->userid, $scope, $now);
                    if (!$tasks) {
                        continue;
                    }
                    $subject = self::subject($now);
                    $html = self::html($user, $tasks, $now);
                    if ($sender) {
                        $sender($user, $subject, $html);
                    } else {
                        self::send($setting, $user, $subject, $html);
                    }
                    DB::table('task_report_mail_deliveries')->insert([
                        'userid' => $user->userid,
                        'report_date' => $date,
                        'sent_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $result['sent']++;
                } catch (\Throwable $e) {
                    $result['failed']++;
                    Log::warning('Task report email failed', [
                        'userid' => $user->userid,
                        'date' => $date,
                        'error' => $e->getMessage(),
                    ]);
                } finally {
                    $lock->release();
                }
            }
        }
        return $result;
    }

    private static function subject(Carbon $now): string
    {
        return Base::settingFind('system', 'system_alias', 'DooTask')
            . ' 任务汇报（' . $now->toDateString() . '）';
    }

    private static function html(User $user, array $tasks, Carbon $now): string
    {
        $today = $now->toDateString();
        $groups = [
            'overdue' => ['title' => '逾期任务', 'color' => '#d95745', 'background' => '#fff1ed', 'tasks' => []],
            'today' => ['title' => '今日到期', 'color' => '#b77919', 'background' => '#fff6df', 'tasks' => []],
            'other' => ['title' => '其他未完成任务', 'color' => '#286f69', 'background' => '#eaf8f4', 'tasks' => []],
        ];
        foreach ($tasks as $task) {
            $due = $task->end_at ? substr((string)$task->end_at, 0, 10) : '';
            $group = $due && $due < $today ? 'overdue'
                : ($due === $today ? 'today' : 'other');
            $flow = explode('|', (string)$task->flow_item_name);
            $groups[$group]['tasks'][] = [
                'id' => (int)$task->id,
                'name' => $task->name,
                'project' => $task->project_name,
                'status' => $flow[1] ?? '',
                'role' => (int)$task->owner === 1 ? '负责人' : '协助人',
                'due' => $task->end_at ? substr((string)$task->end_at, 0, 16) : '',
            ];
        }
        return view('email.task-report', [
            'systemName' => Base::settingFind('system', 'system_alias', 'DooTask'),
            'userName' => $user->nickname,
            'date' => $now->format('Y年m月d日'),
            'total' => count($tasks),
            'groups' => $groups,
        ])->render();
    }

    private static function send(array $setting, User $user, string $subject, string $html): void
    {
        $dsn = sprintf('smtp://%s:%s@%s:%s?verify_peer=0',
            rawurlencode($setting['account']), rawurlencode($setting['password']),
            $setting['smtp_server'], $setting['port']);
        (new Mailer(Transport::fromDsn($dsn)))->send((new Email())
            ->from(new Address($setting['account'], Base::settingFind('system', 'system_alias', 'DooTask')))
            ->to($user->email)->subject($subject)->html($html));
    }
}
