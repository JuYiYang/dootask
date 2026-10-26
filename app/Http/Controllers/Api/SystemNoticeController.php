<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use App\Module\Base;
use App\Module\SystemNoticeWebhook;
use Request;

class SystemNoticeController extends AbstractController
{
    /**
     * @api {get} api/systemnotice/settings 获取全员通知 Webhook 配置（仅管理员，不返回密钥）
     * @apiGroup systemnotice
     */
    public function settings()
    {
        User::auth('admin');
        return Base::retSuccess('success', SystemNoticeWebhook::settings());
    }

    /**
     * @api {post} api/systemnotice/regenerate 生成或重新生成全员通知 Webhook 密钥（仅管理员）
     * @apiGroup systemnotice
     */
    public function regenerate()
    {
        User::auth('admin');
        if (!Request::isMethod('post')) return Base::retError('请使用 POST 请求');
        if (config('dootask.system_setting') === 'disabled') return Base::retError('当前环境禁止修改');
        return Base::retSuccess('success', SystemNoticeWebhook::regenerate());
    }

    /**
     * @api {post} api/systemnotice/revoke 停用全员通知 Webhook（仅管理员）
     * @apiGroup systemnotice
     */
    public function revoke()
    {
        User::auth('admin');
        if (!Request::isMethod('post')) return Base::retError('请使用 POST 请求');
        if (config('dootask.system_setting') === 'disabled') return Base::retError('当前环境禁止修改');
        return Base::retSuccess('success', SystemNoticeWebhook::revoke());
    }

    /**
     * @api {post} api/systemnotice/send 向全体成员群发送富文本通知卡片及详情
     * @apiGroup systemnotice
     * @apiHeader {String} Authorization Bearer snw_xxx（独立 Webhook 密钥）
     * @apiParam {String} event_id 外部事件唯一标识（最多100字符，重试须复用）
     * @apiParam {String} title 卡片标题（最多100字）
     * @apiParam {String} content 卡片富文本 HTML（最多10000字符）
     * @apiParam {String} detail 详情富文本 HTML（最多50000字符）
     */
    public function send()
    {
        if (!Request::isMethod('post')) return Base::retError('请使用 POST 请求');
        return Base::retSuccess('success', SystemNoticeWebhook::send(
            (string)Request::header('Authorization'), Request::only(['event_id', 'title', 'content', 'detail'])));
    }
}
