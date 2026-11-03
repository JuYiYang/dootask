<?php

namespace App\Module;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Models\WebSocketDialogMsg;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class TaskNotificationReceipt
{
    public const TIMEOUT_SECONDS = 30;
    public const REASONS = ['permission_denied', 'not_supported', 'client_error', 'native_pending', 'mobile_pending'];

    public static function key(int $taskId, int $senderId, string $requestId): string
    {
        if (!preg_match('/^[a-zA-Z0-9_-]{16,64}$/', $requestId)) {
            throw new ApiException('通知请求标识无效，请重新打开通知窗口');
        }
        return hash('sha256', $taskId . ':' . $senderId . ':' . $requestId);
    }

    public static function prepare(int $taskId, int $senderId, int $recipientId, string $key, string $source): int
    {
        DB::table('task_notification_deliveries')->insertOrIgnore([
            'task_id' => $taskId, 'sender_id' => $senderId, 'recipient_id' => $recipientId,
            'request_key' => $key, 'source' => $source, 'created_at' => now(), 'updated_at' => now(),
        ]);
        return (int)DB::table('task_notification_deliveries')->where('source', $source)->value('id');
    }

    public static function sent(int $id, ?int $messageId): void
    {
        DB::table('task_notification_deliveries')->where('id', $id)->update([
            'message_id' => $messageId, 'sent_at' => now(), 'error_code' => null, 'updated_at' => now(),
        ]);
    }

    public static function failed(int $id, \Throwable $exception): void
    {
        $code = match ($exception->getMessage()) {
            '任务提醒机器人不可用' => 'bot_unavailable',
            '获取会话失败' => 'dialog_unavailable',
            default => 'send_failed',
        };
        DB::table('task_notification_deliveries')->where('id', $id)->update(['error_code' => $code, 'updated_at' => now()]);
    }

    /** Client claims are authenticated and bound to the intended recipient and actual message. */
    public static function record(int $userId, array $input, string $deviceHash): void
    {
        foreach (['client_id', 'status', 'platform', 'reason'] as $field) {
            if (array_key_exists($field, $input) && !is_string($input[$field])) throw new ApiException('参数错误');
        }
        foreach (['delivery_id', 'message_id', 'sequence'] as $field) {
            $value = $input[$field] ?? null;
            if ((!is_int($value) && !is_string($value)) || !filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) {
                throw new ApiException('参数错误');
            }
        }
        $client = (string)($input['client_id'] ?? '');
        $state = (string)($input['status'] ?? '');
        $platform = (string)($input['platform'] ?? '');
        $reason = (string)($input['reason'] ?? '');
        $sequence = filter_var($input['sequence'] ?? null, FILTER_VALIDATE_INT);
        if (!$sequence || $sequence < 1 || !preg_match('/^[a-zA-Z0-9_-]{16,64}$/', $client)
            || !in_array($state, ['received', 'triggered', 'failed'], true)
            || !in_array($platform, ['browser', 'desktop', 'mobile'], true)
            || ($reason !== '' && !in_array($reason, self::REASONS, true))
            || ($state === 'failed' && !in_array($reason, ['permission_denied', 'not_supported', 'client_error'], true))) {
            throw new ApiException('参数错误');
        }
        DB::transaction(function () use ($userId, $input, $deviceHash, $client, $state, $platform, $reason, $sequence) {
            $row = DB::table('task_notification_deliveries')->where('id', (int)($input['delivery_id'] ?? 0))
                ->where('recipient_id', $userId)->lockForUpdate()->first();
            if (!$row) throw new ApiException('无任务权限');
            $messageId = (int)($input['message_id'] ?? 0);
            $message = WebSocketDialogMsg::whereKey($messageId)->whereType('template')
                ->where('msg->source', $row->source)->where('msg->notification_delivery_id', $row->id)->first();
            if (!$message || ($row->message_id && (int)$row->message_id !== $messageId)) {
                throw new ApiException('参数错误');
            }
            $devices = json_decode($row->receipts ?: '{}', true) ?: [];
            $key = hash('sha256', $deviceHash . ':' . $client);
            if (!isset($devices[$key]) && count($devices) >= 32) throw new ApiException('请求过于频繁，请稍后重试');
            $old = $devices[$key] ?? [];
            // Device event sequence prevents network reordering from reverting a later result.
            $terminal = in_array($old['status'] ?? '', ['triggered', 'failed'], true);
            if ($terminal && $state === 'received') return;
            if (($terminal || $state === 'received') && $sequence <= ($old['sequence'] ?? 0)) return;
            $devices[$key] = ['platform' => $platform, 'status' => $state,
                'reason' => $state === 'received' && $reason === '' ? ($old['reason'] ?? '') : $reason,
                'sequence' => $sequence, 'received_at' => $old['received_at'] ?? now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString()];
            DB::table('task_notification_deliveries')->where('id', $row->id)->update([
                'message_id' => $messageId, 'sent_at' => $row->sent_at ?: $message->created_at,
                'error_code' => null, 'receipts' => json_encode($devices), 'updated_at' => now(),
            ]);
        });
    }

    public static function results(int $taskId, int $senderId, string $requestId): array
    {
        $key = self::key($taskId, $senderId, $requestId);
        $rows = DB::table('task_notification_deliveries')->where('task_id', $taskId)
            ->where('sender_id', $senderId)->where('request_key', $key)->orderBy('id')->get();
        $users = User::whereIn('userid', $rows->pluck('recipient_id'))->get(['userid', 'nickname', 'email'])->keyBy('userid');
        return $rows->map(fn ($row) => array_merge([
            'userid' => (int)$row->recipient_id, 'nickname' => $users->get($row->recipient_id)?->nickname ?: '',
            'message_id' => $row->message_id ? (int)$row->message_id : null,
        ], self::summarize($row)))->all();
    }

    private static function summarize(object $row): array
    {
        $devices = array_values(json_decode($row->receipts ?: '{}', true) ?: []);
        $status = 'waiting'; $reason = '';
        $triggered = collect($devices)->contains(fn ($d) => $d['status'] === 'triggered');
        $received = collect($devices)->where('status', 'received');
        if ($triggered) $status = 'triggered';
        elseif ($received->isNotEmpty()) {
            $latest = $received->max('received_at');
            $status = Carbon::parse($latest)->diffInSeconds(now(), false) >= self::TIMEOUT_SECONDS ? 'unconfirmed' : 'received';
            $reason = $status === 'unconfirmed' ? 'trigger_unconfirmed' : ($received->first()['reason'] ?? '');
        } elseif ($devices) {
            $status = 'failed'; $reason = $devices[0]['reason'];
        } elseif ($row->error_code) {
            $status = 'failed'; $reason = $row->error_code;
        } elseif (Carbon::parse($row->sent_at ?: $row->created_at)->diffInSeconds(now(), false) >= self::TIMEOUT_SECONDS) {
            $status = 'unconfirmed'; $reason = 'no_receipt';
        }
        return ['status' => $status, 'reason' => $reason, 'devices' => $devices,
            'retryable' => (bool)$row->error_code && !$row->message_id];
    }
}
