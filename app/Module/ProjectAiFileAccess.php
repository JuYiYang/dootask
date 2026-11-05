<?php

namespace App\Module;

use App\Exceptions\ApiException;
use App\Models\File;
use App\Models\FileContent;
use App\Models\FileUser;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Module\Manticore\ManticoreFile;

/** Agent 文件操作沿用文件模块的账号权限。 */
class ProjectAiFileAccess
{
    private static function actor(int $userId): User
    {
        $user = User::whereUserid($userId)->first();
        if (!$user || $user->isDisable(true)) {
            throw new ApiException('令牌所属账号已失效');
        }
        return $user;
    }

    public static function upload(int $userId, mixed $parentId, mixed $upload): array
    {
        $pid = self::integer($parentId);
        $user = self::actor($userId);
        if (!$upload instanceof UploadedFile || !$upload->isValid()) {
            throw new ApiException('请选择一个有效的上传文件');
        }
        return Lock::withLock("file:upload:{$userId}:{$pid}", function () use ($user, $pid, $upload) {
            self::writableParent($user, $pid);
            $result = File::createInstance([])->contentUpload($user, $pid, '', false, $upload);
            return self::writeResult(File::findOrFail($result['data']['id']), $user);
        }, 120000, 120000);
    }

    public static function mkdir(int $userId, mixed $parentId, mixed $name): array
    {
        $pid = self::integer($parentId);
        $user = self::actor($userId);
        $name = is_string($name) ? trim($name) : '';
        if (mb_strlen($name) < 2 || mb_strlen($name) > 100 || $name === '..'
            || strpbrk($name, '/\\:*?"<>|') !== false || preg_match('/[\x00-\x1f\x7f]/', $name)) {
            throw new ApiException('文件夹名称无效');
        }
        return Lock::withLock("file:upload:{$userId}:{$pid}", function () use ($user, $pid, $name) {
            $parent = self::writableParent($user, $pid);
            $children = $pid > 0 ? File::wherePid($pid) : File::wherePid(0)->whereUserid($user->userid);
            if ($children->count() >= 300) {
                throw new ApiException('每个文件夹里最多只能创建300个文件或文件夹');
            }
            $file = File::createInstance([
                'pid' => $pid, 'name' => $name, 'type' => 'folder', 'ext' => '',
                'userid' => $parent?->userid ?? $user->userid, 'created_id' => $user->userid,
            ]);
            $file->handleDuplicateName();
            if (!$file->saveBeforePP()) {
                throw new ApiException('创建文件夹失败');
            }
            $file->pushMsg('add', $file);
            return self::writeResult($file, $user);
        }, 120000, 120000);
    }

    public static function share(int $userId, array $input): array
    {
        $user = self::actor($userId);
        $id = self::integer($input['id'] ?? null, 1);
        $shared = match ($input['shared'] ?? null) {
            true, 1, '1', 'true' => true,
            false, 0, '0', 'false' => false,
            default => throw new ApiException('参数错误'),
        };
        $permission = self::integer($input['permission'] ?? 0);
        $ids = $input['userids'] ?? [];
        if (!is_array($ids) || count($ids) > 100 || $permission > 1 || ($shared && !$ids) || (!$shared && $ids)) {
            throw new ApiException('参数错误');
        }
        $ids = array_values(array_unique(array_map(fn ($id) => self::integer($id, 1), $ids)));
        if ($shared) {
            $recipients = User::whereIn('userid', $ids)->where('bot', 0)->get(['userid', 'identity', 'disable_at']);
            if (in_array($userId, $ids, true) || $recipients->count() !== count($ids)
                || $recipients->contains(fn (User $recipient) => $recipient->isDisable(true))) {
                throw new ApiException('共享对象无效或已停用');
            }
        }

        [$file, $changed] = DB::transaction(function () use ($id, $user, $shared, $permission, $ids) {
            $file = File::whereId($id)->whereUserid($user->userid)->lockForUpdate()->first();
            if (!$file) {
                throw new ApiException('仅限所有者操作');
            }
            if ($file->isNnShare() || $file->isSubShare()) {
                throw new ApiException('父目录或子项存在独立共享，请在原共享位置调整');
            }
            $current = FileUser::whereFileId($id)->pluck('userid')->map(fn ($id) => (int)$id)->all();
            if ($shared) {
                if (count(array_unique(array_merge($current, $ids))) > 100) {
                    throw new ApiException('共享人数上限100个成员');
                }
                foreach ($ids as $recipientId) {
                    if (!FileUser::updateInsert(['file_id' => $id, 'userid' => $recipientId], ['permission' => $permission])) {
                        throw new ApiException('设置共享失败');
                    }
                }
            } else {
                foreach ($current as $recipientId) {
                    FileUser::deleteFileUser($id, $recipientId);
                }
            }
            $file->updataShare();
            return [$file, $shared ? $ids : $current];
        });
        if ($changed) {
            $file->pushMsg($shared ? 'update' : 'delete', $shared ? $file : null, $changed);
        }
        return self::writeResult($file, $user);
    }

    private static function writableParent(User $user, int $pid): ?File
    {
        if ($pid === 0) {
            return null;
        }
        $parent = File::permissionFind($pid, $user, 1);
        if ($parent->type !== 'folder') {
            throw new ApiException('目标必须是文件夹');
        }
        return $parent;
    }

    private static function writeResult(File $file, User $user): array
    {
        $ids = $user->isTemp() ? [$user->userid] : [0, $user->userid];
        $share = $file->getShareInfo();
        return self::metadata($file, $file->getPermission($ids)) + [
            'shared' => $share !== null,
            'share_inherited' => $share !== null && (int)$share->id !== (int)$file->id,
        ];
    }

    private static function integer(mixed $value, int $minimum = 0): int
    {
        $number = is_string($value) || is_int($value)
            ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $minimum]])
            : false;
        if ($number === false) {
            throw new ApiException('参数错误');
        }
        return $number;
    }

    public static function lists(int $userId, array $params): array
    {
        $user = self::actor($userId);
        $pid = max(0, (int)($params['pid'] ?? 0));
        $scope = $params['scope'] ?? 'all';
        $recursive = (int)($params['recursive'] ?? 0) === 1;
        $key = trim((string)($params['key'] ?? ''));
        $page = max(1, (int)($params['page'] ?? 1));
        $perPage = min(100, max(1, (int)($params['per_page'] ?? 50)));
        if (!in_array($scope, ['mine', 'shared', 'all'], true) || $page > 10000 || mb_strlen($key) > 200 || ($pid > 0 && $recursive)) {
            throw new ApiException('参数错误');
        }
        $ids = $user->isTemp() ? [$userId] : [0, $userId];
        $shared = fn () => DB::table('file_users')
            ->select('file_id')->whereIn('userid', $ids)->where('permission', '>=', 0);
        $query = File::query();
        if ($pid > 0) {
            $parent = File::permissionFind($pid, $user);
            if ($parent->type !== 'folder') {
                throw new ApiException('参数错误');
            }
            $query->wherePid($pid);
        } elseif ($recursive) {
            $query->where(function ($q) use ($userId, $shared, $scope) {
                if ($scope !== 'shared') {
                    $q->where('userid', $userId)->orWhere('created_id', $userId);
                }
                if ($scope !== 'mine') {
                    $q->orWhereIn('pshare', $shared())->orWhereIn('id', $shared());
                }
            });
        } else {
            $query->where(function ($q) use ($userId, $scope, $shared) {
                if ($scope !== 'shared') {
                    $q->where(fn ($mine) => $mine->where('pid', 0)->where('userid', $userId));
                } else {
                    $q->where(fn ($mine) => $mine->where('pid', 0)->where('userid', $userId)->where('share', 1));
                }
                if ($scope !== 'mine') {
                    $q->orWhereIn('id', $shared());
                }
            });
        }
        if ($key !== '') {
            $query->searchByKeyword($key);
        }
        // 候选分页后逐项执行原权限判断，嵌套共享覆盖/撤销不能绕过。
        // 使用 simplePaginate 不返回候选总数，避免泄露无权访问的文件计数。
        $result = $query->orderBy('id')->simplePaginate($perPage, ['*'], 'page', $page);
        $list = [];
        foreach ($result->items() as $file) {
            $permission = $file->getPermission($ids);
            if ($permission >= 0) {
                $list[] = self::metadata($file, $permission);
            }
        }
        return ['userid' => $userId, 'pid' => $pid, 'scope' => $scope, 'recursive' => $recursive,
            'list' => $list, 'page' => $page, 'per_page' => $perPage,
            'has_more' => $result->hasMorePages()];
    }

    public static function detail(int $userId, int $fileId, array $params = []): array
    {
        $permission = -1;
        $file = File::permissionFind($fileId, self::actor($userId), 0, $permission);
        $data = self::metadata($file, $permission);
        if ($file->type !== 'folder') {
            if (in_array($file->ext, File::imageExt, true)) {
                $data['image_url'] = FileContent::getFileUrl($file->id);
            }
            if (($params['with_text'] ?? 'no') === 'yes') {
                $data['text_content'] = ManticoreFile::extractFileContentPaginated($file,
                    max(0, (int)($params['text_offset'] ?? 0)),
                    min(200000, max(1, (int)($params['text_limit'] ?? 50000))));
            }
        }
        return $data;
    }

    public static function content(int $userId, int $fileId, bool $download = false): mixed
    {
        $file = File::permissionFind($fileId, self::actor($userId), $download ? 1 : 0);
        if ($file->type === 'folder') {
            throw new ApiException('文件夹无法提取内容');
        }
        $content = FileContent::whereFid($file->id)->orderByDesc('id')->first();
        return FileContent::formatContent($file, $content?->content, $download);
    }

    public static function collaboration(int $userId, array $params): array
    {
        $data = \App\Services\CollaborationFileService::lists(self::actor($userId), $params);
        foreach ($data['list'] as &$item) {
            $query = !empty($item['attachment_id']) ? 'attachment_id=' . (int)$item['attachment_id'] : 'msg_id=' . (int)$item['msg_id'];
            $item['download_url'] = Base::fillUrl('api/projectaifile/attachment?' . $query);
        }
        unset($item);
        return $data;
    }

    public static function attachment(int $userId, int $attachmentId, int $messageId = 0): mixed
    {
        $user = self::actor($userId);
        if ($attachmentId > 0) {
            $attachment = \App\Models\WebSocketDialogMsgAttachment::find($attachmentId);
            if (!$attachment) {
                throw new ApiException('文件不存在或已被删除');
            }
            \App\Services\CollaborationFileService::authorizeAttachment($attachment, $user);
            $path = (string)$attachment->path;
            $name = (string)$attachment->name;
        } else {
            // 兼容附件索引尚未完成时的旧文件消息，不允许通过任意路径读取。
            $message = \App\Models\WebSocketDialogMsg::find($messageId);
            if (!$message || $message->type !== 'file') {
                throw new ApiException('文件不存在或已被删除');
            }
            \App\Services\CollaborationFileService::authorizeMessage($message, $user);
            $payload = Base::json2array($message->getRawOriginal('msg'));
            $path = (string)($payload['path'] ?? '');
            $name = (string)($payload['name'] ?? '');
        }
        if (preg_match('/^https?:\/\//i', $path)) {
            return redirect()->away($path);
        }
        $local = \App\Services\CollaborationFileService::resolveLocalAttachmentPath($path);
        if (!$local) {
            throw new ApiException('文件不存在或已被删除');
        }
        return Base::DownloadFileResponse($local, $name ?: basename($local));
    }

    private static function metadata(File $file, int $permission): array
    {
        $folder = $file->type === 'folder';
        return ['id' => (int)$file->id, 'pid' => (int)$file->pid, 'name' => $file->name,
            'type' => $file->type, 'ext' => $file->ext, 'size' => (int)$file->size,
            'userid' => (int)$file->userid, 'created_id' => (int)$file->created_id,
            'created_at' => $file->created_at, 'updated_at' => $file->updated_at,
            'permission' => $permission, 'is_folder' => $folder,
            'can_download' => !$folder && $permission >= 1,
            'detail_url' => Base::fillUrl('api/projectaifile/detail?id=' . $file->id),
            'content_url' => $folder ? null : Base::fillUrl('api/projectaifile/content?id=' . $file->id),
            'download_url' => !$folder && $permission >= 1 ? Base::fillUrl('api/projectaifile/download?id=' . $file->id) : null];
    }
}
