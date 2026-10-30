<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Module\Base;
use App\Module\ProjectAiFileAccess;
use App\Module\ProjectAiTaskAccess;
use Request;

/** @apiDefine projectaifile AI 专用令牌文件读取 */
class ProjectAiFileController extends AbstractController
{
    private function userId(): int
    {
        if (!Request::isMethod('get')) {
            throw new ApiException('请使用 GET 请求');
        }
        return (int)ProjectAiTaskAccess::authorizeRead((string)Request::header('Authorization'))->userid;
    }

    /**
     * @api {get} api/projectaifile/lists 通过 Agent 令牌分页读取文件模块
     * @apiGroup projectaifile
     * @apiHeader {String} Authorization Bearer dai_xxx
     * @apiParam {Number} [pid=0] 文件夹 ID，0 为根入口
     * @apiParam {String} [scope=all] all/mine/shared
     * @apiParam {Number} [recursive=0] pid=0 时全范围搜索（含子目录），1 开启
     * @apiParam {String} [key] 文件名关键词或编号
     * @apiParam {Number} [page=1] 页码，上限 10000
     * @apiParam {Number} [per_page=50] 每页候选条数，上限 100；按 has_more 翻页
     */
    public function lists()
    {
        return Base::retSuccess('success', ProjectAiFileAccess::lists($this->userId(), Request::input()));
    }

    /**
     * @api {get} api/projectaifile/detail 通过 Agent 令牌读取文件详情
     * @apiGroup projectaifile
     * @apiHeader {String} Authorization Bearer dai_xxx
     * @apiParam {Number} id 文件 ID
     * @apiParam {String} [with_text=no] yes 提取支持格式的文本
     * @apiParam {Number} [text_offset=0] 文本字符偏移
     * @apiParam {Number} [text_limit=50000] 文本长度，上限 200000
     */
    public function detail()
    {
        return Base::retSuccess('success', ProjectAiFileAccess::detail($this->userId(), (int)Request::input('id'), Request::input()));
    }

    /**
     * @api {get} api/projectaifile/content 通过 Agent 令牌读取文件内容
     * @apiGroup projectaifile
     * @apiHeader {String} Authorization Bearer dai_xxx
     * @apiParam {Number} id 文件 ID；返回原生 JSON 内容或文件流
     */
    public function content()
    {
        return ProjectAiFileAccess::content($this->userId(), (int)Request::input('id'));
    }

    /**
     * @api {get} api/projectaifile/download 通过 Agent 令牌下载文件（沿用原下载权限）
     * @apiGroup projectaifile
     * @apiHeader {String} Authorization Bearer dai_xxx
     * @apiParam {Number} id 文件 ID
     */
    public function download()
    {
        return ProjectAiFileAccess::content($this->userId(), (int)Request::input('id'), true);
    }
    /**
     * @api {get} api/projectaifile/collaboration 通过 Agent 令牌查询协作文件
     * @apiGroup projectaifile
     * @apiHeader {String} Authorization Bearer dai_xxx
     * @apiParam {String} [scope=all] all/conversation/project
     * @apiParam {Number} [project_id] 项目筛选
     * @apiParam {String} [key] 文件名、会话、项目或任务关键词
     * @apiParam {String} [cursor] 继续使用返回的 next_cursor
     * @apiParam {Number} [take=50] 最大 100
     */
    public function collaboration()
    {
        return Base::retSuccess('success', ProjectAiFileAccess::collaboration($this->userId(), Request::only([
            'scope', 'conversation_type', 'project_id', 'project_source', 'file_type', 'sender_id', 'key', 'cursor', 'take'
        ])));
    }

    /**
     * @api {get} api/projectaifile/attachment 通过 Agent 令牌下载协作文件
     * @apiGroup projectaifile
     * @apiHeader {String} Authorization Bearer dai_xxx
     * @apiParam {Number} [attachment_id] 附件索引 ID（优先）
     * @apiParam {Number} [msg_id] 无索引时的文件消息 ID
     */
    public function attachment()
    {
        return ProjectAiFileAccess::attachment($this->userId(), (int)Request::input('attachment_id'), (int)Request::input('msg_id'));
    }

}
