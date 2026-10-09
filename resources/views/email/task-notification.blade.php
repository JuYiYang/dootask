<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{{ $senderName }} 发来一项待处理任务：{{ $task->name }}</title>
</head>
<body style="margin:0;padding:0;background:#e9e9e5;font-family:Arial,'PingFang SC','Microsoft YaHei',sans-serif;color:#181a18;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:collapse;background:#e9e9e5;">
    <tr><td align="center" style="padding:28px 12px 44px;">
        <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;border-collapse:collapse;background:#fffdf9;">
            <tr><td bgcolor="#181a18" style="padding:0;background:#181a18;">
                <div style="padding:27px 36px 0;font-size:12px;font-weight:700;letter-spacing:1px;color:#fffdf9;">{{ $systemName }} <span style="font-weight:400;color:#9b9d98;">/ 任务邮件</span></div>
                <div style="margin:23px 36px 0;padding-left:14px;border-left:4px solid #f36b3f;">
                    <div style="padding-bottom:8px;font-size:12px;font-weight:700;color:#f36b3f;">{{ $projectName }} &nbsp;·&nbsp; #{{ $task->id }}</div>
                    <div style="font-size:29px;line-height:1.3;font-weight:800;color:#fffdf9;word-break:break-word;">{{ $task->name }}</div>
                </div>
                <div style="padding:13px 36px 29px;font-size:13px;line-height:20px;color:#b9bbb4;">{{ $senderName }} 发来一项需要您处理的任务</div>
            </td></tr>
            <tr><td align="right" style="padding:24px 36px 0;">
                <div style="display:inline-block;padding:9px 15px;border:3px double #b85a37;color:#b85a37;font-size:22px;line-height:27px;font-weight:800;letter-spacing:2px;transform:rotate(-8deg);">{{ $status }}</div>
            </td></tr>
            <tr><td style="padding:15px 36px 5px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:collapse;">
                    <tr><td style="padding:9px 0;border-bottom:1px solid #d7d9d2;font-size:13px;color:#767d74;width:80px;">负责人</td><td style="padding:9px 0;border-bottom:1px solid #d7d9d2;font-size:13px;">{{ $owners ?: '未指定' }}</td></tr>
                    <tr><td style="padding:9px 0;border-bottom:1px solid #d7d9d2;font-size:13px;color:#767d74;">协助人员</td><td style="padding:9px 0;border-bottom:1px solid #d7d9d2;font-size:13px;">{{ $assists ?: '无' }}</td></tr>
                    <tr><td style="padding:9px 0;border-bottom:1px solid #d7d9d2;font-size:13px;color:#767d74;">计划时间</td><td style="padding:9px 0;border-bottom:1px solid #d7d9d2;font-size:13px;">{{ $task->start_at ?: '未设置' }} @if($task->end_at) — {{ $task->end_at }} @endif</td></tr>
                </table>
            </td></tr>
            @if($description !== '')
                <tr><td style="padding:29px 36px 0;">
                    <div style="padding-bottom:11px;border-bottom:2px solid #181a18;font-size:18px;font-weight:800;">任务说明</div>
                    <div style="padding-top:15px;font-size:14px;line-height:23px;white-space:pre-wrap;word-break:break-word;">{{ $description }}</div>
                </td></tr>
            @endif
            <tr><td style="padding:33px 36px 0;">
                <div style="padding-bottom:11px;border-bottom:2px solid #181a18;font-size:18px;font-weight:800;">讨论 <span style="font-size:12px;font-weight:400;color:#767d74;">最近 {{ $limit }} 条</span></div>
                @forelse(array_slice($discussion, 0, 5) as $item)
                    <div style="padding:14px 0;border-bottom:1px solid #e1e2dc;">
                        <div style="font-size:12px;font-weight:700;">{{ $item['name'] }} <span style="font-weight:400;color:#858b82;">&nbsp;{{ $item['time'] }}</span></div>
                        @if($item['text'] !== '')
                            <div style="padding-top:6px;font-size:13px;line-height:21px;white-space:pre-wrap;word-break:break-word;">{{ $item['text'] }}</div>
                        @endif
                        @if(count($item['images']) > 0)
                            <div style="padding-top:10px;">
                                <a href="{{ $item['images'][0]['original'] }}" target="_blank" rel="noopener noreferrer" style="display:inline-block;text-decoration:none;">
                                    <img src="{{ $item['images'][0]['thumbnail'] }}" alt="讨论图片，点击查看原图" width="160" height="110" style="display:block;width:160px;height:110px;object-fit:cover;border:0;">
                                    <span style="display:block;padding-top:5px;font-size:11px;color:#b85a37;">查看原图 →</span>
                                </a>
                            </div>
                            @if(count($item['images']) > 1)
                                <div style="padding-top:5px;font-size:11px;"><a href="{{ $taskUrl }}" target="_blank" rel="noopener noreferrer" style="color:#767d74;">另有 {{ count($item['images']) - 1 }} 张图片，在任务中查看 →</a></div>
                            @endif
                        @endif
                    </div>
                @empty
                    <div style="padding:17px 0;font-size:13px;color:#858b82;">暂无讨论</div>
                @endforelse
                @if(count($discussion) > 5)
                    <div style="padding-top:13px;font-size:13px;line-height:21px;"><a href="{{ $taskUrl }}" target="_blank" rel="noopener noreferrer" style="font-weight:700;color:#b85a37;text-decoration:none;">查看更多讨论 →</a></div>
                @endif
            </td></tr>
            <tr><td style="padding:34px 36px 38px;">
                <table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr><td bgcolor="#181a18" style="background:#181a18;">
                    <a href="{{ $taskUrl }}" target="_blank" rel="noopener noreferrer" style="display:inline-block;padding:14px 23px;font-size:14px;font-weight:700;color:#fffdf9;text-decoration:none;">打开任务，查看完整记录 &nbsp;→</a>
                </td></tr></table>
            </td></tr>
            <tr><td style="padding:18px 36px 23px;border-top:1px solid #d7d9d2;font-size:11px;line-height:18px;color:#858b82;">{{ $systemName }} · 由 {{ $senderName }} 手动发送</td></tr>
        </table>
    </td></tr>
</table>
</body>
</html>
