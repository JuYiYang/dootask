<?php

namespace App\Module;

use App\Exceptions\ApiException;
use App\Models\AbstractModel;
use App\Models\ProjectTask;
use App\Models\ProjectTaskPushLog;
use App\Models\ProjectTaskUser;
use App\Models\User;
use App\Models\WebSocketDialog;
use App\Models\WebSocketDialogMsg;
use Closure;

class TaskOwnerNotification
{
    // Separate from historical, silent new-task/assistant reminders (type 0).
    public const TYPE = 4;

    public static function send(ProjectTask $task, array $userids): void
    {
        $actorId = (int)User::userid();
        $userids = array_values(array_unique(array_filter(array_map('intval', $userids), fn ($id) => $id > 0 && $id !== $actorId)));
        if (!$userids) return;
        foreach (User::whereIn('userid', $userids)->whereNull('disable_at')->where('bot', 0)->get() as $receiver) {
            self::once($task->id, $receiver->userid, function () use ($task, $receiver): bool {
                $bot = User::botGetOrCreate('task-alert');
                if (!$bot) return false;
                $dialog = WebSocketDialog::checkUserDialog($bot, $receiver->userid);
                if (!$dialog) return false;
                $result = WebSocketDialogMsg::sendMsg(...self::messageArguments($task, $dialog->id, $bot->userid));
                // Bot sender and push_self deliver to the other recipients across their connections.
                // Explicit non-silence enables existing desktop/browser notification handling.
                // Retry allows the asynchronous consumer to see the committed message.
                if (($result['ret'] ?? 0) !== 1) {
                    throw new ApiException((string)($result['msg'] ?? '发送失败'));
                }
                return true;
            });
        }
    }

    /** Arguments for the existing message transport; owner changes are explicitly non-silent. */
    public static function messageArguments(ProjectTask $task, int $dialogId, int $botId): array
    {
        return [null, $dialogId, 'template', [
            'type' => 'task_list',
            'title' => '您有一个新任务',
            'list' => [['id' => $task->parent_id ?: $task->id, 'name' => $task->name]],
        ], $botId, true, true, false];
    }

    /** Serialize per task so concurrent retries cannot both notify the same owner. */
    public static function once(int $taskId, int $userId, Closure $deliver): bool
    {
        // A self-triggered assignment is neither delivered nor recorded as notified.
        if ($userId > 0 && $userId === (int)User::userid()) return false;
        return AbstractModel::transaction(function () use ($taskId, $userId, $deliver) {
            if (!ProjectTask::whereKey($taskId)->lockForUpdate()->first()) return false;
            // Query the persisted assignment, never a cached taskUser relationship.
            if (!ProjectTaskUser::whereTaskId($taskId)->whereUserid($userId)->whereOwner(1)->exists()) return false;
            $key = ['task_id' => $taskId, 'userid' => $userId, 'type' => self::TYPE];
            if (ProjectTaskPushLog::withTrashed()->where($key)->exists()) return false;
            if (!$deliver()) return false;
            ProjectTaskPushLog::createInstance($key)->save();
            return true;
        });
    }
}
