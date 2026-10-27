<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Models\ProjectTask;
use App\Models\ProjectTaskUser;
use App\Models\ProjectTaskVisibilityUser;
use App\Models\ProjectUser;
use App\Models\User;
use App\Module\Base;
use App\Module\TaskMailNotification;
use App\Module\TaskInstantNotification;
use Request;

/**
 * @apiDefine projecttaskmail 任务邮件通知
 */
class ProjectTaskMailController extends AbstractController
{
    /**
     * @api {get} api/projecttaskmail/options 可发送任务邮件的相关人员
     * @apiGroup projecttaskmail
     * @apiParam {Number} task_id 任务ID
     */
    public function options()
    {
        $task = $this->task((int)Request::input('task_id'));
        return Base::retSuccess('success', [
            'recipients' => TaskMailNotification::recipients($task, (int)User::userid()),
            'smtp_ready' => TaskMailNotification::smtpReady(),
            'history_limit' => TaskMailNotification::HISTORY_LIMIT,
        ]);
    }

    /**
     * @api {post} api/projecttaskmail/send 发送任务信息、最近讨论和动态
     * @apiGroup projecttaskmail
     * @apiParam {Number} task_id 任务ID
     * @apiParam {Array} recipient_ids 相关人员ID，发送者本人不可选
     */
    public function send()
    {
        if (!Request::isMethod('post')) {
            return Base::retError('请使用 POST 请求');
        }
        $ids = Request::input('recipient_ids');
        if (!is_array($ids) || !$ids || count($ids) > 100) {
            return Base::retError('请选择有效的发送人');
        }
        foreach ($ids as $id) {
            if ((!is_int($id) && !is_string($id)) || !ctype_digit((string)$id) || (int)$id <= 0) {
                return Base::retError('请选择有效的发送人');
            }
        }
        $task = $this->task((int)Request::input('task_id'));
        try {
            $result = TaskMailNotification::send($task, User::auth(), $ids);
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            return Base::retError($e->getMessage());
        }
        $message = $result['sent'] === 0 ? '邮件发送失败'
            : ($result['failed'] ? '部分邮件发送失败' : '发送成功');
        return Base::retSuccess($message, $result);
    }

    /**
     * @api {get} api/projecttaskmail/notifyoptions 可发送任务实时通知的相关人员（不依赖邮箱）
     * @apiGroup projecttaskmail
     * @apiParam {Number} task_id 任务ID
     */
    public function notifyoptions()
    {
        $task = $this->task((int)Request::input('task_id'), false);
        return Base::retSuccess('success', ['recipients' => TaskMailNotification::recipients($task, (int)User::userid(), false)]);
    }

    /**
     * @api {post} api/projecttaskmail/notify 立即发送任务实时通知（桌面、浏览器等）
     * @apiGroup projecttaskmail
     * @apiParam {Number} task_id 主任务ID
     * @apiParam {Array} recipient_ids 相关人员ID，不含发送者本人
     * @apiParam {String} request_id 本次发送请求标识，重试必须复用
     */
    public function notify()
    {
        if (!Request::isMethod('post')) return Base::retError('请使用 POST 请求');
        $task = $this->task((int)Request::input('task_id'), false);
        $ids = Request::input('recipient_ids');
        if (!is_array($ids) || !$ids || count($ids) > 100) return Base::retError('请选择有效的发送人');
        foreach ($ids as $id) {
            if ((!is_int($id) && !is_string($id)) || !ctype_digit((string)$id) || (int)$id <= 0) {
                return Base::retError('请选择有效的发送人');
            }
        }
        $result = TaskInstantNotification::send($task, User::auth(), $ids, (string)Request::input('request_id'));
        $message = $result['sent'] === 0 ? '通知发送失败' : ($result['failed'] ? '部分通知发送失败' : '发送成功');
        return $result['sent'] > 0 ? Base::retSuccess($message, $result) : Base::retError($message, $result);
    }

    private function task(int $taskId, bool $forMail = true): ProjectTask
    {
        User::auth();
        $task = ProjectTask::userTask($taskId, null);
        $member = ProjectUser::whereProjectId($task->project_id)->whereUserid(User::userid())->first();
        if (!$member) {
            throw new ApiException('无任务权限');
        }
        if ((int)$task->visibility !== 1 && !$member->isOwner()
            && !ProjectTaskUser::whereTaskId($task->id)->whereUserid(User::userid())->exists()
            && !ProjectTaskUser::whereTaskPid($task->id)->whereUserid(User::userid())->exists()
            && !ProjectTaskVisibilityUser::whereTaskId($task->id)->whereUserid(User::userid())->exists()) {
            throw new ApiException('无任务权限');
        }
        if ($task->parent_id > 0) {
            throw new ApiException($forMail ? '请从主任务发送邮件通知' : '请从主任务发送通知');
        }
        return $task;
    }
}
