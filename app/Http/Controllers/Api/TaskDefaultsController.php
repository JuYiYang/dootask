<?php

namespace App\Http\Controllers\Api;

use App\Models\Project;
use App\Models\User;
use App\Module\Base;
use App\Module\TaskCreationDefaults;
use Request;

class TaskDefaultsController extends AbstractController
{
    /**
     * @api {get} api/taskdefaults/assistant 获取项目内新任务默认协助人
     * @apiGroup taskdefaults
     * @apiParam {Number} project_id 当前账号已加入的项目
     */
    public function assistant()
    {
        User::auth();
        $project = Project::userProject((int)Request::input('project_id'));
        return Base::retSuccess('success', ['userid' => TaskCreationDefaults::assistant($project->id)]);
    }
}
