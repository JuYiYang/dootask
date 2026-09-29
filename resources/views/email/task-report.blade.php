<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>今日任务 · {{ $systemName }}</title>
</head>
<body style="margin:0;padding:0;background:#e9e9e5;color:#181a18;font-family:Arial,'Microsoft YaHei',sans-serif;-webkit-text-size-adjust:100%;">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;">{{ $date }}：{{ $total }}项未完成，{{ $overdueCount }}项逾期，{{ $todayCount }}项今日到期。</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:collapse;background:#e9e9e5;">
    <tr><td align="center" style="padding:28px 12px 44px;">
        <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;border-collapse:collapse;background:#fffdf9;">
            <tr><td bgcolor="#181a18" style="padding:0;background:#181a18;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:collapse;">
                    <tr><td height="7" bgcolor="#f36b3f" style="height:7px;background:#f36b3f;font-size:1px;line-height:1px;">&nbsp;</td></tr>
                    <tr><td style="padding:29px 36px 0;font-size:12px;line-height:18px;font-weight:700;letter-spacing:1px;color:#fffdf9;">{{ $systemName }} <span style="font-weight:400;color:#9b9d98;">/ 任务汇报</span></td></tr>
                    <tr><td style="padding:31px 36px 3px;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:collapse;">
                            <tr>
                                <td valign="bottom" style="font-size:36px;line-height:1.15;font-weight:800;color:#fffdf9;">今日任务</td>
                                <td valign="bottom" align="right" style="font-size:58px;line-height:1;font-weight:800;letter-spacing:-4px;color:#f36b3f;">{{ $totalPadded }}</td>
                            </tr>
                        </table>
                    </td></tr>
                    <tr><td style="padding:11px 36px 32px;font-size:13px;line-height:20px;color:#b9bbb4;">{{ $date }} &nbsp;{{ $weekday }}<span style="color:#555b55;">&nbsp;&nbsp; / &nbsp;&nbsp;</span>{{ $userName }}</td></tr>
                </table>
            </td></tr>
            @if (count($urgentTasks) > 0)
                <tr><td bgcolor="#f36b3f" style="padding:15px 36px;background:#f36b3f;font-size:15px;line-height:23px;font-weight:700;color:#181a18;">先处理 <span style="font-size:22px;line-height:20px;">{{ count($urgentTasks) }}</span> 项：逾期 {{ $overdueCount }} 项，今日到期 {{ $todayCount }} 项</td></tr>
                <tr><td style="padding:34px 36px 0;">
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:collapse;">
                        <tr><td style="padding:0 0 11px;border-bottom:2px solid #181a18;font-size:18px;line-height:25px;font-weight:800;color:#181a18;">优先处理</td></tr>
                    </table>
                </td></tr>
                <tr><td style="padding:0 36px;">
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:collapse;">
                        @foreach ($urgentTasks as $task)
                            <tr>
                                <td width="42" valign="top" style="width:42px;padding:22px 8px 20px 0;border-bottom:1px solid #d7d9d2;font-size:12px;line-height:20px;font-weight:700;color:#a6aaa1;">{{ $task['number'] }}</td>
                                <td style="padding:20px 0 22px;border-bottom:1px solid #d7d9d2;">
                                    <a href="{{ $task['url'] }}" target="_blank" rel="noopener noreferrer" style="display:block;color:#181a18;text-decoration:none;">
                                        <span style="display:block;font-size:12px;line-height:18px;font-weight:700;color:{{ $task['group'] === 'overdue' ? '#b74333' : '#a16416' }};">{{ $task['group'] === 'overdue' ? '已逾期' : '今日到期' }} &nbsp;·&nbsp; {{ $task['due'] }}</span>
                                        <span style="display:block;padding:8px 0 7px;font-size:17px;line-height:25px;font-weight:800;color:#181a18;word-break:break-word;">{{ $task['name'] }}</span>
                                        <span style="display:block;font-size:12px;line-height:19px;color:#666d65;">{{ $task['project'] }} &nbsp;/&nbsp; #{{ $task['id'] }} &nbsp;/&nbsp; {{ $task['role'] }}@if ($task['status'] !== '') · {{ $task['status'] }}@endif</span>
                                        <span style="display:block;padding-top:5px;font-size:11px;line-height:17px;font-weight:700;color:#a16416;">查看任务 →</span>
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </table>
                </td></tr>
            @endif
            @if (count($followUpTasks) > 0)
                <tr><td style="padding:35px 36px 0;">
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:collapse;">
                        <tr>
                            <td style="padding:0 0 11px;border-bottom:2px solid #181a18;font-size:18px;line-height:25px;font-weight:800;color:#181a18;">后续跟进</td>
                            <td align="right" style="padding:0 0 11px;border-bottom:2px solid #181a18;font-size:12px;line-height:20px;color:#737971;">{{ count($followUpTasks) }} 项</td>
                        </tr>
                    </table>
                </td></tr>
                <tr><td style="padding:0 36px;">
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:collapse;">
                        @foreach ($followUpTasks as $task)
                            <tr>
                                <td width="42" valign="top" style="width:42px;padding:20px 8px 18px 0;border-bottom:1px solid #d7d9d2;font-size:12px;line-height:20px;font-weight:700;color:#a6aaa1;">{{ $task['number'] }}</td>
                                <td style="padding:18px 0 20px;border-bottom:1px solid #d7d9d2;">
                                    <a href="{{ $task['url'] }}" target="_blank" rel="noopener noreferrer" style="display:block;color:#181a18;text-decoration:none;">
                                        <span style="display:block;font-size:15px;line-height:23px;font-weight:700;color:#181a18;word-break:break-word;">{{ $task['name'] }}</span>
                                        <span style="display:block;padding-top:7px;font-size:12px;line-height:19px;color:#666d65;">{{ $task['project'] }} &nbsp;/&nbsp; #{{ $task['id'] }} &nbsp;/&nbsp; {{ $task['role'] }}@if ($task['status'] !== '') · {{ $task['status'] }}@endif</span>
                                        @if ($task['due'] !== '')
                                            <span style="display:block;padding-top:4px;font-size:12px;line-height:18px;color:#767d74;">截止 {{ $task['due'] }}</span>
                                        @endif
                                        <span style="display:block;padding-top:5px;font-size:11px;line-height:17px;font-weight:700;color:#70786e;">查看任务 →</span>
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </table>
                </td></tr>
            @endif
            <tr><td style="padding:33px 36px 38px;">
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;">
                    <tr><td bgcolor="#181a18" style="background:#181a18;">
                        <a href="{{ $workbenchUrl }}" target="_blank" rel="noopener noreferrer" style="display:inline-block;padding:14px 23px;font-size:14px;line-height:20px;font-weight:700;color:#fffdf9;text-decoration:none;">打开工作台 &nbsp;→</a>
                    </td></tr>
                </table>
            </td></tr>
            <tr><td style="padding:18px 36px 23px;border-top:1px solid #d7d9d2;font-size:11px;line-height:18px;color:#858b82;">{{ $systemName }} · 自动发送 &nbsp;/&nbsp; 任务状态以工作台为准</td></tr>
        </table>
    </td></tr>
</table>
</body>
</html>
