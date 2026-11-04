<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use App\Module\Base;
use App\Module\GlobalNotification;
use Request;

class GlobalNotificationController extends AbstractController
{
    /**
     * @api {post} api/globalnotification/send 向所选账号发送纯文字系统推送（管理员）
     * @apiGroup globalnotification
     * @apiParam {String} request_id 本次提交的去重标识
     * @apiParam {String} title 通知标题
     * @apiParam {String} body 通知正文
     * @apiParam {Number[]} recipients 收件账号 ID
     */
    public function send()
    {
        $user = User::auth('admin');
        if (!Request::isMethod('post')) {
            return Base::retError('请使用 POST 请求');
        }
        return Base::retSuccess('success', GlobalNotification::send($user->userid, Request::all()));
    }

    /**
     * @api {get} api/globalnotification/status 查询自己发出的通知及各端回执（管理员）
     * @apiGroup globalnotification
     * @apiParam {String} request_id 提交标识
     */
    public function status()
    {
        $user = User::auth('admin');
        return Base::retSuccess('success', GlobalNotification::status($user->userid, Request::input('request_id')));
    }

    /**
     * @api {post} api/globalnotification/receipt 回报浏览器或桌面系统通知的触发结果
     * @apiGroup globalnotification
     */
    public function receipt()
    {
        $user = User::auth();
        if (!Request::isMethod('post')) {
            return Base::retError('请使用 POST 请求');
        }
        return GlobalNotification::receipt($user->userid, Request::all())
            ? Base::retSuccess('success')
            : Base::retError('无效的通知回执');
    }
}
