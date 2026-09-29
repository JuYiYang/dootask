<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $systemName }} · 任务汇报</title>
</head>
<body style="margin:0;padding:0;background:#f3f6f8;color:#21313b;font-family:Arial,'Microsoft YaHei',sans-serif;-webkit-text-size-adjust:100%;">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;">{{ $date }}任务汇报：共{{ $total }}项，逾期{{ count($groups['overdue']['tasks']) }}项，今日到期{{ count($groups['today']['tasks']) }}项。</div>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="width:100%;border-collapse:collapse;background:#f3f6f8;">
    <tr>
        <td align="center" style="padding:32px 16px 48px;">
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="620" style="width:100%;max-width:620px;border-collapse:separate;border-spacing:0;background:#ffffff;border-radius:20px;overflow:hidden;">
                <tr>
                    <td style="padding:0;background:#172b3a;">
                        <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="width:100%;border-collapse:collapse;">
                            <tr>
                                <td style="padding:35px 40px 0;font-size:12px;line-height:18px;letter-spacing:2px;font-weight:bold;color:#91e3c1;">{{ $systemName }}</td>
                            </tr>
                            <tr>
                                <td style="padding:18px 40px 0;font-size:30px;line-height:38px;font-weight:bold;color:#ffffff;">任务汇报</td>
                            </tr>
                            <tr>
                                <td style="padding:10px 40px 35px;font-size:14px;line-height:23px;color:#cfdee3;">{{ $date }} · 您负责与协助的任务概览</td>
                            </tr>
                        </table>
                    </td>
                </tr>
                <tr>
                    <td style="padding:32px 40px 8px;font-size:15px;line-height:25px;color:#31434c;">
                        <strong style="color:#172b3a;">{{ $userName }}，您好。</strong><br>
                        为您整理了当前参与的未完成任务，方便按轻重缓急安排今天的工作。
                    </td>
                </tr>
                <tr>
                    <td style="padding:18px 40px 30px;">
                        <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="width:100%;border-collapse:collapse;">
                            <tr>
                                <td width="33%" style="padding:17px 10px;text-align:center;background:#f2f8f6;border-radius:12px;">
                                    <div style="font-size:25px;line-height:31px;font-weight:bold;color:#1c6c58;">{{ $total }}</div>
                                    <div style="padding-top:5px;font-size:12px;line-height:18px;color:#657b77;">未完成</div>
                                </td>
                                <td width="2%" style="font-size:1px;">&nbsp;</td>
                                <td width="31%" style="padding:17px 10px;text-align:center;background:#fff1ed;border-radius:12px;">
                                    <div style="font-size:25px;line-height:31px;font-weight:bold;color:#d95745;">{{ count($groups['overdue']['tasks']) }}</div>
                                    <div style="padding-top:5px;font-size:12px;line-height:18px;color:#93665e;">已逾期</div>
                                </td>
                                <td width="2%" style="font-size:1px;">&nbsp;</td>
                                <td width="32%" style="padding:17px 10px;text-align:center;background:#fff6df;border-radius:12px;">
                                    <div style="font-size:25px;line-height:31px;font-weight:bold;color:#b77919;">{{ count($groups['today']['tasks']) }}</div>
                                    <div style="padding-top:5px;font-size:12px;line-height:18px;color:#927447;">今日到期</div>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
                @foreach ($groups as $group)
                    @if (count($group['tasks']) > 0)
                        <tr>
                            <td style="padding:0 40px 12px;">
                                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="width:100%;border-collapse:collapse;">
                                    <tr>
                                        <td style="padding:8px 0 13px;font-size:17px;line-height:24px;font-weight:bold;color:#1e303a;">{{ $group['title'] }}</td>
                                        <td align="right" style="padding:8px 0 13px;font-size:12px;line-height:20px;color:{{ $group['color'] }};">{{ count($group['tasks']) }} 项</td>
                                    </tr>
                                </table>
                                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="width:100%;border-collapse:collapse;">
                                    @foreach ($group['tasks'] as $task)
                                        <tr>
                                            <td style="padding:0 0 10px;">
                                                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="width:100%;border-collapse:separate;border-spacing:0;border:1px solid #e8eef0;border-radius:12px;">
                                                    <tr>
                                                        <td width="4" style="width:4px;background:{{ $group['color'] }};font-size:1px;line-height:1px;">&nbsp;</td>
                                                        <td style="padding:17px 20px;">
                                                            <div style="font-size:11px;line-height:17px;letter-spacing:.3px;color:#71838b;">{{ $task['project'] }} &nbsp;·&nbsp; #{{ $task['id'] }}</div>
                                                            <div style="padding:7px 0 10px;font-size:16px;line-height:24px;font-weight:bold;color:#20333c;word-break:break-word;">{{ $task['name'] }}</div>
                                                            <div style="font-size:12px;line-height:20px;color:#687c84;">
                                                                <span style="display:inline-block;padding:2px 8px;background:{{ $group['background'] }};color:{{ $group['color'] }};border-radius:5px;">{{ $task['role'] }}</span>
                                                                @if ($task['status'] !== '')
                                                                    <span style="padding-left:8px;">状态：{{ $task['status'] }}</span>
                                                                @endif
                                                                @if ($task['due'] !== '')
                                                                    <span style="padding-left:8px;">截止：{{ $task['due'] }}</span>
                                                                @endif
                                                            </div>
                                                        </td>
                                                    </tr>
                                                </table>
                                            </td>
                                        </tr>
                                    @endforeach
                                </table>
                            </td>
                        </tr>
                    @endif
                @endforeach
                <tr>
                    <td style="padding:16px 40px 34px;font-size:13px;line-height:22px;color:#667981;">请登录 {{ $systemName }} 查看任务详情并更新进度。</td>
                </tr>
                <tr>
                    <td style="padding:20px 40px;background:#f8fafb;border-top:1px solid #edf1f3;font-size:11px;line-height:18px;color:#8999a0;">
                        此邮件由 {{ $systemName }} 自动发送，请勿直接回复。任务状态以系统内实时数据为准。
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
