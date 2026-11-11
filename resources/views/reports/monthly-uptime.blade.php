@php
    use App\Filament\App\Resources\Monitors\Pages\ViewMonitor;

    $brand = $workspace->report_brand_name ?: $workspace->name;
    $percent = fn (?float $value): string => $value === null ? '—' : ViewMonitor::formatPercent($value);
    $color = fn (?float $value): string => match (true) {
        $value === null => '#697386',
        $value >= 99.9 => '#047857',
        $value >= 99 => '#b45309',
        default => '#b91c1c',
    };
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $brand }} — uptime report {{ $month->format('F Y') }}</title>
</head>
<body style="margin:0;padding:24px 12px;background:#f4f6f8;font-family:DejaVu Sans,-apple-system,'Segoe UI',Roboto,Arial,sans-serif;color:#1f2933;font-size:13px;">
    <div style="max-width:720px;margin:0 auto;background:#ffffff;border:1px solid #e3e8ee;border-radius:12px;padding:28px;">
        <p style="margin:0;font-size:12px;color:#697386;">{{ $brand }}</p>
        <h1 style="margin:4px 0 2px;font-size:22px;">Uptime report — {{ $month->format('F Y') }}</h1>
        <p style="margin:0 0 20px;color:#697386;">{{ count($monitors) }} {{ str('monitor')->plural(count($monitors)) }} · {{ $month->format('M j') }} – {{ $month->endOfMonth()->format('M j, Y') }} (UTC)</p>

        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin-bottom:22px;">
            <tr>
                <td style="padding:12px;border:1px solid #e3e8ee;border-radius:8px;width:33%;">
                    <div style="font-size:22px;font-weight:700;color:{{ $color($overall_uptime_percent) }};">{{ $percent($overall_uptime_percent) }}</div>
                    <div style="color:#697386;">Overall uptime</div>
                </td>
                <td style="padding:12px;border:1px solid #e3e8ee;border-radius:8px;width:33%;">
                    <div style="font-size:22px;font-weight:700;">{{ $total_incidents }}</div>
                    <div style="color:#697386;">{{ str('Incident')->plural($total_incidents) }}</div>
                </td>
                <td style="padding:12px;border:1px solid #e3e8ee;border-radius:8px;width:33%;">
                    <div style="font-size:22px;font-weight:700;">{{ ViewMonitor::formatDuration($total_downtime_seconds) }}</div>
                    <div style="color:#697386;">Total downtime</div>
                </td>
            </tr>
        </table>

        <table width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;">
            <thead>
                <tr style="text-align:left;color:#697386;font-size:11px;text-transform:uppercase;">
                    <th style="padding:8px 6px;border-bottom:1px solid #e3e8ee;text-align:left;">Monitor</th>
                    <th style="padding:8px 6px;border-bottom:1px solid #e3e8ee;text-align:left;">Uptime</th>
                    <th style="padding:8px 6px;border-bottom:1px solid #e3e8ee;text-align:left;">Incidents</th>
                    <th style="padding:8px 6px;border-bottom:1px solid #e3e8ee;text-align:left;">Downtime</th>
                    <th style="padding:8px 6px;border-bottom:1px solid #e3e8ee;text-align:left;">Avg response</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($monitors as $row)
                    <tr>
                        <td style="padding:10px 6px;border-bottom:1px solid #eef1f4;">
                            <strong>{{ $row['monitor']->name }}</strong>
                            @foreach ($row['expiries'] as $expiry)
                                <div style="font-size:11px;color:#697386;">{{ $expiry['label'] }}: {{ $expiry['date'] ?? 'unknown' }}</div>
                            @endforeach
                        </td>
                        <td style="padding:10px 6px;border-bottom:1px solid #eef1f4;font-weight:700;color:{{ $color($row['uptime_percent']) }};">{{ $percent($row['uptime_percent']) }}</td>
                        <td style="padding:10px 6px;border-bottom:1px solid #eef1f4;">{{ $row['incidents'] }}</td>
                        <td style="padding:10px 6px;border-bottom:1px solid #eef1f4;">
                            {{ $row['downtime_seconds'] > 0 ? ViewMonitor::formatDuration($row['downtime_seconds']) : '—' }}
                            @if ($row['longest_outage_seconds'] > 0 && $row['incidents'] > 1)
                                <div style="font-size:11px;color:#697386;">longest {{ ViewMonitor::formatDuration($row['longest_outage_seconds']) }}</div>
                            @endif
                        </td>
                        <td style="padding:10px 6px;border-bottom:1px solid #eef1f4;">{{ $row['average_response_ms'] !== null ? $row['average_response_ms'].' ms' : '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" style="padding:16px 6px;color:#697386;">No monitors were active this month.</td></tr>
                @endforelse
            </tbody>
        </table>

        @isset($dashboardUrl)
            <p style="margin:24px 0 0;">
                <a href="{{ $dashboardUrl }}" style="display:inline-block;background:#0f9d8a;color:#ffffff;text-decoration:none;font-weight:600;padding:10px 18px;border-radius:8px;">Open monitors</a>
            </p>
        @endisset

        <p style="margin:24px 0 0;font-size:11px;color:#697386;">{{ $workspace->report_footer_note ?: 'Generated by '.config('app.name', 'SiteVaulta').'.' }}</p>
    </div>
</body>
</html>
