<?php

namespace App\Module;

use App\Models\ProjectTask;
use App\Models\User;
use App\Models\WebSocketDialog;
use App\Models\WebSocketDialogMsg;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AiAutomation
{
    public static function weeklyDue(array $settings, Carbon $now): bool
    {
        return $settings['weekly_enabled'] && $now->dayOfWeekIso === $settings['weekly_day']
            && $now->gte($now->copy()->setTimeFromTimeString($settings['weekly_time']))
            && $now->lt($now->copy()->setTimeFromTimeString($settings['weekly_time'])->addHour());
    }

    public static function reminderReason(array $settings, ProjectTask $task, Carbon $now, ?string $lastHumanAt): ?string
    {
        if ($task->complete_at || $task->archived_at || $task->deleted_at
            || ProjectTask::isCanceledFlowName($task->flow_item_name ?? '')) {
            return null;
        }
        if ($task->end_at && $task->end_at->lte($now)) {
            return '任务已逾期';
        }
        if ($task->end_at && $task->end_at->lte($now->copy()->addHours($settings['due_hours']))) {
            return '任务即将到期';
        }
        $activity = Carbon::parse($lastHumanAt ?: $task->updated_at);
        if ($task->updated_at && $task->updated_at->gt($activity)) {
            $activity = $task->updated_at;
        }
        return $activity->lte($now->copy()->subDays($settings['stale_days'])) ? '任务长期没有更新' : null;
    }

    /** 只选择明确配置的项目，并过滤已删除或归档的项目。 */
    public static function tasks(array $settings)
    {
        return ProjectTask::query()->whereIn('project_id', $settings['project_ids'])
            ->where('parent_id', 0)->whereNull('archived_at')
            ->whereRaw("COALESCE(flow_item_name, '') NOT REGEXP ?", ['已取消|Cancelled|취소됨|キャンセル済み|Abgebrochen|Annulé|Dibatalkan|Отменено'])
            ->whereHas('project', fn($q) => $q->whereNull('deleted_at')->whereNull('archived_at'));
    }

    public static function weeklyContext(array $settings, User $user, Carbon $now): array
    {
        // 按配置的发送日汇总最近七天，不丢失上次汇报之后的周末任务。
        $start = $now->copy()->subDays(7);
        $query = self::tasks($settings)
            ->whereHas('taskUser', fn($q) => $q->where('userid', $user->userid)->whereIn('owner', [0, 1]))
            ->whereHas('project.projectUser', fn($q) => $q->where('userid', $user->userid))
            ->where(function ($q) use ($start, $now) {
                $q->whereNull('complete_at')->orWhereBetween('complete_at', [$start, $now]);
            });
        $total = (clone $query)->count();
        $rows = $query->with('project')->orderByDesc('complete_at')->orderBy('id')->limit(200)->get();
        return ['nickname' => $user->nickname, 'from' => $start->toDateTimeString(), 'to' => $now->toDateTimeString(),
            'total' => $total, 'truncated' => $total > 200,
            'tasks' => $rows->map(fn($task) => self::taskData($task))->all()];
    }

    private static function taskData(ProjectTask $task): array
    {
        return ['id' => $task->id, 'name' => $task->name, 'project' => $task->project?->name,
            'status' => $task->flow_item_name, 'deadline' => $task->end_at?->toDateTimeString(),
            'completed_at' => $task->complete_at?->toDateTimeString(),
            'updated_at' => $task->updated_at?->toDateTimeString(),
            'url' => rtrim((string)config('dootask.task_report_base_url'), '/') . '/single/task/' . $task->id];
    }

    private static function lastHumanAt(ProjectTask $task): ?string
    {
        return $task->dialog_id ? DB::table('web_socket_dialog_msgs as msg')
            ->join('users', 'users.userid', '=', 'msg.userid')->where('msg.dialog_id', $task->dialog_id)
            ->where('users.bot', 0)->whereNull('msg.deleted_at')->max('msg.created_at') : null;
    }

    public static function run(?callable $sender = null): void
    {
        $settings = AiAutomationSettings::get();
        $now = Carbon::now();
        if ((!$settings['weekly_enabled'] && !$settings['remind_enabled'])
            || !$settings['base_url'] || !$settings['api_key'] || !$settings['model'] || !$settings['project_ids']) {
            return;
        }
        // 每分钟最多处理五次模型请求，已发送项跳过，下一轮继续。
        $budget = 5;
        if (self::weeklyDue($settings, $now)) {
            foreach (User::whereIn('userid', $settings['weekly_user_ids'])->where('bot', 0)->get() as $user) {
                if ($user->isDisable(true) || $budget <= 0) {
                    continue;
                }
                self::deliver('weekly', (int)$user->userid, $now->format('o-W'), function () use ($settings, $user, $now) {
                    $cutoff = $now->copy()->setTimeFromTimeString($settings['weekly_time']);
                    $context = self::weeklyContext($settings, $user, $cutoff);
                    if (!$context['tasks']) {
                        return null;
                    }
                    $text = AiAutomationModel::generate($settings,
                        '生成简洁周总结：本周完成、未完成及风险、下一步建议。建议必须标为建议。每项附任务原始链接。使用 Markdown。数据截断时明确说明。不要声称已读取任务讨论或获知未提供的原因。', $context);
                    $current = AiAutomationSettings::get();
                    $recipient = $user->fresh();
                    if (!$current['weekly_enabled'] || !$recipient || $recipient->isDisable(true)
                        || !in_array((int)$user->userid, $current['weekly_user_ids'], true)
                        || array_column(self::weeklyContext($current, $recipient, $cutoff)['tasks'], 'id') !== array_column($context['tasks'], 'id')) {
                        return null;
                    }
                    $bot = User::botGetOrCreate('task-alert');
                    $dialog = WebSocketDialog::checkUserDialog($user, $bot->userid);
                    return [$dialog->id, $bot->userid, "### 每周任务总结\n\n" . $text];
                }, $budget, $sender);
            }
        }
        if (!$settings['remind_enabled'] || $budget <= 0 || $now->format('H:i') < $settings['remind_time']
            || ($settings['workdays_only'] && !$now->isWeekday())) {
            return;
        }
        // 催办仅在配置时间起一小时内执行，避免晚上补发。
        if ($now->gte($now->copy()->setTimeFromTimeString($settings['remind_time'])->addHour())) {
            return;
        }
        self::tasks($settings)->whereNull('complete_at')->with('project')->orderBy('id')->chunkById(100, function ($tasks) use ($settings, $now, &$budget, $sender) {
            foreach ($tasks as $task) {
                if ($budget <= 0) {
                    return false;
                }
                $latestHuman = self::lastHumanAt($task);
                $reason = self::reminderReason($settings, $task, $now, $latestHuman);
                if (!$reason || DB::table('ai_automation_deliveries')->where('kind', 'remind')->where('target_id', $task->id)
                    ->where('status', 'sent')->where('sent_at', '>', $now->copy()->subHours($settings['interval_hours']))->exists()) {
                    continue;
                }
                self::deliver('remind', $task->id, $now->toDateString(), function () use ($settings, $task, $now, $latestHuman) {
                    $fresh = $task->fresh(['project']);
                    if (!$fresh || !self::tasks($settings)->whereKey($task->id)->exists()) {
                        return null;
                    }
                    $reason = self::reminderReason($settings, $fresh, $now, $latestHuman);
                    $owners = DB::table('project_task_users as tu')->join('project_users as pu', function ($join) {
                        $join->on('pu.project_id', '=', 'tu.project_id')->on('pu.userid', '=', 'tu.userid');
                    })->join('users', 'users.userid', '=', 'tu.userid')
                        ->where('tu.task_id', $task->id)->where('tu.owner', 1)->whereNull('users.disable_at')
                        ->where('users.bot', 0)->get(['users.userid', 'users.nickname', 'users.identity']);
                    $owners = $owners->filter(fn($owner) => !str_contains((string)$owner->identity, ',disable,'));
                    if (!$reason || $owners->isEmpty()) {
                        return null;
                    }
                    $text = AiAutomationModel::generate($settings, '写一条两到三句的任务催办。指出真实的到期时间或最近更新时间，问进度或阻塞。不虚构催办次数。不输出 HTML。',
                        ['now' => $now->toDateTimeString(), 'reason' => $reason, 'last_human_message_at' => $latestHuman, 'task' => self::taskData($fresh)]);
                    // 模型请求期间任务可能已完成或有人更新，再检查后发送。
                    $again = $fresh->fresh();
                    $current = AiAutomationSettings::get();
                    if (!$current['remind_enabled'] || !$again || $again->complete_at || $again->archived_at || $again->deleted_at
                        || ProjectTask::isCanceledFlowName($again->flow_item_name)
                        || !$again->updated_at->eq($fresh->updated_at)
                        || !self::tasks($current)->whereKey($task->id)->exists()
                        || !self::reminderReason($current, $again, $now, self::lastHumanAt($again))) {
                        return null;
                    }
                    if (!$again->dialog_id) {
                        DB::transaction(function () use ($again) {
                            $locked = ProjectTask::whereKey($again->id)->lockForUpdate()->firstOrFail();
                            if (!$locked->dialog_id) {
                                $dialog = WebSocketDialog::createGroup($locked->name, $locked->relationUserids(), 'task');
                                $locked->dialog_id = $dialog->id;
                                $locked->save();
                            }
                            $again->dialog_id = $locked->dialog_id;
                        });
                        $again->pushMsg('dialog');
                    }
                    $memberIds = DB::table('web_socket_dialog_users')->where('dialog_id', $again->dialog_id)->pluck('userid')->all();
                    $mentions = '';
                    foreach ($owners as $owner) {
                        if (in_array($owner->userid, $memberIds)) {
                            $mentions .= '<span class="mention user" data-id="' . (int)$owner->userid . '">@'
                                . htmlspecialchars($owner->nickname, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span> ';
                        }
                    }
                    if (!$mentions) {
                        return null;
                    }
                    $bot = User::botGetOrCreate('task-alert');
                    return [$again->dialog_id, $bot->userid, $mentions . htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')];
                }, $budget, $sender);
            }
            return true;
        });
    }

    private static function deliver(string $kind, int $target, string $period, callable $generate, int &$budget, ?callable $sender): void
    {
        $lock = Cache::lock("ai-automation:{$kind}:{$target}", 180);
        if (!$lock->get()) {
            return;
        }
        $key = ['kind' => $kind, 'target_id' => $target, 'period' => $period];
        try {
            $row = DB::table('ai_automation_deliveries')->where($key)->first();
            if ($row && (in_array($row->status, ['sent', 'skipped', 'sending'], true)
                || ($row->retry_at && Carbon::parse($row->retry_at)->isFuture()))) {
                return;
            }
            $budget--;
            DB::table('ai_automation_deliveries')->updateOrInsert($key, ['status' => 'pending', 'updated_at' => now(), 'created_at' => $row?->created_at ?? now()]);
            $message = $generate();
            if (!$message) {
                DB::table('ai_automation_deliveries')->where($key)->update(['status' => 'skipped']);
                return;
            }
            [$dialogId, $botId, $text] = $message;
            // 发送前落账；进程中断后的 sending 不自动重发，避免重复催促。
            DB::table('ai_automation_deliveries')->where($key)->update(['status' => 'sending', 'content' => $text]);
            $result = $sender ? $sender($dialogId, $botId, $text) : WebSocketDialogMsg::sendMsg(null, $dialogId, 'text',
                $kind === 'weekly' ? ['text' => $text, 'type' => 'md'] : ['text' => $text], $botId);
            if (Base::isError($result)) {
                DB::table('ai_automation_deliveries')->where($key)->update(['status' => 'failed', 'retry_at' => now()->addMinutes(15)]);
                return;
            }
            DB::table('ai_automation_deliveries')->where($key)->update(['status' => 'sent', 'msg_id' => $result['data']->id,
                'sent_at' => now(), 'retry_at' => null, 'updated_at' => now()]);
        } catch (\Throwable $e) {
            // 不记录模型回复或含密钥的异常详情。
            if (DB::table('ai_automation_deliveries')->where($key)->value('status') !== 'sending') {
                DB::table('ai_automation_deliveries')->where($key)->update(['status' => 'failed', 'retry_at' => now()->addMinutes(15)]);
            }
            Log::warning('AI automation failed', ['kind' => $kind, 'target_id' => $target, 'error_type' => get_class($e)]);
        } finally {
            $lock->release();
        }
    }
}
