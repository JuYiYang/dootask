<?php

namespace App\Module;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Models\WebSocketDialog;
use App\Models\WebSocketDialogMsg;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

class SystemNoticeWebhook
{
    private const SETTING = 'systemNoticeWebhook';

    public static function settings(): array
    {
        $settings = Base::setting(self::SETTING);
        $dialog = WebSocketDialog::whereType('group')->whereGroupType('all')->orderByDesc('id')->first();
        return [
            'enabled' => !empty($settings['token_hash']),
            'url' => Base::fillUrl('api/systemnotice/send'),
            'dialog_id' => $dialog ? (int)$dialog->id : null,
            'dialog_name' => $dialog?->name,
        ];
    }

    public static function regenerate(): array
    {
        $token = 'snw_' . bin2hex(random_bytes(32));
        Base::setting(self::SETTING, ['token_hash' => hash('sha256', $token)]);
        return array_merge(self::settings(), ['token' => $token]);
    }

    public static function revoke(): array
    {
        Base::setting(self::SETTING, []);
        return self::settings();
    }

    public static function authorize(string $authorization, array $settings): void
    {
        if (empty($settings['token_hash'])
            || !preg_match('/^Bearer (snw_[a-f0-9]{64})$/', trim($authorization), $matches)
            || !hash_equals($settings['token_hash'], hash('sha256', $matches[1]))) {
            throw new ApiException('通知 Webhook 密钥无效或已停用');
        }
    }

    public static function payload(array $input): array
    {
        foreach (['event_id' => 100, 'title' => 100, 'content' => 10000, 'detail' => 50000] as $field => $max) {
            if (!isset($input[$field]) || !is_string($input[$field])
                || trim($input[$field]) === '' || mb_strlen($input[$field]) > $max) {
                throw new ApiException('通知参数无效，请检查 event_id、title、content 和 detail');
            }
        }
        if (!preg_match('/^[a-zA-Z0-9._:-]{1,100}$/', $input['event_id'])) {
            throw new ApiException('通知 event_id 只能包含字母、数字、点、下划线、冒号和短横线');
        }
        $title = trim(strip_tags($input['title']));
        if ($title === '') {
            throw new ApiException('通知标题不能为空');
        }
        $config = \HTMLPurifier_Config::createDefault();
        $config->set('Cache.DefinitionImpl', null);
        $config->set('HTML.Allowed', 'p[style],br,strong,b,em,i,u,s,span[style],div[style],h1[style],h2[style],h3[style],h4[style],blockquote,pre,code,ul,ol,li,a[href|title|target],img[src|alt|width|height],table[style],thead,tbody,tr,th[style|colspan|rowspan],td[style|colspan|rowspan],hr');
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);
        $config->set('Attr.AllowedFrameTargets', ['_blank']);
        $config->set('HTML.TargetBlank', true);
        $config->set('HTML.Nofollow', true);
        $purifier = new \HTMLPurifier($config);
        $content = $purifier->purify($input['content']);
        $detail = $purifier->purify($input['detail']);
        foreach ([$content, $detail] as $html) {
            if (trim(str_replace("\u{00A0}", ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'))) === ''
                && !str_contains($html, '<img ')) {
                throw new ApiException('通知富文本内容不能为空');
            }
        }
        return [
            'type' => 'system_notice',
            'title' => $title,
            'title_raw' => $title,
            'content' => $content,
            'detail' => $detail,
            'source' => 'system-notice-webhook:' . hash('sha256', $input['event_id']),
            'event_id' => $input['event_id'],
        ];
    }

    public static function send(string $authorization, array $input): array
    {
        self::authorize($authorization, Base::setting(self::SETTING));
        $limitKey = 'system-notice-webhook';
        if (RateLimiter::tooManyAttempts($limitKey, 60)) {
            throw new ApiException('请求过于频繁，请稍后重试');
        }
        RateLimiter::hit($limitKey, 60);
        $payload = self::payload($input);
        try {
            return Cache::lock('system-notice-webhook-send', 30)->block(3, function () use ($payload) {
                $dialog = WebSocketDialog::whereType('group')->whereGroupType('all')->orderByDesc('id')->first();
                if (!$dialog) {
                    throw new ApiException('全体成员群聊不存在，无法发送系统通知');
                }
                // event_id is global for this webhook, even after rotating its key or changing the group.
                $existing = WebSocketDialogMsg::withTrashed()->whereType('template')->where('msg->type', 'system_notice')->where('msg->source', $payload['source'])->first();
                if ($existing) {
                    return ['message_id' => (int)$existing->id, 'dialog_id' => (int)$existing->dialog_id, 'duplicate' => true];
                }
                $bot = User::botGetOrCreate('system-msg');
                if (!$bot || $bot->isDisable(true)) {
                    throw new ApiException('系统消息机器人不可用');
                }
                $result = WebSocketDialogMsg::sendMsg(null, $dialog->id, 'template', $payload, $bot->userid, true, true, false, $payload['title']);
                if (($result['ret'] ?? 0) !== 1) {
                    throw new ApiException($result['msg'] ?? '系统通知发送失败');
                }
                return ['message_id' => (int)$result['data']->id, 'dialog_id' => (int)$dialog->id, 'duplicate' => false];
            });
        } catch (LockTimeoutException $e) {
            throw new ApiException('通知发送繁忙，请稍后使用相同 event_id 重试');
        }
    }
}
