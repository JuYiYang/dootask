<?php
namespace App\Module;

use App\Exceptions\ApiException;
use App\Models\ProjectTask;
use App\Models\User;
use App\Models\WebSocketDialog;
use App\Models\WebSocketDialogMsg;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

class TaskInstantNotification
{
    public static function send(ProjectTask $task, User $sender, array $recipientIds, string $requestId, ?callable $deliver = null): array
    {
        if (!preg_match('/^[a-zA-Z0-9_-]{16,64}$/', $requestId)) {
            throw new ApiException('通知请求标识无效，请重新打开通知窗口');
        }
        $candidates = array_column(TaskMailNotification::recipients($task, (int)$sender->userid, false), 'userid');
        $ids = array_values(array_unique(array_map('intval', $recipientIds)));
        if (!$ids || count($ids) > 100 || count($ids) !== count($recipientIds) || array_diff($ids, $candidates)) {
            throw new ApiException('请选择有效的发送人');
        }
        $key = hash('sha256', $task->id . ':' . $sender->userid . ':' . $requestId);
        try {
            return Cache::lock('task-instant-notify:' . $key, 30)->block(3, function () use ($task, $sender, $ids, $key, $deliver) {
                $result = ['sent' => 0, 'failed' => 0, 'duplicates' => 0];
                foreach ($ids as $userId) {
                    $source = 'task-instant-notify:' . hash('sha256', $key . ':' . $userId);
                    if (WebSocketDialogMsg::withTrashed()->whereType('template')->where('msg->source', $source)->exists()) {
                        $result['sent']++;
                        $result['duplicates']++;
                        continue;
                    }
                    $payload = [
                        'type' => 'task_list', 'title' => '任务提醒',
                        'list' => [['id' => $task->id, 'name' => $task->name]],
                        'source' => $source,
                        'sender_id' => (int)$sender->userid,
                    ];
                    try {
                        if ($deliver) {
                            $response = $deliver($userId, $payload);
                        } else {
                            $bot = User::botGetOrCreate('task-alert');
                            if (!$bot || $bot->isDisable(true)) throw new ApiException('任务提醒机器人不可用');
                            $dialog = WebSocketDialog::checkUserDialog($bot, $userId);
                            if (!$dialog) throw new ApiException('获取会话失败');
                            $response = WebSocketDialogMsg::sendMsg(...self::messageArguments($dialog->id, $bot->userid, $payload));
                        }
                        if (($response['ret'] ?? 0) !== 1) throw new ApiException('通知发送失败');
                        $result['sent']++;
                    } catch (\Throwable $e) {
                        $result['failed']++;
                    }
                }
                return $result;
            });
        } catch (LockTimeoutException $e) {
            throw new ApiException('通知发送繁忙，请稍后重试');
        }
    }

    public static function messageArguments(int $dialogId, int $botId, array $payload): array
    {
        // Explicit non-silence enables the existing desktop/browser/mobile delivery channels.
        return [null, $dialogId, 'template', $payload, $botId, true, true, false];
    }
}
