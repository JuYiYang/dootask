<?php

namespace App\Http\Controllers\Api;

use App\Models\Meeting;
use App\Models\User;
use App\Module\Base;
use App\Module\MeetingRoom;
use Request;

class MeetingController extends AbstractController
{
    /**
     * @api {post} api/meeting/leave 离开后检查并关闭空会议
     * @apiGroup meeting
     * @apiParam {String} meetingid 会议编号
     * @apiParam {String} [sharekey] 游客使用该会议的有效分享凭证
     */
    public function leave()
    {
        $meetingid = Request::input('meetingid');
        $sharekey = Request::input('sharekey', '');
        if (!Request::isMethod('post') || !is_string($meetingid)
            || !is_string($sharekey) || strlen($meetingid) > 64 || strlen($sharekey) > 512) {
            return Base::retError('参数错误');
        }
        $meetingid = trim($meetingid);
        if ($sharekey !== '') {
            $share = Meeting::getShareInfo($sharekey);
            if (!$share || ($share['meetingid'] ?? '') !== $meetingid) {
                return Base::retError('分享链接已过期');
            }
        } else {
            User::auth();
        }
        $meeting = Meeting::whereMeetingid($meetingid)->first();
        if (!$meeting) {
            return Base::retError('频道ID不存在');
        }
        return Base::retSuccess('success', [
            'closed' => MeetingRoom::afterLeave($meeting->id),
        ]);
    }
}
