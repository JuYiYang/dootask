<?php

namespace App\Http\Controllers\Api;

use App\Models\ProjectAiTaskToken;
use App\Models\User;
use App\Module\Base;
use App\Module\ProjectAiTaskAccess;
use Request;

/**
 * @apiDefine projectaitask AI 任务只读接口
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
     * @api {post} api/projectaitask/create 创建仅能读取任务的令牌
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
     * @api {get} api/projectaitask/tasks 通过专用 Bearer 令牌查询所属账号负责或协助的任务
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
            ProjectAiTaskAccess::tasks((int)$row->userid, $page, $perPage, $includeArchived)
        ));
    }
}
