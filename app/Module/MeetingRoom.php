<?php

namespace App\Module;

use App\Models\Meeting;
use App\Models\WebSocketDialog;
use App\Models\WebSocketDialogMsg;
use App\Tasks\CheckMeetingRoomTask;
use Carbon\Carbon;
use Hhxsv5\LaravelS\Swoole\Task\Task;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class MeetingRoom
{
    public static function afterLeave(int $id): bool
    {
        $closed = self::checkAndClose($id) === true;
        if (!$closed && app()->bound('swoole')
            && Cache::add("meeting-room:retry:{$id}", true, 15)) {
            Task::deliver(new CheckMeetingRoomTask($id));
        }
        return $closed;
    }

    public static function retryAfterLeave(int $id): void
    {
        // 声网的频道查询可能稍晚于客户端离开事件更新。
        try {
            foreach ([1, 2, 4] as $seconds) {
                usleep($seconds * 1000000);
                if (self::checkAndClose($id) === true) {
                    return;
                }
            }
        } finally {
            Cache::forget("meeting-room:retry:{$id}");
        }
    }

    public static function closeInactive(): void
    {
        foreach (Meeting::whereNull('end_at')
            ->where('updated_at', '<', Carbon::now()->subMinutes(10))
            ->limit(100)->get() as $meeting) {
            if (self::checkAndClose($meeting->id) === false) {
                Meeting::whereId($meeting->id)->whereNull('end_at')
                    ->update(['updated_at' => Carbon::now()]);
            }
        }
    }

    /**
     * true: 已关闭；false: 有人或检查期间发生新加入；null: 无法确认。
     */
    public static function checkAndClose(int $id): ?bool
    {
        $lock = Cache::lock("meeting-room:close:{$id}", 15);
        if (!$lock->get()) {
            return null;
        }
        try {
            $meeting = Meeting::find($id);
            if (!$meeting || $meeting->end_at) {
                return true;
            }
            $setting = Base::setting('meetingSetting');
            if (($setting['open'] ?? '') !== 'open'
                || empty($setting['appid']) || empty($setting['api_key']) || empty($setting['api_secret'])) {
                return null;
            }
            try {
                $response = Http::withBasicAuth($setting['api_key'], $setting['api_secret'])
                    ->acceptJson()->connectTimeout(3)->timeout(5)
                    ->get('https://api.sd-rtn.com/dev/v1/channel/user/'
                        . rawurlencode($setting['appid']) . '/' . rawurlencode($meeting->channel));
                $data = $response->json();
                if (!$response->successful() || ($data['success'] ?? null) !== true
                    || !is_bool($data['data']['channel_exist'] ?? null)) {
                    return null;
                }
            } catch (\Throwable $e) {
                return null;
            }
            if ($data['data']['channel_exist']) {
                return false;
            }
            // 查询在线人数期间若有人请求加入，不关闭这场会议。
            $ended = Meeting::whereId($id)->whereNull('end_at')
                ->where('updated_at', $meeting->getRawOriginal('updated_at'))
                ->update(['end_at' => Carbon::now()]);
            if (!$ended) {
                return Meeting::whereId($id)->whereNotNull('end_at')->exists();
            }
            $meeting->refresh();
            self::updateMessages($meeting);
            return true;
        } finally {
            $lock->release();
        }
    }

    private static function updateMessages(Meeting $meeting): void
    {
        $newMsg = $meeting->toArray();
        $newMsg['end_at'] = $meeting->end_at->toDateTimeString();
        WebSocketDialogMsg::select(['web_socket_dialog_msgs.*'])
            ->join('meeting_msgs as m', 'm.msg_id', '=', 'web_socket_dialog_msgs.id')
            ->where('m.meetingid', $meeting->meetingid)
            ->chunk(100, function ($msgs) use ($newMsg) {
                foreach ($msgs as $msg) {
                    $msgData = Base::json2array($msg->getRawOriginal('msg'));
                    $msg->msg = Base::array2json(array_merge($msgData, $newMsg));
                    $msg->save();
                    WebSocketDialog::find($msg->dialog_id)?->pushMsg('update', [
                        'id' => $msg->id,
                        'msg' => $msg->msg,
                    ]);
                }
            });
    }
}
