<?php

namespace App\Http\Controllers\Api;

use App\Models\ProjectAiTaskToken;
use App\Models\User;
use App\Module\Base;
use App\Module\ProjectAiTaskAccess;
use Request;

/**
 * @apiDefine projectaitask AI 任务专用接口
 */
class ProjectAiTaskController extends AbstractController
{
    /**
     * @api {get} api/projectaitask/tokens 列出项目的 AI 任务令牌
     * @apiGroup projectaitask
     * @apiParam {Number} project_id 项目 ID
     */
    public function tokens()
    {
        $project = ProjectAiTaskAccess::managementProject((int)Request::input('project_id'));
        $actor = User::auth();
        $query = ProjectAiTaskToken::whereProjectId($project->id);
        if (!$actor->isAdmin()) {
            $query->whereUserid($actor->userid);
        }
        return Base::retSuccess('success', $query->orderByDesc('id')
            ->get(['id', 'project_id', 'userid', 'created_by', 'name', 'token_suffix',
                'expires_at', 'last_used_at', 'revoked_at', 'created_at']));
    }

    /**
     * @api {post} api/projectaitask/create 创建或重新生成任务专用令牌
     * @apiGroup projectaitask
     * @apiParam {Number} project_id 项目 ID
     * @apiParam {Number} userid 令牌所属账号 ID（须为项目成员）
     * @apiParam {String} name 令牌备注名称
     */
    public function create()
    {
        if (!Request::isMethod('post')) {
            return Base::retError('请使用 POST 请求');
        }
        $project = ProjectAiTaskAccess::managementProject((int)Request::input('project_id'));
        $result = ProjectAiTaskAccess::create($project, User::auth(),
            (int)Request::input('userid'), (string)Request::input('name'));
        return Base::retSuccess('令牌已生成，请立即复制保存', $result);
    }

    /**
     * @api {post} api/projectaitask/revoke 撤销 AI 任务令牌
     * @apiGroup projectaitask
     * @apiParam {Number} project_id 项目 ID
     * @apiParam {Number} id 令牌 ID
     */
    public function revoke()
    {
        if (!Request::isMethod('post')) {
            return Base::retError('请使用 POST 请求');
        }
        $project = ProjectAiTaskAccess::managementProject((int)Request::input('project_id'));
        $actor = User::auth();
        $query = ProjectAiTaskToken::whereProjectId($project->id)->whereId((int)Request::input('id'));
        if (!$actor->isAdmin()) {
            $query->whereUserid($actor->userid);
        }
        $row = $query->first();
        if (!$row) {
            return Base::retError('令牌不存在');
        }
        $row->revoked_at = now();
        $row->save();
        return Base::retSuccess('令牌已撤销');
    }

    /**
     * @api {get} api/projectaitask/tasks 通过专用 Bearer 令牌查询所属账号任务及本项目待处理任务
     * @apiGroup projectaitask
     * @apiHeader {String} Authorization Bearer dai_xxx
     * @apiParam {Number} [page=1] 页码
     * @apiParam {Number} [per_page=50] 每页条数（最大 100）
     * @apiParam {Number} [include_archived=0] 是否包含归档任务
     */
    public function tasks()
    {
        if (!Request::isMethod('get')) {
            return Base::retError('请使用 GET 请求');
        }
        $row = ProjectAiTaskAccess::authorizeRead((string)Request::header('Authorization'));
        $page = max(1, (int)Request::input('page', 1));
        $perPage = min(100, max(1, (int)Request::input('per_page', 50)));
        if ($page > 10000) {
            return Base::retError('页码超出范围');
        }
        $includeArchived = (int)Request::input('include_archived', 0) === 1;
        return Base::retSuccess('success', array_merge(
            ['userid' => (int)$row->userid],
            ProjectAiTaskAccess::tasks($row, $page, $perPage, $includeArchived)
        ));
    }

    /**
     * @api {post} api/projectaitask/status 使用专用令牌调整所属账号或本项目待处理任务状态
     * @apiGroup projectaitask
     * @apiHeader {String} Authorization Bearer dai_xxx
     * @apiParam {Number} task_id 任务 ID
     * @apiParam {Number} flow_item_id 目标工作流状态 ID
     */
    public function status()
    {
        if (!Request::isMethod('post')) {
            return Base::retError('请使用 POST 请求');
        }
        $row = ProjectAiTaskAccess::authorize((string)Request::header('Authorization'));
        return Base::retSuccess('修改成功', ProjectAiTaskAccess::changeStatus(
            (int)$row->userid, (int)Request::input('task_id'), (int)Request::input('flow_item_id'), (int)$row->project_id));
    }

    /**
     * @api {get} api/projectaitask/statuses 查询所属账号或本项目待处理任务可流转的目标状态
     * @apiGroup projectaitask
     * @apiHeader {String} Authorization Bearer dai_xxx
     * @apiParam {Number} task_id 任务 ID
     */
    public function statuses()
    {
        if (!Request::isMethod('get')) {
            return Base::retError('请使用 GET 请求');
        }
        $row = ProjectAiTaskAccess::authorize((string)Request::header('Authorization'));
        return Base::retSuccess('success', ProjectAiTaskAccess::statuses(
            (int)$row->userid, (int)Request::input('task_id'), (int)$row->project_id));
    }

    /**
     * @api {post} api/projectaitask/comment 使用专用令牌在所属账号或本项目待处理任务讨论中发表评论
     * @apiGroup projectaitask
     * @apiHeader {String} Authorization Bearer dai_xxx
     * @apiParam {Number} task_id 任务 ID（不支持子任务）
     * @apiParam {String} text 评论正文，最多 5000 字
     */
    public function comment()
    {
        if (!Request::isMethod('post')) {
            return Base::retError('请使用 POST 请求');
        }
        $row = ProjectAiTaskAccess::authorize((string)Request::header('Authorization'));
        return ProjectAiTaskAccess::comment(
            (int)$row->userid, (int)Request::input('task_id'), (string)Request::input('text'), (int)$row->project_id);
    }

    /**
     * @api {get} api/projectaitask/logs 查询任务的所有动态
     * @apiGroup projectaitask
     * @apiHeader {String} Authorization Bearer dai_xxx
     * @apiParam {Number} task_id 任务 ID
     * @apiParam {Number} [page=1] 页码
     * @apiParam {Number} [per_page=50] 每页条数（最大 100）
     */
    public function logs()
    {
        if (!Request::isMethod('get')) {
            return Base::retError('请使用 GET 请求');
        }
        $row = ProjectAiTaskAccess::authorizeRead((string)Request::header('Authorization'));
        $page = max(1, (int)Request::input('page', 1));
        $perPage = min(100, max(1, (int)Request::input('per_page', 50)));
        if ($page > 10000) {
            return Base::retError('页码超出范围');
        }
        return Base::retSuccess('success', ProjectAiTaskAccess::logs(
            (int)$row->userid, (int)Request::input('task_id'), $page, $perPage, (int)$row->project_id));
    }

    /**
     * @api {get} api/projectaitask/discussion 查询任务的所有讨论记录
     * @apiGroup projectaitask
     * @apiHeader {String} Authorization Bearer dai_xxx
     * @apiParam {Number} task_id 任务 ID（不支持子任务）
     * @apiParam {Number} [page=1] 页码
     * @apiParam {Number} [per_page=50] 每页条数（最大 100）
     */
    public function discussion()
    {
        if (!Request::isMethod('get')) {
            return Base::retError('请使用 GET 请求');
        }
        $row = ProjectAiTaskAccess::authorizeRead((string)Request::header('Authorization'));
        $page = max(1, (int)Request::input('page', 1));
        $perPage = min(100, max(1, (int)Request::input('per_page', 50)));
        if ($page > 10000) {
            return Base::retError('页码超出范围');
        }
        return Base::retSuccess('success', ProjectAiTaskAccess::discussion(
            (int)$row->userid, (int)Request::input('task_id'), $page, $perPage, (int)$row->project_id));
    }

    /**
     * @api {get} api/projectaitask/comments 查询任务的所有讨论记录
     * @apiGroup projectaitask
     * @apiHeader {String} Authorization Bearer dai_xxx
     * @apiParam {Number} task_id 任务 ID（不支持子任务）
     * @apiParam {Number} [page=1] 页码
     * @apiParam {Number} [per_page=50] 每页条数（最大 100）
     */
    public function comments()
    {
        return $this->discussion();
    }
}
