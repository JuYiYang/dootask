<?php

namespace App\Module;

use App\Exceptions\ApiException;
use Illuminate\Support\Facades\Http;

class AiAutomationModel
{
    public static function generate(array $settings, string $instruction, array $context): string
    {
        if (!$settings['base_url'] || !$settings['api_key'] || !$settings['model']) {
            throw new ApiException('请先配置 AI 模型');
        }
        $response = Http::withToken($settings['api_key'])->acceptJson()->connectTimeout(5)->timeout(45)
            ->withOptions(['allow_redirects' => false])
            ->post($settings['base_url'] . '/chat/completions', [
                'model' => $settings['model'], 'stream' => false,
                'messages' => [
                    ['role' => 'system', 'content' => AiAutomationSettings::VOICE . '\n' . $settings['voice'] . '\n' . $instruction],
                    ['role' => 'user', 'content' => json_encode($context, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)],
                ],
            ]);
        $text = $response->json('choices.0.message.content');
        if (!$response->successful() || !is_string($text) || trim($text) === '') {
            throw new ApiException('AI 生成失败，请检查模型配置');
        }
        return mb_substr(trim($text), 0, 4000);
    }

}
