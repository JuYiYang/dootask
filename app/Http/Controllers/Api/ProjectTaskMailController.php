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

    private function task(int $taskId): ProjectTask
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
            throw new ApiException('请从主任务发送邮件通知');
        }
        return $task;
    }
}
