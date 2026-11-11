<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $heading }}</title>
</head>
<body style="margin:0;padding:24px 12px;background:#f4f6f8;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;color:#1f2933;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:12px;border:1px solid #e3e8ee;">
        <tr>
            <td style="padding:28px 28px 8px;">
                <p style="margin:0 0 6px;font-size:13px;color:#697386;">{{ $pageName }}</p>
                <h1 style="margin:0 0 14px;font-size:20px;line-height:1.3;">{{ $heading }}</h1>
                @foreach (preg_split("/\n+/", trim($body)) as $paragraph)
                    <p style="margin:0 0 12px;font-size:15px;line-height:1.6;">{{ $paragraph }}</p>
                @endforeach
                <p style="margin:20px 0 24px;">
                    <a href="{{ $actionUrl }}" style="display:inline-block;background:#0f9d8a;color:#ffffff;text-decoration:none;font-weight:600;font-size:14px;padding:10px 18px;border-radius:8px;">{{ $actionLabel }}</a>
                </p>
            </td>
        </tr>
        @if ($unsubscribeUrl)
            <tr>
                <td style="padding:14px 28px 22px;border-top:1px solid #e3e8ee;font-size:12px;color:#697386;">
                    You get these emails because you subscribed to the {{ $pageName }} status page.
                    <a href="{{ $unsubscribeUrl }}" style="color:#697386;">Unsubscribe</a>
                </td>
            </tr>
        @endif
    </table>
</body>
</html>
