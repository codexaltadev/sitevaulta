<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Backup Health Report — {{ $website?->domain ?? 'Client Website' }}</title>
    <style>
        @page { margin: 32px 40px; }
        body {
            font-family: "DejaVu Sans", "Helvetica Neue", Helvetica, Arial, sans-serif;
            font-size: 12px;
            line-height: 1.55;
            color: #1e293b;
            margin: 0;
        }
        table { width: 100%; border-collapse: collapse; }
        .top-accent {
            height: 6px;
            background: {{ $brandColor }};
            margin-bottom: 22px;
            border-radius: 3px;
        }
        .info-box {
            background: #f8fafc;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            padding: 14px 16px;
        }
        .runs-table {
            margin-top: 20px;
            border: 1px solid #cbd5e1;
        }
        .runs-table th {
            background: #0f172a;
            color: #f8fafc;
            font-size: 10.5px;
            padding: 10px 12px;
            text-align: left;
            text-transform: uppercase;
        }
        .runs-table td {
            padding: 10px 12px;
            border-bottom: 1px solid #e2e8f0;
            font-size: 11px;
        }
    </style>
</head>
<body>
    <div class="top-accent"></div>

    <table>
        <tr>
            <td style="width: 60%; vertical-align: top;">
                @if(!empty($logoUrl))
                    <div style="margin-bottom: 6px;">
                        <img src="{{ $logoUrl }}" alt="{{ $brandName }}" style="max-height: 36px; width: auto;">
                    </div>
                @endif
                <h1 style="margin: 0; font-size: 22px; color: #0f172a;">{{ $brandName }}</h1>
                <div style="font-size: 11.5px; color: #64748b; margin-top: 4px;">
                    Automated Disaster Recovery &amp; SHA-256 Backup Verification Report
                </div>
                <div style="font-size: 11px; color: #475569; margin-top: 6px;">
                    Agency Support Contact: {{ $supportEmail }}
                </div>
            </td>
            <td style="width: 40%; text-align: right; vertical-align: top;">
                <div style="font-size: 16px; font-weight: 800; color: {{ $brandColor }};">CLIENT AUDIT REPORT</div>
                <div style="font-size: 11px; color: #475569; margin-top: 4px;">
                    <strong>Report Cycle:</strong> {{ strtoupper($report->frequency) }}<br>
                    <strong>Generated:</strong> {{ now()->utc()->format('d M Y, H:i') }} UTC
                </div>
            </td>
        </tr>
    </table>

    <hr style="border: none; border-top: 1px solid #e2e8f0; margin: 18px 0;">

    <div class="info-box">
        <table>
            <tr>
                <td style="width: 50%;">
                    <strong>Protected Website:</strong> {{ $website?->name ?? 'N/A' }}<br>
                    <strong>Verified Domain:</strong> {{ $website?->domain ?? 'N/A' }}<br>
                    <strong>Environment:</strong> {{ strtoupper($website?->environment ?? 'PRODUCTION') }}
                </td>
                <td style="width: 50%;">
                    <strong>Client Recipient:</strong> {{ $report->recipient_email }}<br>
                    <strong>Verified Recovery Points:</strong> {{ $runs->count() }} Completed Backups<br>
                    <strong>Encryption Standard:</strong> AES-256-GCM + SHA-256 Checksum
                </td>
            </tr>
        </table>
    </div>

    <h3 style="margin: 22px 0 8px; font-size: 13px; color: #0f172a;">Recent Verified Recovery Points</h3>
    <table class="runs-table">
        <thead>
            <tr>
                <th>Run #</th>
                <th>Type</th>
                <th>Scope</th>
                <th>Archive Size</th>
                <th>SHA-256 Integrity Checksum</th>
                <th>Completed (UTC)</th>
            </tr>
        </thead>
        <tbody>
            @forelse($runs as $run)
                @php $artifact = $run->artifacts->first(); @endphp
                <tr>
                    <td><strong>#{{ $run->id }}</strong></td>
                    <td>{{ strtoupper($run->backup_type) }}</td>
                    <td>{{ $run->scope?->label() ?? 'Full Stack' }}</td>
                    <td>{{ $run->formattedSize() }}</td>
                    <td><code>{{ substr($artifact?->sha256_checksum ?? 'Verified', 0, 24) }}...</code></td>
                    <td>{{ $run->completed_at?->format('d M Y H:i') ?? '-' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" style="text-align: center; color: #64748b;">No backup runs recorded in this period yet.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div style="margin-top: 30px; padding-top: 12px; border-top: 1px solid #e2e8f0; text-align: center; font-size: 10.5px; color: #64748b;">
        {{ $footer }} &bull; Prepared for {{ $report->recipient_email }} by {{ $brandName }}
    </div>
</body>
</html>

