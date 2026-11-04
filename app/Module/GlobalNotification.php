<?php

namespace App\Module;

use App\Exceptions\ApiException;
use App\Models\UmengAlias;
use App\Models\User;
use App\Models\WebSocket;
use App\Models\WebSocketDialogMsgRead;
use App\Tasks\DeliverGlobalNotification;
use Hhxsv5\LaravelS\Swoole\Task\Task;
use Illuminate\Support\Facades\DB;

class GlobalNotification
{
    private const DELIVERIES = 'global_notification_deliveries';
    private const BATCHES = 'global_notifications';
    private const CHANNELS = ['browser', 'desktop', 'app'];

    public static function send(int $sender, array $input): array
    {
        $requestId = self::requestId($input['request_id'] ?? null);
        $title = self::plainText($input['title'] ?? null, 60);
        $body = self::plainText($input['body'] ?? null, 300);
        $ids = $input['recipients'] ?? null;
        if (!is_array($ids) || !$ids || count($ids) > 100) {
            throw new ApiException('请选择 1 至 100 位收件人');
        }
        $ids = array_map(static fn ($id) => self::positiveId($id), $ids);
        if (in_array(0, $ids, true)) {
            throw new ApiException('收件人无效或已停用');
        }
        $ids = array_values(array_unique($ids));
        sort($ids);
        $deliveryIds = DB::transaction(function () use ($sender, $requestId, $title, $body, $ids) {
            $created = DB::table(self::BATCHES)->insertOrIgnore([
                'sender_id' => $sender,
                'request_id' => $requestId,
                'title' => $title,
                'body' => $body,
                'created_at' => now(),
            ]);
            $batch = self::batch($sender, $requestId);
            if (!$created) {
                $original = DB::table(self::DELIVERIES)->where('notification_id', $batch->id)
                    ->orderBy('recipient_id')->pluck('recipient_id')->map(fn ($id) => (int) $id)->all();
                if ($batch->title !== $title || $batch->body !== $body || $original !== $ids) {
                    throw new ApiException('提交标识已用于另一条通知');
                }
                return [];
            }

            $users = User::whereIn('userid', $ids)->where('bot', 0)->whereNull('disable_at')
                ->get(['userid', 'nickname', 'email'])->keyBy('userid');
            if ($users->count() !== count($ids)) {
                throw new ApiException('收件人无效或已停用');
            }
            return array_map(fn ($id) => DB::table(self::DELIVERIES)->insertGetId([
                'notification_id' => $batch->id,
                'recipient_id' => $id,
                'recipient_name' => (string) $users[$id]->nickname,
            ]), $ids);
        });

        foreach ($deliveryIds as $id) {
            try {
                if (Task::deliver(new DeliverGlobalNotification($id))) {
                    continue;
                }
            } catch (\Throwable) {
                // The worker claims the row atomically before sending.
            }
            DB::table(self::DELIVERIES)->where('id', $id)->whereNull('started_at')->update([
                'started_at' => now(),
                'channels' => self::encode(self::allChannels('failed', 'queue_failed')),
            ]);
        }
        return self::status($sender, $requestId);
    }

    public static function status(int $sender, mixed $requestId): array
    {
        $batch = self::batch($sender, self::requestId($requestId));
        if (!$batch) {
            throw new ApiException('通知记录不存在');
        }
        $expired = now()->subSeconds(30)->greaterThanOrEqualTo($batch->created_at);
        return [
            'recipients' => DB::table(self::DELIVERIES)->where('notification_id', $batch->id)->orderBy('id')
                ->get()->map(function ($row) use ($expired) {
                    $channels = self::decode($row->channels) + self::allChannels(
                        $expired ? 'unconfirmed' : 'waiting', $expired ? 'dispatch_unconfirmed' : ''
                    );
                    $clients = self::decode($row->clients);
                    foreach (['browser', 'desktop'] as $platform) {
                        $devices = [];
                        foreach ($clients as $client) {
                            if ($client['platform'] !== $platform) {
                                continue;
                            }
                            $device = $client['result'];
                            if ($expired && in_array($device['status'], ['waiting', 'received'], true)) {
                                $device = self::outcome('unconfirmed', $device['status'] === 'received' ? 'trigger_unconfirmed' : 'no_receipt');
                            }
                            $devices[] = $device;
                        }
                        if ($devices) {
                            $channels[$platform] = self::aggregate($devices, ['triggered', 'received', 'waiting', 'unconfirmed', 'failed']);
                        }
                    }
                    return ['userid' => $row->recipient_id, 'nickname' => $row->recipient_name, 'channels' => $channels];
                })->all(),
        ];
    }

    public static function deliver(int $id): void
    {
        $query = DB::table(self::DELIVERIES)->where('id', $id);
        if (!(clone $query)->whereNull('started_at')->update(['started_at' => now()])) {
            return;
        }
        $row = $query->first();
        $batch = DB::table(self::BATCHES)->where('id', $row->notification_id)->first();
        if (!User::whereUserid($row->recipient_id)->whereNull('disable_at')->where('bot', 0)->exists()) {
            $query->update(['channels' => self::encode(self::allChannels('failed', 'recipient_unavailable'))]);
            return;
        }

        try {
            [$channels, $clients, $targets] = self::onlineTargets((int) $row->recipient_id);
            $query->update(['channels' => self::encode($channels), 'clients' => self::encode($clients)]);
            foreach ($targets as $target) {
                $payload = [
                    'type' => 'globalNotification',
                    'data' => [
                        'global_delivery_id' => $id,
                        'client_key' => $target['key'],
                        'recipient_id' => (int) $row->recipient_id,
                        'title' => $batch->title,
                        'body' => $batch->body,
                    ],
                ];
                try {
                    // Recheck ownership in case the socket closed or was reassigned while dispatching.
                    $active = WebSocket::whereId($target['id'])->whereUserid($row->recipient_id)->exists();
                    if ($active && app('swoole')->push($target['fd'], self::encode($payload))) {
                        continue;
                    }
                } catch (\Throwable) {
                    // A failed socket must not prevent delivery on other channels.
                }
                self::updateClient((int) $row->recipient_id, $id, $target['key'], 'failed', 'socket_failed');
            }
        } catch (\Throwable) {
            $channels = [
                'browser' => self::outcome('unconfirmed', 'dispatch_unconfirmed'),
                'desktop' => self::outcome('unconfirmed', 'dispatch_unconfirmed'),
            ];
        }

        try {
            $channels['app'] = self::sendApp((int) $row->recipient_id, $id, $batch->title, $batch->body);
        } catch (\Throwable) {
            $channels['app'] = self::outcome('unconfirmed', 'provider_unconfirmed');
        }
        $query->update(['channels' => self::encode($channels)]);
    }

    private static function onlineTargets(int $recipient): array
    {
        $channels = [
            'browser' => self::outcome('offline', 'no_online_client'),
            'desktop' => self::outcome('offline', 'no_online_client'),
        ];
        $clients = [];
        $targets = [];
        $server = app('swoole');
        foreach (WebSocket::whereUserid($recipient)->whereIn('platform', ['web', 'win', 'mac'])->get() as $socket) {
            if (!$server->isEstablished((int) $socket->fd)) {
                continue;
            }
            $platform = $socket->platform === 'web' ? 'browser' : 'desktop';
            $key = bin2hex(random_bytes(16));
            $clients[hash('sha256', $key)] = ['platform' => $platform, 'result' => self::outcome('waiting')];
            $targets[] = ['id' => $socket->id, 'fd' => (int) $socket->fd, 'key' => $key];
        }
        return [$channels, $clients, $targets];
    }

    private static function sendApp(int $recipient, int $id, string $title, string $body): array
    {
        $config = UmengAlias::getPushConfig(true);
        if ($config === false) {
            return self::outcome('unavailable', 'app_disabled');
        }
        $rows = UmengAlias::whereUserid($recipient)->where('updated_at', '>', now()->subMonth())
            ->whereIn('platform', ['ios', 'android'])->whereNotNull('alias')->where('alias', '<>', '')
            ->orderByDesc('updated_at')->get(['alias', 'platform', 'is_notified']);
        if ($rows->isEmpty()) {
            return self::outcome('unavailable', 'no_app_device');
        }
        $badge = WebSocketDialogMsgRead::whereUserid($recipient)->whereSilence(0)->whereReadAt(null)->count();
        $devices = [];
        foreach ($rows->groupBy('platform') as $platform => $group) {
            $credentials = $config[$platform === 'ios' ? 'iOS' : 'Android'] ?? [];
            $aliases = $group->filter(fn ($row) => (int) $row->is_notified === 1)->pluck('alias')->unique()->take(5);
            if ($aliases->isEmpty()) {
                $result = self::outcome('unavailable', 'app_permission_denied');
            } elseif (empty($credentials['appKey']) || empty($credentials['appMasterSecret'])) {
                $result = self::outcome('unavailable', 'app_not_configured');
            } else {
                $sharedAliases = UmengAlias::where('platform', $platform)->whereIn('alias', $aliases)
                    ->where('userid', '<>', $recipient)->pluck('alias');
                $aliases = $aliases->diff($sharedAliases);
                if ($aliases->isEmpty()) {
                    $devices[] = ['platform' => $platform] + self::outcome('unavailable', 'no_app_device');
                    continue;
                }
                try {
                    $response = UmengAlias::submitNotification($aliases->implode(','), $platform, [
                        'title' => $title, 'subtitle' => '', 'body' => $body,
                        'description' => "GN:{$id}", 'extra' => [],
                        'seconds' => 3600, 'badge' => $badge,
                    ], $config);
                    $code = (string) ($response['data']['error_code'] ?? '');
                    $result = match ($response['ret'] ?? null) {
                        'SUCCESS' => self::outcome('accepted'),
                        'FAIL' => self::outcome('failed', 'provider_rejected'),
                        default => self::outcome('unconfirmed', 'provider_unconfirmed'),
                    };
                    if ($result['status'] === 'failed' && preg_match('/^[0-9]{1,8}$/D', $code)) {
                        $result['code'] = $code;
                    }
                } catch (\Throwable) {
                    $result = self::outcome('unconfirmed', 'provider_unconfirmed');
                }
            }
            $devices[] = ['platform' => $platform] + $result;
        }
        return self::aggregate($devices, ['accepted', 'unconfirmed', 'failed', 'unavailable']);
    }

    public static function receipt(int $recipient, array $input): bool
    {
        $status = $input['status'] ?? null;
        $reason = $input['reason'] ?? '';
        if (!in_array($status, ['received', 'triggered', 'failed'], true)
            || !in_array($reason, ['', 'permission_denied', 'not_supported', 'client_error'], true)
            || ($status === 'failed') !== ($reason !== '')) {
            return false;
        }
        return self::updateClient($recipient, self::positiveId($input['delivery_id'] ?? null), $input['client_key'] ?? null, $status, $reason);
    }

    private static function updateClient(int $recipient, int $id, mixed $key, string $status, string $reason): bool
    {
        if (!$id || !is_string($key) || !preg_match('/^[a-f0-9]{32}$/D', $key)) {
            return false;
        }
        return DB::transaction(function () use ($recipient, $id, $key, $status, $reason) {
            $query = DB::table(self::DELIVERIES)->where('id', $id)->where('recipient_id', $recipient);
            $row = (clone $query)->lockForUpdate()->first();
            $clients = self::decode($row->clients ?? null);
            $hash = hash('sha256', $key);
            if (!isset($clients[$hash])) {
                return false;
            }
            $current = $clients[$hash]['result']['status'];
            if ($current === 'failed' || ($current === 'triggered' && $status === 'received')
                || ($reason === 'socket_failed' && $current !== 'waiting')) {
                return true;
            }
            $clients[$hash]['result'] = self::outcome($status, $reason);
            $query->update(['clients' => self::encode($clients)]);
            return true;
        });
    }

    private static function aggregate(array $devices, array $priority): array
    {
        foreach ($priority as $status) {
            foreach ($devices as $device) {
                if ($device['status'] === $status) {
                    unset($device['platform']);
                    return $device + ['devices' => $devices];
                }
            }
        }
        return self::outcome('unconfirmed') + ['devices' => $devices];
    }

    private static function allChannels(string $status, string $reason): array
    {
        return array_fill_keys(self::CHANNELS, self::outcome($status, $reason));
    }

    private static function outcome(string $status, string $reason = ''): array
    {
        return ['status' => $status, 'reason' => $reason];
    }

    private static function requestId(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^[a-zA-Z0-9_-]{16,64}$/D', $value)) {
            throw new ApiException('无效的通知提交标识');
        }
        return $value;
    }

    private static function plainText(mixed $value, int $max): string
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
            throw new ApiException('请填写有效的通知标题和正文');
        }
        $value = trim(str_replace(["\r\n", "\r"], "\n", $value));
        if ($value === '' || mb_strlen($value) > $max || preg_match('/[\x00-\x08\x0B-\x1F\x7F]/', $value)) {
            throw new ApiException('请填写有效的通知标题和正文');
        }
        return $value;
    }

    private static function positiveId(mixed $value): int
    {
        if (!is_int($value) && (!is_string($value) || !ctype_digit($value))) {
            return 0;
        }
        return filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
    }

    private static function batch(int $sender, string $requestId): ?object
    {
        return DB::table(self::BATCHES)->where('sender_id', $sender)->where('request_id', $requestId)->first();
    }

    private static function encode(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private static function decode(?string $value): array
    {
        return $value ? json_decode($value, true, 512, JSON_THROW_ON_ERROR) : [];
    }
}
