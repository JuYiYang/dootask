<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use App\Module\Base;
use App\Module\TaskWorkCalendar;
use Carbon\Carbon;
use Request;

class WorkCalendarController extends AbstractController
{
    /**
     * @api {get} api/workcalendar/settings 获取任务工作日历设置
     * @apiGroup workcalendar
     */
    public function settings()
    {
        User::auth();
        return Base::retSuccess('success', TaskWorkCalendar::settings());
    }

    /**
     * @api {post} api/workcalendar/save 保存任务工作日历（仅管理员）
     * @apiGroup workcalendar
     * @apiParam {Object} settings 单双休及自定义日期
     */
    public function save()
    {
        User::auth('admin');
        if (!Request::isMethod('post')) return Base::retError('请使用 POST 请求');
        if (config('dootask.system_setting') === 'disabled') return Base::retError('当前环境禁止修改');
        $input = Request::input('settings');
        if (!is_array($input)) return Base::retError('工作日历配置无效');
        $settings = TaskWorkCalendar::validateSettings($input);
        Base::setting('taskWorkCalendar', $settings);
        return Base::retSuccess('保存成功', $settings);
    }

    /**
     * @api {get} api/workcalendar/dates 获取年度不可选日期与日历可用性
     * @apiGroup workcalendar
     * @apiParam {Number} year 年份
     */
    public function dates()
    {
        User::auth();
        $year = (int)Request::input('year', date('Y'));
        if ($year < 2000 || $year > 2100) return Base::retError('年份超出范围');
        return Base::retSuccess('success', TaskWorkCalendar::dates($year));
    }

    /**
     * @api {get} api/workcalendar/range 计算任务默认工作日周期（包含首日）
     * @apiGroup workcalendar
     * @apiParam {String} [start] 起始日期
     * @apiParam {Number} [days=7] 工作日数
     */
    public function range()
    {
        User::auth();
        $days = (int)Request::input('days', 7);
        $start = Request::input('start');
        if ($days < 1 || $days > 366 || ($start && !\App\Module\Timer::isDate($start))) return Base::retError('工作日历配置无效');
        return Base::retSuccess('success', ['times' => TaskWorkCalendar::defaultRange($start ? Carbon::parse($start, 'Asia/Shanghai') : null, $days)]);
    }
}
