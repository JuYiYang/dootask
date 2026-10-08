<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use App\Module\Base;
use App\Module\UserAppearance;
use Request;

class AppearanceController extends AbstractController
{
    /**
     * @api {get} api/appearance/settings 获取当前账号的个人字号
     * @apiGroup appearance
     * @apiSuccess {Number|null} font_size 12至20，null表示使用系统默认字号
     */
    public function settings()
    {
        return Base::retSuccess('success', UserAppearance::get(User::auth()));
    }

    /**
     * @api {post} api/appearance/save 保存当前账号的个人字号
     * @apiGroup appearance
     * @apiParam {Number|null} font_size 12至20的整数，null表示恢复系统默认
     */
    public function save()
    {
        $user = User::auth();
        if (!Request::isMethod('post') || !Request::exists('font_size')) {
            return Base::retError('参数错误');
        }
        return Base::retSuccess('保存成功', UserAppearance::save($user, Request::input('font_size')));
    }
}
