<?php

namespace App\Http\Controllers\Api;

use App\Models\Project;
use App\Models\User;
use App\Module\AiAutomation;
use App\Module\AiAutomationModel;
use App\Module\AiAutomationSettings;
use App\Module\Base;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Request;

class AiAutomationController extends AbstractController
{
    /**
     * @api {get} api/aiautomation/settings 获取 AI 自动汇报和催办设置（仅系统管理员）
     * @apiGroup aiautomation
     */
    public function settings()
    {
        User::auth('admin');
        return Base::retSuccess('success', AiAutomationSettings::publicData());
    }

    /**
     * @api {post} api/aiautomation/save 保存设置，空白 API Key 保留原密钥
     * @apiGroup aiautomation
     * @apiParam {Object} settings 模型、时间、项目及账号范围配置
     */
    public function save()
    {
        User::auth('admin');
        if (config('dootask.system_setting') === 'disabled') {
            return Base::retError('当前环境禁止修改');
        }
        $input = Request::input('settings');
        if (!Request::isMethod('post') || !is_array($input)) {
            return Base::retError('AI 配置参数无效');
        }
        $data = AiAutomationSettings::validate($input, AiAutomationSettings::get());
        if (Project::whereIn('id', $data['project_ids'])->whereNull('archived_at')->count() !== count($data['project_ids'])
            || User::whereIn('userid', $data['weekly_user_ids'])->where('bot', 0)->whereNull('disable_at')->count() !== count($data['weekly_user_ids'])) {
            return Base::retError('请选择有效项目和账号');
        }
        Base::setting('aiAutomation', $data);
        return Base::retSuccess('保存成功', AiAutomationSettings::publicData());
    }

    /**
     * @api {get} api/aiautomation/options 获取可配置项目及账号（仅系统管理员）
     * @apiGroup aiautomation
     */
    public function options()
    {
        User::auth('admin');
        return Base::retSuccess('success', [
            'projects' => Project::whereNull('archived_at')->orderBy('name')->get(['id', 'name']),
            'users' => User::where('bot', 0)->whereNull('disable_at')->orderBy('nickname')->get(['userid', 'nickname', 'email']),
        ]);
    }

    /**
     * @api {post} api/aiautomation/preview 使用已保存设置预览周总结或催办，不发送消息
     * @apiGroup aiautomation
     * @apiParam {String} kind weekly 或 remind
     * @apiParam {Number} [userid] 周报所属账号
     * @apiParam {Number} [task_id] 催办任务
     */
    public function preview()
    {
        User::auth('admin');
        if (!Request::isMethod('post')) {
            return Base::retError('请使用 POST 请求');
        }
        $settings = AiAutomationSettings::get();
        $now = Carbon::now();
        if (Request::input('kind') === 'weekly') {
            $userid = (int)Request::input('userid');
            $user = User::whereUserid($userid)->where('bot', 0)->first();
            if (!$user || $user->isDisable(true) || !in_array($userid, $settings['weekly_user_ids'], true)) {
                return Base::retError('请选择已配置的周报账号');
            }
            $context = AiAutomation::weeklyContext($settings, $user, $now);
            $instruction = AiAutomation::weeklyInstruction();
        } elseif (Request::input('kind') === 'remind') {
            $task = AiAutomation::tasks($settings)->whereKey((int)Request::input('task_id'))->first();
            if (!$task) {
                return Base::retError('任务不在配置范围内');
            }
            $reason = AiAutomation::reminderReason($settings, $task, $now, null);
            if (!$reason) {
                return Base::retError('任务不符合催办条件');
            }
            $context = ['name' => $task->name, 'status' => $task->flow_item_name,
                'deadline' => $task->end_at?->toDateTimeString(), 'updated_at' => $task->updated_at?->toDateTimeString(),
                'reason' => $reason, 'now' => $now->toDateTimeString()];
            $instruction = '写两到三句任务催办，问进度或阻塞，不虚构催办次数，不输出 HTML。';
        } else {
            return Base::retError('参数错误');
        }
        return Base::retSuccess('success', ['text' => AiAutomationModel::generate($settings, $instruction, $context)]);
    }

    /**
     * @api {get} api/aiautomation/history 最近自动发送记录（仅系统管理员，不返回正文）
     * @apiGroup aiautomation
     */
    public function history()
    {
        User::auth('admin');
        return Base::retSuccess('success', DB::table('ai_automation_deliveries')->orderByDesc('id')->limit(30)
            ->get(['id', 'kind', 'target_id', 'period', 'status', 'msg_id', 'sent_at', 'updated_at']));
    }
}
