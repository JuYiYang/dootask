<?php

namespace App\Tasks;

use App\Module\MeetingRoom;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

class CloseMeetingRoomTask extends AbstractTask
{
    public function start()
    {
        // 定时检查作为断网、关闭浏览器等未能上报离开的兜底。
        $time = intval(Cache::get('CloseMeetingRoomTask:Time'));
        if (time() - $time < 600) {
            return;
        }
        Cache::put('CloseMeetingRoomTask:Time', time(), Carbon::now()->addMinutes(10));
        MeetingRoom::closeInactive();
    }

    public function end()
    {
    }
}
