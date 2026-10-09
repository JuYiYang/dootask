<?php

namespace App\Module;

use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\ProjectTaskUser;
use App\Models\ProjectTaskVisibilityUser;
use App\Models\ProjectUser;
use App\Models\Setting;
use App\Models\User;
use App\Models\WebSocketDialogMsg;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

class TaskMailNotification
{
    public const HISTORY_LIMIT = 50;

    /**
     * Only current project members who can see the task may receive its discussion.
     */
    public static function recipients(ProjectTask $task, int $senderId): array
    {
        $related = array_merge(
            [(int)$task->userid],
            ProjectTaskUser::whereTaskId($task->id)->pluck('userid')->all(),
            ProjectTaskUser::whereTaskPid($task->id)->pluck('userid')->all(),
            $task->dialog_id
                ? WebSocketDialogMsg::whereDialogId($task->dialog_id)->where('bot', 0)
                    ->distinct()->pluck('userid')->all()
                : []
        );
        $related = array_values(array_diff(array_unique(array_map('intval', $related)), [0, $senderId]));
        if (!$related) {
            return [];
        }

        $members = ProjectUser::whereProjectId($task->project_id)
            ->whereIn('userid', $related)->get(['userid', 'owner']);
        $managerIds = $members->filter(fn($member) => in_array((int)$member->owner,
            [ProjectUser::OWNER_PRIMARY, ProjectUser::OWNER_DEPUTY], true))
            ->pluck('userid')->map(fn($id) => (int)$id)->all();
        $visibleIds = array_unique(array_merge(
            $managerIds,
            ProjectTaskUser::whereTaskId($task->id)->pluck('userid')->all(),
            ProjectTaskUser::whereTaskPid($task->id)->pluck('userid')->all(),
            ProjectTaskVisibilityUser::whereTaskId($task->id)->pluck('userid')->all()
        ));
        $allowed = $members->pluck('userid')->map(fn($id) => (int)$id)->all();
        if ((int)$task->visibility !== 1) {
            $allowed = array_values(array_intersect($allowed, array_map('intval', $visibleIds)));
        }

        return User::whereIn('userid', $allowed)->orderBy('userid')->get()
            ->filter(fn(User $user) => !$user->isDisable(true)
                && !User::isBot($user->userid)
                && Base::isEmail($user->email)
                && count(Setting::validateAddr($user->email, null)) > 0)
            ->map(fn(User $user) => [
                'userid' => (int)$user->userid,
                'nickname' => $user->nickname,
                'email' => $user->email,
            ])->values()->all();
    }

    public static function smtpReady(): bool
    {
        $setting = Base::setting('emailSetting');
        return !empty($setting['smtp_server']) && !empty($setting['port'])
            && Base::isEmail($setting['account'] ?? '') && !empty($setting['password']);
    }

    /**
     * A separate message is sent to each recipient so addresses stay private.
     */
    public static function send(ProjectTask $task, User $sender, array $recipientIds, ?callable $sendMessage = null): array
    {
        $candidates = collect(self::recipients($task, (int)$sender->userid))->keyBy('userid');
        $ids = array_values(array_unique(array_map('intval', $recipientIds)));
        if (!$ids || count($ids) > 100 || count($ids) !== count($recipientIds)
            || count(array_diff($ids, $candidates->keys()->all())) > 0) {
            throw new \InvalidArgumentException('请选择有效的发送人');
        }
        $setting = Base::setting('emailSetting');
        if (!self::smtpReady()) {
            throw new \RuntimeException('请先配置 SMTP 邮箱');
        }

        $mailer = $sendMessage ? null : new Mailer(Transport::fromDsn(sprintf(
            'smtp://%s:%s@%s:%s?verify_peer=0',
            rawurlencode($setting['account']), rawurlencode($setting['password']),
            $setting['smtp_server'], $setting['port']
        )));
        $html = self::html($task, $sender);
        $subject = $sender->nickname . ' 发来一项待处理任务：' . $task->name;
        $sent = 0;
        $failed = 0;
        foreach ($ids as $id) {
            $recipient = $candidates->get($id);
            try {
                if ($sendMessage) {
                    $sendMessage($recipient, $subject, $html);
                } else {
                    $mailer->send((new Email())
                        ->from(new Address($setting['account'], Base::settingFind('system', 'system_alias', 'DooTask')))
                        ->to($recipient['email'])->subject($subject)->html($html));
                }
                $sent++;
            } catch (\Throwable $e) {
                $failed++;
                \Log::warning('Task mail notification failed', [
                    'task_id' => $task->id, 'recipient_id' => $id, 'error' => $e->getMessage(),
                ]);
            }
        }
        return ['sent' => $sent, 'failed' => $failed];
    }

    public static function html(ProjectTask $task, User $sender): string
    {
        $project = Project::withTrashed()->find($task->project_id);
        $taskUsers = DB::table('project_task_users as assignment')
            ->join('users as person', 'person.userid', '=', 'assignment.userid')
            ->where('assignment.task_id', $task->id)
            ->select('assignment.owner', 'person.nickname')->get();
        $description = ($task->content?->getContentInfo() ?? [])['content'] ?? '';
        $discussion = $task->dialog_id
            ? WebSocketDialogMsg::whereDialogId($task->dialog_id)
                ->whereIn('type', ['text', 'file'])
                ->with('user:userid,nickname')->orderByDesc('id')->limit(self::HISTORY_LIMIT)->get()
            : collect();
        $flow = explode('|', (string)$task->flow_item_name);
        return view('email.task-notification', [
            'systemName' => Base::settingFind('system', 'system_alias', 'DooTask'),
            'senderName' => $sender->nickname,
            'task' => $task,
            'projectName' => $project?->name ?? '',
            'status' => $flow[1] ?? ($task->complete_at ? '已完成' : '进行中'),
            'owners' => $taskUsers->where('owner', 1)->pluck('nickname')->implode('、'),
            'assists' => $taskUsers->where('owner', 0)->pluck('nickname')->implode('、'),
            'description' => self::plainText((string)$description),
            'discussion' => $discussion->map(fn(WebSocketDialogMsg $message) => array_merge([
                'name' => $message->user?->nickname ?: '成员',
                'time' => (string)$message->created_at,
            ], self::messageContent($message)))->all(),
            'taskUrl' => rtrim((string)config('dootask.task_report_base_url'), '/') . '/single/task/' . $task->id,
            'limit' => self::HISTORY_LIMIT,
        ])->render();
    }

    private static function messageContent(WebSocketDialogMsg $message): array
    {
        $data = $message->msg;
        if ($message->type === 'text') {
            $html = (string)($data['text'] ?? '');
            preg_match_all('/<img\b[^>]*\bsrc\s*=\s*(["\'])(.*?)\1/is', $html, $matches);
            return [
                'text' => self::plainText($html),
                'images' => array_values(array_filter(array_map(self::imageUrl(...), $matches[2]))),
            ];
        }
        if ($message->type === 'file') {
            $name = self::plainText((string)($data['name'] ?? ''));
            $image = in_array(strtolower((string)($data['ext'] ?? '')), ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)
                ? self::imageUrl((string)($data['path'] ?? '')) : null;
            return [
                'text' => $image ? $name : '[文件] ' . $name,
                'images' => $image ? [$image] : [],
            ];
        }
        return ['text' => '', 'images' => []];
    }

    private static function imageUrl(string $source): ?array
    {
        $source = trim(html_entity_decode($source, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $base = rtrim((string)config('dootask.task_report_base_url'), '/');
        if (str_starts_with($source, '{{RemoteURL}}')) {
            $path = substr($source, strlen('{{RemoteURL}}'));
        } elseif (str_starts_with($source, 'https://') || str_starts_with($source, 'http://')) {
            $sourceHost = parse_url($source, PHP_URL_HOST);
            if (!$sourceHost || $sourceHost !== parse_url($base, PHP_URL_HOST)) {
                return null;
            }
            $path = ltrim((string)parse_url($source, PHP_URL_PATH), '/');
        } else {
            $path = ltrim($source, '/');
        }
        if (!str_starts_with($path, 'uploads/') || str_contains($path, '..')
            || preg_match('/[\x00-\x1f\x7f]/', $path)) {
            return null;
        }
        $originalPath = Base::thumbRestore($path);
        if ($originalPath !== $path && !file_exists(public_path($originalPath))) {
            $originalPath = $path;
        }
        return [
            'thumbnail' => $base . '/' . $path,
            'original' => $base . '/' . $originalPath,
        ];
    }

    private static function plainText(string $html): string
    {
        $html = preg_replace('/<br\s*\/?>|<\/p>|<\/div>/i', "\n", $html);
        $text = strip_tags($html);
        for ($i = 0; $i < 2; $i++) {
            $decoded = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($decoded === $text) {
                break;
            }
            $text = $decoded;
        }
        return trim(str_replace("\xc2\xa0", ' ', $text));
    }
}
