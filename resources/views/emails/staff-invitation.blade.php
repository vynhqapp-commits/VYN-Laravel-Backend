<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Invitation</title>
</head>
<body style="margin:0;padding:0;background:#f8fafc;font-family:Arial,sans-serif;color:#0f172a;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="padding:24px;">
    <tr>
        <td align="center">
            <table role="presentation" width="600" cellspacing="0" cellpadding="0" style="max-width:600px;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e2e8f0;">
                <tr>
                    <td style="padding:24px;">
                        <h1 style="margin:0 0 12px;font-size:22px;line-height:1.3;">You're invited to join {{ $salonName }}</h1>
                        <p style="margin:0 0 16px;font-size:14px;line-height:1.6;">
                            @if(!empty($inviteeName))
                                Hi {{ $inviteeName }},
                            @else
                                Hello,
                            @endif
                            you've been invited to join <strong>{{ $salonName }}</strong> as a <strong>{{ ucfirst($role) }}</strong>.
                        </p>

                        @if(!empty($branchName))
                            <p style="margin:0 0 12px;font-size:14px;line-height:1.6;">
                                Assigned branch: <strong>{{ $branchName }}</strong>
                            </p>
                        @endif

                        @if(!empty($expiresAt))
                            <p style="margin:0 0 16px;font-size:14px;line-height:1.6;">
                                This invitation expires on <strong>{{ $expiresAt->toDayDateTimeString() }}</strong>.
                            </p>
                        @endif

                        <p style="margin:0 0 24px;">
                            <a href="{{ $inviteUrl }}" style="display:inline-block;background:#0f766e;color:#ffffff;text-decoration:none;padding:12px 20px;border-radius:8px;font-size:14px;font-weight:600;">
                                Accept invitation
                            </a>
                        </p>

                        <p style="margin:0 0 8px;font-size:13px;line-height:1.6;color:#475569;">
                            If the button does not work, copy and paste this link into your browser:
                        </p>
                        <p style="margin:0;font-size:12px;line-height:1.6;word-break:break-all;color:#0f766e;">
                            {{ $inviteUrl }}
                        </p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
