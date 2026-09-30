<?php

namespace App\Tasks;

use App\Module\MeetingRoom;

class CheckMeetingRoomTask extends AbstractTask
{
    public function __construct(private int $meetingId)
    {
        parent::__construct($meetingId);
    }

    public function start()
    {
        MeetingRoom::retryAfterLeave($this->meetingId);
    }

    public function end()
    {
    }
}
