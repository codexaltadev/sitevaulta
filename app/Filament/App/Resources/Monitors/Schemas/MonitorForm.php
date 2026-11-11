<?php

namespace App\Filament\App\Resources\Monitors\Schemas;

use App\Enums\MonitorType;
use App\Filament\App\Resources\NotificationChannels\NotificationChannelResource;
use App\Filament\App\Resources\VerifiedDomains\VerifiedDomainResource;
use App\Models\Monitor;
use App\Models\Workspace;
use App\Rules\AllowedMonitorTypes;
use App\Rules\OwnedMonitorHost;
use App\Rules\PublicMonitorDestination;
use App\Rules\UniqueWorkspaceTarget;
use App\Services\Billing\EntitlementService;
use App\Services\Monitoring\HostOwnershipVerifier;
use App\Services\Monitoring\MonitorChildChecksSync;
use App\Services\Monitoring\RegistrableDomain;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Slider;
use Filament\Forms\Components\Slider\Enums\PipsMode;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;
use Filament\Support\RawJs;
use Illuminate\Support\HtmlString;

/**
 * UptimeRobot-style monitor editor: type tiles, URL/host, interval buttons, alert rules and a
 * collapsed "Advanced settings" section on the left; a sticky live summary on the right.
 */
class MonitorForm
{
    /**
     * @var array<int, string>
     */
    public const INTERVAL_OPTIONS = [
        60 => 'Every 1 minute',
        120 => 'Every 2 minutes',
        300 => 'Every 5 minutes',
        600 => 'Every 10 minutes',
        1800 => 'Every 30 minutes',
        3600 => 'Every 1 hour',
        21600 => 'Every 6 hours',
        86400 => 'Every day',
    ];

    /**
     * @var array<int, string>
     */
    public const INTERVAL_SHORT_LABELS = [
        60 => '1m',
        120 => '2m',
        300 => '5m',
        600 => '10m',
        1800 => '30m',
        3600 => '1h',
        21600 => '6h',
        86400 => '24h',
    ];

    public const PORT_MIN_INTERVAL_SECONDS = 300;

    /** BTCL's public .bd domain lookup (for people; it is behind a CAPTCHA, so it is never queried automatically). */
    public const BTCL_DOMAIN_LOOKUP_URL = 'https://bdia.btcl.com.bd/';

    /**
     * @var array<int, string>
     */
    public const HEARTBEAT_GRACE_OPTIONS = [
        60 => '1 minute',
        300 => '5 minutes',
        900 => '15 minutes',
        1800 => '30 minutes',
        3600 => '1 hour',
    ];

    /**
     * @var array<int, string>
     */
    public const ALERT_DELAY_OPTIONS = [
        0 => 'Immediately',
        2 => 'After 2 minutes down',
        5 => 'After 5 minutes down',
        10 => 'After 10 minutes down',
        15 => 'After 15 minutes down',
        30 => 'After 30 minutes down',
        60 => 'After 1 hour down',
    ];

    /**
     * @var array<int, string>
     */
    public const ALERT_REPEAT_OPTIONS = [
        0 => 'Never (alert once)',
        5 => 'Every 5 minutes',
        15 => 'Every 15 minutes',
        30 => 'Every 30 minutes',
        60 => 'Every hour',
        180 => 'Every 3 hours',
        1440 => 'Every day',
    ];

    /**
     * @var array<string, string>
     */
    public const DNS_RECORD_TYPES = [
        'A' => 'A (IPv4 address)',
        'AAAA' => 'AAAA (IPv6 address)',
        'CNAME' => 'CNAME (alias)',
        'MX' => 'MX (mail server)',
        'NS' => 'NS (name server)',
        'TXT' => 'TXT (text, e.g. SPF/DMARC)',
    ];

    /**
     * @var array<string, string>
     */
    private const EXTRA_CHECK_LABELS = [
        'ping' => 'Ping',
        'ssl' => 'SSL certificate expiry',
        'domain' => 'Domain expiry',
        'dns' => 'DNS record',
        'port' => 'Server ports',
    ];

    /**
     * @var array<string, string>
     */
    private const EXTRA_CHECK_DESCRIPTIONS = [
        'ping' => 'Server reachability and latency',
        'ssl' => 'Alert before the certificate expires',
        'domain' => 'Alert before the registration expires',
        'dns' => 'Alert if the record disappears or changes',
        'port' => 'SSH, MySQL, SMTP… open — needs verified ownership',
    ];

    /**
     * @var array<string, string>
     */
    private const TYPE_TILE_LABELS = [
        'http' => 'HTTP(s)',
        'keyword' => 'Keyword',
        'ping' => 'Ping',
        'port' => 'Port',
        'heartbeat' => 'Heartbeat',
        'dns' => 'DNS',
        'ssl' => 'SSL expiry',
        'domain' => 'Domain expiry',
    ];

    /**
     * @var array<string, string>
     */
    private const TYPE_DESCRIPTIONS = [
        'http' => 'Checks that a website or API answers with a 2xx/3xx status code.',
        'keyword' => 'Checks that a page contains (or does not contain) a word — catches blank pages and error screens that still return 200.',
        'ping' => 'Sends an ICMP ping to a server or IP to confirm it is reachable.',
        'port' => 'Checks that a TCP service (SSH, MySQL, SMTP…) accepts connections.',
        'heartbeat' => 'Your cron job or script calls a unique URL; we alert when the call stops arriving.',
        'dns' => 'Watches a DNS record and alerts if it disappears or changes.',
        'ssl' => 'Alerts before the TLS certificate expires or when it is invalid.',
        'domain' => 'Alerts before the domain registration expires (via RDAP).',
    ];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(['default' => 1, 'lg' => 12])
                    ->columnSpanFull()
                    ->extraAttributes(['class' => 'sv-compact-form'])
                    ->schema([
                        Group::make([
                            self::monitorSection(),
                            self::extraChecksSection(),
                            self::intervalSection(),
                            self::alertsSection(),
                            self::advancedSection(),
                        ])->columnSpan(['default' => 1, 'lg' => 8]),

                        self::summarySection()->columnSpan(['default' => 1, 'lg' => 4]),
                    ]),
            ]);
    }

    private static function monitorSection(): Section
    {
        return Section::make('Monitor')
            ->compact()
            ->icon(Heroicon::OutlinedSignal)
            ->columns(2)
            ->schema([
                ToggleButtons::make('type')
                    ->label('Monitor type')
                    ->options(fn (?Monitor $record): array => collect(self::TYPE_TILE_LABELS)
                        ->map(fn (string $label, string $type): string => self::typeIsSelectable($type, $record) ? $label : "🔒 {$label}")
                        ->all())
                    ->disableOptionWhen(fn (mixed $value, ?Monitor $record): bool => ! self::typeIsSelectable((string) $value, $record))
                    ->hint(fn (?Monitor $record): ?HtmlString => $record === null
                        && in_array(MonitorType::Port->value, self::allowedTypes($record), true)
                        && self::ownedHostNames() === []
                            ? self::verifiedDomainsLink('Port checks unlock after you verify a domain you own.')
                            : null)
                    ->rules(fn (?Monitor $record): array => [new AllowedMonitorTypes(self::allowedTypes($record))])
                    ->icons([
                        'http' => Heroicon::OutlinedGlobeAlt,
                        'keyword' => Heroicon::OutlinedDocumentMagnifyingGlass,
                        'ping' => Heroicon::OutlinedSignal,
                        'port' => Heroicon::OutlinedServerStack,
                        'heartbeat' => Heroicon::OutlinedHeart,
                        'dns' => Heroicon::OutlinedCircleStack,
                        'ssl' => Heroicon::OutlinedLockClosed,
                        'domain' => Heroicon::OutlinedCalendarDays,
                    ])
                    ->columns(['default' => 2, 'sm' => 4])
                    ->default(MonitorType::Http->value)
                    ->helperText(fn (Get $get): string => self::TYPE_DESCRIPTIONS[self::selectedType($get)->value])
                    ->live()
                    ->afterStateUpdated(function (?string $state, Set $set): void {
                        $type = MonitorType::tryFrom((string) $state);
                        $interval = $type?->isExpiryCheck() ? 86400 : 300;
                        $set('interval_seconds', $interval);
                        $set('interval_step', self::intervalStepFor($interval));
                    })
                    // The type is chosen once, at creation. Hidden fields keep their state but are not
                    // saved, so an existing monitor (and its extra checks) can never switch type.
                    ->hidden(fn (?Monitor $record): bool => $record !== null)
                    ->required()
                    ->columnSpanFull(),

                TextEntry::make('type_locked')
                    ->label('Monitor type')
                    ->state(fn (?Monitor $record): string => $record?->type->label() ?? '')
                    ->icon(fn (?Monitor $record): ?Heroicon => match ($record?->type) {
                        MonitorType::Keyword => Heroicon::OutlinedDocumentMagnifyingGlass,
                        MonitorType::Ping => Heroicon::OutlinedSignal,
                        MonitorType::Port => Heroicon::OutlinedServerStack,
                        MonitorType::Heartbeat => Heroicon::OutlinedHeart,
                        MonitorType::Dns => Heroicon::OutlinedCircleStack,
                        MonitorType::Ssl => Heroicon::OutlinedLockClosed,
                        MonitorType::Domain => Heroicon::OutlinedCalendarDays,
                        default => Heroicon::OutlinedGlobeAlt,
                    })
                    ->badge()
                    ->color('primary')
                    ->helperText('The type is fixed after creation. To watch this target another way, tick an extra check below or create a new monitor.')
                    ->visible(fn (?Monitor $record): bool => $record !== null)
                    ->columnSpanFull(),

                TextInput::make('url')
                    ->label('URL to monitor')
                    ->placeholder('https://example.com/')
                    ->prefixIcon(Heroicon::OutlinedLink)
                    ->url()
                    ->rule(new PublicMonitorDestination(isUrl: true))
                    ->rule(fn (?Monitor $record): UniqueWorkspaceTarget => new UniqueWorkspaceTarget(self::currentWorkspace(), UniqueWorkspaceTarget::MONITORS, $record?->id))
                    ->maxLength(500)
                    ->required(fn (Get $get): bool => self::selectedType($get)->usesUrl())
                    ->visible(fn (Get $get): bool => self::selectedType($get)->usesUrl())
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (?string $state, Get $get, Set $set): void {
                        if (blank($get('name')) && filled($state)) {
                            $set('name', parse_url($state, PHP_URL_HOST) ?: $state);
                        }
                    })
                    ->columnSpanFull(),

                TextInput::make('host')
                    ->label(fn (Get $get): string => match (self::selectedType($get)) {
                        MonitorType::Dns, MonitorType::Domain => 'Domain',
                        MonitorType::Ssl => 'Hostname (certificate to check)',
                        default => 'Hostname or IP',
                    })
                    ->placeholder(fn (Get $get): string => match (self::selectedType($get)) {
                        MonitorType::Dns => '_dmarc.example.com',
                        MonitorType::Domain, MonitorType::Ssl => 'example.com',
                        default => 'server.example.com',
                    })
                    ->prefixIcon(Heroicon::OutlinedServer)
                    ->regex('/^[A-Za-z0-9_][A-Za-z0-9_.:\-]*$/')
                    ->validationMessages(['regex' => 'Enter a hostname or IP only — no scheme, path or spaces.'])
                    // DNS and Domain-expiry checks only query public registries/resolvers, never the host itself.
                    ->rule(new PublicMonitorDestination, fn (Get $get): bool => ! in_array(self::selectedType($get), [MonitorType::Dns, MonitorType::Domain], true))
                    ->rule(fn (?Monitor $record): UniqueWorkspaceTarget => new UniqueWorkspaceTarget(self::currentWorkspace(), UniqueWorkspaceTarget::MONITORS, $record?->id))
                    // Port monitoring is a scan, so it needs proof that the workspace owns the host.
                    ->rule(fn (): OwnedMonitorHost => new OwnedMonitorHost(self::currentWorkspace()), fn (Get $get): bool => self::selectedType($get) === MonitorType::Port)
                    ->datalist(fn (Get $get): array => self::selectedType($get) === MonitorType::Port ? self::ownedHostNames() : [])
                    ->hint(fn (Get $get): ?string => self::selectedType($get) === MonitorType::Port && filled($get('host'))
                        ? (self::hostIsOwned($get('host')) ? '✓ Verified owner' : '✗ Ownership not verified')
                        : null)
                    ->hintColor(fn (Get $get): string => self::selectedType($get) === MonitorType::Port && self::hostIsOwned($get('host')) ? 'success' : 'danger')
                    ->helperText(fn (Get $get): HtmlString|string|null => match (true) {
                        self::selectedType($get) !== MonitorType::Port => null,
                        filled($get('host')) && ! self::hostIsOwned($get('host')) => self::verifiedDomainsLink('Port checks only run on hosts you have proven you own.'),
                        default => 'Pick one of your verified domains or servers.',
                    })
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (?string $state, Get $get, Set $set): void {
                        if (blank($get('name')) && filled($state)) {
                            $set('name', $state);
                        }
                    })
                    ->required(fn (Get $get): bool => self::selectedType($get)->usesHost())
                    ->visible(fn (Get $get): bool => self::selectedType($get)->usesHost()),

                TextInput::make('port')
                    ->label('Port')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(65535)
                    ->placeholder('443')
                    ->visible(fn (Get $get): bool => self::selectedType($get) === MonitorType::Ssl),

                self::portsInput('ports')
                    ->required(fn (Get $get): bool => self::selectedType($get) === MonitorType::Port)
                    ->visible(fn (Get $get): bool => self::selectedType($get) === MonitorType::Port)
                    // Ports can only be entered once the host is proven to be the workspace's own.
                    ->disabled(fn (Get $get): bool => ! self::hostIsOwned($get('host')))
                    ->columnSpanFull(),

                self::dnsRecordTypesInput('dns_record_types')
                    ->required(fn (Get $get): bool => self::selectedType($get) === MonitorType::Dns)
                    ->visible(fn (Get $get): bool => self::selectedType($get) === MonitorType::Dns)
                    ->columnSpanFull(),

                TextInput::make('expiry_threshold_days')
                    ->label('Alert when it expires within')
                    ->suffix('days')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(90)
                    ->default(14)
                    ->required(fn (Get $get): bool => self::selectedType($get)->isExpiryCheck())
                    ->visible(fn (Get $get): bool => self::selectedType($get)->isExpiryCheck()),

                TextEntry::make('detected_expiry')
                    ->label(fn (?Monitor $record): string => $record?->type === MonitorType::Ssl ? 'Certificate expires' : 'Domain expires')
                    ->state(fn (?Monitor $record): ?string => self::detectedExpiryText($record))
                    ->icon(Heroicon::OutlinedCalendarDays)
                    ->visible(fn (?Monitor $record): bool => (bool) $record?->type->isExpiryCheck())
                    ->columnSpanFull(),

                DatePicker::make('manual_expires_at')
                    ->label('Expiry date (manual, optional)')
                    ->helperText(fn (Get $get): HtmlString|string => self::manualExpiryHelp($get('host')))
                    ->native(false)
                    ->visible(fn (Get $get): bool => self::selectedType($get) === MonitorType::Domain),

                TextInput::make('keyword')
                    ->label('Keyword')
                    ->placeholder('e.g. Add to cart')
                    ->maxLength(255)
                    ->required(fn (Get $get): bool => self::selectedType($get) === MonitorType::Keyword)
                    ->visible(fn (Get $get): bool => self::selectedType($get) === MonitorType::Keyword),

                ToggleButtons::make('keyword_should_exist')
                    ->label('Alert when the keyword is')
                    ->options([1 => 'Missing', 0 => 'Present'])
                    ->icons([1 => Heroicon::OutlinedEyeSlash, 0 => Heroicon::OutlinedEye])
                    ->inline()
                    ->grouped()
                    ->default(1)
                    ->visible(fn (Get $get): bool => self::selectedType($get) === MonitorType::Keyword),

                Select::make('heartbeat_grace_seconds')
                    ->label('Grace period')
                    ->helperText('How late a heartbeat may arrive before we alert.')
                    ->options(self::HEARTBEAT_GRACE_OPTIONS)
                    ->default(300)
                    ->native(false)
                    ->required(fn (Get $get): bool => self::selectedType($get) === MonitorType::Heartbeat)
                    ->visible(fn (Get $get): bool => self::selectedType($get) === MonitorType::Heartbeat),

                TextEntry::make('heartbeat_url_hint')
                    ->label('Heartbeat URL')
                    ->state(fn (?Monitor $record): string => $record?->heartbeatUrl()
                        ?? 'Your unique URL appears on the monitor page after you save.')
                    ->copyable(fn (?Monitor $record): bool => $record?->heartbeatUrl() !== null)
                    ->visible(fn (Get $get): bool => self::selectedType($get) === MonitorType::Heartbeat)
                    ->columnSpanFull(),

                TextInput::make('name')
                    ->label('Friendly name')
                    ->placeholder('Filled in from the URL — change it if you like')
                    ->required()
                    ->maxLength(150)
                    ->columnSpanFull(),
            ]);
    }

    /**
     * Tick Ping / SSL / Domain / DNS to watch the same target in one go; a tab opens only for
     * checks that need their own input. Each ticked check becomes a child monitor.
     */
    private static function extraChecksSection(): Section
    {
        return Section::make('Extra checks for this target')
            ->compact()
            ->icon(Heroicon::OutlinedSquaresPlus)
            ->description(fn (Get $get): string => 'Watch more than uptime for '
                .(self::extraChecksHost($get) ?? 'this target')
                .' — everything is reported together on the monitor page. Each extra check counts as one monitor in your plan.')
            ->visible(fn (Get $get, ?Monitor $record): bool => MonitorChildChecksSync::canOwnExtraChecks($record)
                && MonitorChildChecksSync::childTypesFor(self::selectedType($get)) !== [])
            ->schema([
                CheckboxList::make('extra_checks')
                    ->hiddenLabel()
                    ->options(fn (Get $get, ?Monitor $record): array => collect(MonitorChildChecksSync::childTypesFor(self::selectedType($get)))
                        ->mapWithKeys(fn (string $type): array => [
                            $type => (self::extraCheckIsSelectable($type, $get, $record) ? '' : '🔒 ').self::EXTRA_CHECK_LABELS[$type],
                        ])
                        ->all())
                    ->descriptions(fn (Get $get): array => [
                        ...self::EXTRA_CHECK_DESCRIPTIONS,
                        ...(self::portExtraCheckIsBlocked($get)
                            ? ['port' => self::verifiedDomainsLink('Locked: '.(self::extraChecksHost($get) ?? 'this host').' is not verified as yours.')]
                            : []),
                    ])
                    // A port check that is already ticked stays untickable, so a revoked host can still be removed.
                    ->disableOptionWhen(fn (mixed $value, Get $get, ?Monitor $record): bool => ! self::extraCheckIsSelectable((string) $value, $get, $record)
                        && ! in_array((string) $value, (array) $get('extra_checks'), true))
                    ->rules(fn (?Monitor $record): array => [new AllowedMonitorTypes(self::allowedExtraTypes($record))])
                    ->columns(['default' => 1, 'sm' => 2])
                    ->live(),

                Tabs::make('extra_check_settings')
                    ->visible(fn (Get $get): bool => array_intersect((array) $get('extra_checks'), ['ssl', 'domain', 'dns', 'port']) !== [])
                    ->tabs([
                        Tab::make('SSL certificate')
                            ->icon(Heroicon::OutlinedLockClosed)
                            ->visible(fn (Get $get): bool => in_array('ssl', (array) $get('extra_checks'), true))
                            ->columns(2)
                            ->schema([
                                TextEntry::make('extras_ssl_detected')
                                    ->label('Certificate expires')
                                    ->state(fn (?Monitor $record): ?string => self::detectedExpiryText(self::childCheck($record, MonitorType::Ssl)))
                                    ->icon(Heroicon::OutlinedLockClosed)
                                    ->visible(fn (?Monitor $record): bool => self::childCheck($record, MonitorType::Ssl) !== null)
                                    ->columnSpanFull(),
                                TextInput::make('extras.ssl.expiry_threshold_days')
                                    ->label('Alert when it expires within')
                                    ->suffix('days')
                                    ->numeric()
                                    ->minValue(1)
                                    ->maxValue(90)
                                    ->default(14),
                                TextInput::make('extras.ssl.port')
                                    ->label('Port')
                                    ->numeric()
                                    ->minValue(1)
                                    ->maxValue(65535)
                                    ->placeholder('443'),
                            ]),
                        Tab::make('Domain expiry')
                            ->icon(Heroicon::OutlinedCalendarDays)
                            ->visible(fn (Get $get): bool => in_array('domain', (array) $get('extra_checks'), true))
                            ->columns(2)
                            ->schema([
                                TextEntry::make('extras_domain_detected')
                                    ->label('Domain expires')
                                    ->state(fn (?Monitor $record): ?string => self::detectedExpiryText(self::childCheck($record, MonitorType::Domain)))
                                    ->icon(Heroicon::OutlinedCalendarDays)
                                    ->visible(fn (?Monitor $record): bool => self::childCheck($record, MonitorType::Domain) !== null)
                                    ->columnSpanFull(),
                                TextInput::make('extras.domain.host')
                                    ->label('Registered domain')
                                    ->placeholder(fn (Get $get): string => MonitorChildChecksSync::suggestedDomainFor(self::extraChecksHost($get)) ?? 'example.com')
                                    ->helperText('For a sub-domain, enter the domain you pay the registrar for (e.g. daffodilvarsity.edu.bd).')
                                    ->regex('/^[A-Za-z0-9][A-Za-z0-9.\-]*$/')
                                    ->maxLength(255),
                                TextInput::make('extras.domain.expiry_threshold_days')
                                    ->label('Alert when it expires within')
                                    ->suffix('days')
                                    ->numeric()
                                    ->minValue(1)
                                    ->maxValue(90)
                                    ->default(30),
                                DatePicker::make('extras.domain.manual_expires_at')
                                    ->label('Expiry date (manual, optional)')
                                    ->helperText(fn (Get $get): HtmlString|string => self::manualExpiryHelp($get('extras.domain.host') ?: self::extraChecksHost($get)))
                                    ->native(false)
                                    ->columnSpanFull(),
                            ]),
                        Tab::make('Server ports')
                            ->icon(Heroicon::OutlinedServerStack)
                            ->visible(fn (Get $get): bool => in_array('port', (array) $get('extra_checks'), true))
                            ->schema([
                                self::portsInput('extras.port.ports')
                                    ->required(fn (Get $get): bool => in_array('port', (array) $get('extra_checks'), true))
                                    // The scan targets this monitor's host, so the workspace must own it.
                                    ->rule(
                                        fn (Get $get): OwnedMonitorHost => new OwnedMonitorHost(self::currentWorkspace(), self::extraChecksHost($get)),
                                        fn (Get $get): bool => in_array('port', (array) $get('extra_checks'), true),
                                    ),
                            ]),
                        Tab::make('DNS record')
                            ->icon(Heroicon::OutlinedCircleStack)
                            ->visible(fn (Get $get): bool => in_array('dns', (array) $get('extra_checks'), true))
                            ->columns(2)
                            ->schema([
                                self::dnsRecordTypesInput('extras.dns.dns_record_types')
                                    ->required(fn (Get $get): bool => in_array('dns', (array) $get('extra_checks'), true))
                                    ->columnSpanFull(),
                            ]),
                    ]),
            ]);
    }

    private static function intervalSection(): Section
    {
        return Section::make('Monitor interval')
            ->icon(Heroicon::OutlinedClock)
            ->description(fn (Get $get): HtmlString => new HtmlString(
                'Checked every <strong>'.strtolower(self::INTERVAL_OPTIONS[(int) $get('interval_seconds')] ?? '5 minutes').'</strong>.'
                .(self::minimumIntervalSeconds() > 60 ? ' 🔒 Faster checks are available on higher plans.' : '')
            ))
            ->compact()
            ->schema([
                // The real value; validated server-side against the plan's allowed intervals.
                Hidden::make('interval_seconds')
                    ->default(300)
                    ->required()
                    ->rules(fn (Get $get): array => [
                        'integer',
                        'in:'.implode(',', array_keys(self::allowedIntervalOptions())),
                        // Port monitoring is a scan; keep it to at most once every 5 minutes.
                        ...(self::selectedType($get) === MonitorType::Port ? ['min:'.self::PORT_MIN_INTERVAL_SECONDS] : []),
                    ])
                    ->validationMessages([
                        'in' => 'That interval is not included in your current plan.',
                        'min' => 'Port checks run at most every 5 minutes.',
                    ]),

                // Drag-to-choose slider over the interval steps. Steps faster than the plan allows sit in
                // the padded (locked) part of the track, so the handle cannot be dragged into them.
                Slider::make('interval_step')
                    ->hiddenLabel()
                    ->range(0, count(self::INTERVAL_SHORT_LABELS) - 1)
                    ->step(1)
                    ->decimalPlaces(0)
                    ->rangePadding(fn (): array => [self::lockedIntervalStepCount(), 0])
                    ->tooltips(RawJs::make(self::intervalLabelJs()))
                    ->pips(PipsMode::Steps)
                    ->pipsFormatter(RawJs::make(self::intervalLabelJs(withLocks: true)))
                    ->dehydrated(false)
                    ->live()
                    ->afterStateHydrated(function (Slider $component, Get $get): void {
                        $component->state(self::intervalStepFor((int) ($get('interval_seconds') ?: 300)));
                    })
                    ->afterStateUpdated(function (mixed $state, Set $set): void {
                        $steps = array_keys(self::INTERVAL_SHORT_LABELS);
                        $set('interval_seconds', $steps[(int) round((float) $state)] ?? 300);
                    }),
            ]);
    }

    public static function intervalStepFor(int $seconds): int
    {
        $position = array_search($seconds, array_keys(self::INTERVAL_SHORT_LABELS), true);

        return $position === false ? 2 : $position;
    }

    private static function lockedIntervalStepCount(): int
    {
        return count(array_filter(
            array_keys(self::INTERVAL_SHORT_LABELS),
            fn (int $seconds): bool => $seconds < self::minimumIntervalSeconds(),
        ));
    }

    /**
     * JS expression mapping the slider's step index ($value) to a label such as "5m" (or "🔒 1m").
     */
    private static function intervalLabelJs(bool $withLocks = false): string
    {
        $labels = collect(self::INTERVAL_SHORT_LABELS)
            ->map(fn (string $label, int $seconds): string => $withLocks && $seconds < self::minimumIntervalSeconds() ? "🔒 {$label}" : $label)
            ->values()
            ->all();

        return json_encode($labels, JSON_UNESCAPED_UNICODE).'[Math.round($value)]';
    }

    private static function alertsSection(): Section
    {
        return Section::make('Notifications and alerts')
            ->compact()
            ->icon(Heroicon::OutlinedBellAlert)
            ->description('Who hears about an outage, and when. If the monitor recovers before the delay, nobody is alerted.')
            ->columns(2)
            ->schema([
                ToggleButtons::make('alert_delay_minutes')
                    ->label('Send the down alert')
                    ->options([0 => 'Now', 2 => '2m', 5 => '5m', 10 => '10m', 15 => '15m', 30 => '30m', 60 => '1h'])
                    ->inline()
                    ->grouped()
                    ->default(0)
                    ->live()
                    ->required()
                    ->columnSpanFull(),

                ToggleButtons::make('alert_repeat_minutes')
                    ->label('While still down, remind every')
                    ->options([0 => 'Never', 5 => '5m', 15 => '15m', 30 => '30m', 60 => '1h', 180 => '3h', 1440 => '24h'])
                    ->inline()
                    ->grouped()
                    ->default(0)
                    ->live()
                    ->required()
                    ->columnSpanFull(),

                // Slow-site alert: the site is up, just slow — no incident, an alert and a "back to normal".
                Grid::make(['default' => 1, 'sm' => 2])
                    ->visible(fn (Get $get): bool => self::selectedType($get)->usesUrl())
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('slow_threshold_ms')
                            ->label('🐢 Alert when slower than')
                            ->placeholder('Off')
                            ->suffix('ms')
                            ->numeric()
                            ->integer()
                            ->minValue(100)
                            ->maxValue(60000)
                            ->helperText('Leave empty to turn off. A slow site is still up: no incident, no uptime loss.'),
                        ToggleButtons::make('slow_alert_after_checks')
                            ->label('For this many checks in a row')
                            ->options([1 => '1', 2 => '2', 3 => '3', 5 => '5'])
                            ->inline()
                            ->grouped()
                            ->default(3)
                            ->required()
                            ->helperText('So a single slow moment does not alert.'),
                    ]),

                Toggle::make('notify_workspace_members')
                    ->label('🔔 In-app bell for workspace members')
                    ->default(true)
                    ->live()
                    ->columnSpanFull(),

                CheckboxList::make('alert_channel_ids')
                    ->label('Alert channels')
                    ->options(fn (): array => self::workspaceAlertChannelOptions())
                    ->helperText('Leave all unticked to use every active channel.')
                    ->columns(2)
                    ->live()
                    ->visible(fn (): bool => self::workspaceAlertChannelOptions() !== [])
                    ->columnSpanFull(),

                TextEntry::make('no_channels_hint')
                    ->hiddenLabel()
                    ->state('No alert channels yet. Add Slack, Telegram, Discord, email or a webhook to get alerts outside SiteVaulta.')
                    ->visible(fn (): bool => self::workspaceAlertChannelOptions() === [])
                    ->columnSpanFull(),

                Actions::make([
                    Action::make('addAlertChannel')
                        ->label('Add alert channel')
                        ->icon(Heroicon::OutlinedPlus)
                        ->color('gray')
                        ->size(Size::Small)
                        ->url(fn (): string => NotificationChannelResource::getUrl('create'), shouldOpenInNewTab: true),
                ])->visible(fn (): bool => self::workspaceAlertChannelOptions() === []),
            ]);
    }

    private static function advancedSection(): Section
    {
        return Section::make('Advanced settings')
            ->compact()
            ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
            ->description('Timeout, HTTP method, redirects and matching options.')
            ->collapsed()
            ->columns(2)
            ->schema([
                Slider::make('timeout_seconds')
                    ->label('Request timeout')
                    ->helperText('The shorter the timeout, the sooner a slow server counts as down.')
                    ->range(1, 60)
                    ->step(1)
                    ->decimalPlaces(0)
                    ->default(30)
                    ->tooltips(RawJs::make('`${Math.round($value)}s`'))
                    ->pips(PipsMode::Values)
                    ->pipsValues([1, 15, 30, 45, 60])
                    ->pipsFormatter(RawJs::make('`${Math.round($value)}s`'))
                    ->required()
                    ->visible(fn (Get $get): bool => ! self::selectedType($get)->isPassive())
                    ->columnSpanFull(),

                Select::make('http_method')
                    ->label('HTTP method')
                    ->options(['GET' => 'GET', 'HEAD' => 'HEAD', 'POST' => 'POST'])
                    ->default('GET')
                    ->native(false)
                    ->visible(fn (Get $get): bool => self::selectedType($get)->usesUrl()),

                Toggle::make('follow_redirects')
                    ->label('Follow redirects')
                    ->helperText('Turn off to treat 3xx responses as the final status.')
                    ->default(true)
                    ->visible(fn (Get $get): bool => self::selectedType($get)->usesUrl()),

                Toggle::make('keyword_case_sensitive')
                    ->label('Case-sensitive keyword match')
                    ->visible(fn (Get $get): bool => self::selectedType($get) === MonitorType::Keyword),

                Toggle::make('ping_strict_icmp')
                    ->label('Strict ICMP only')
                    ->helperText('Off (recommended): if the server blocks ping, reachability is measured with a TCP connect on port 443/80 instead of reporting a false outage. On: no ping reply = down.')
                    ->visible(fn (Get $get): bool => self::selectedType($get) === MonitorType::Ping)
                    ->columnSpanFull(),
            ]);
    }

    private static function summarySection(): Section
    {
        return Section::make('Summary')
            ->compact()
            ->icon(Heroicon::OutlinedClipboardDocumentCheck)
            ->extraAttributes(['style' => 'position: sticky; top: 5rem; align-self: start;'])
            ->schema([
                TextEntry::make('summary_what')
                    ->label('What')
                    ->state(fn (Get $get): string => self::selectedType($get)->label().' — '.(self::summaryTarget($get) ?: 'not set yet')),
                TextEntry::make('summary_extras')
                    ->label('Extra checks')
                    ->state(fn (Get $get): string => collect((array) $get('extra_checks'))
                        ->map(fn (string $type): string => self::EXTRA_CHECK_LABELS[$type] ?? $type)
                        ->implode(', ') ?: 'none')
                    ->visible(fn (Get $get): bool => MonitorChildChecksSync::childTypesFor(self::selectedType($get)) !== []),
                TextEntry::make('summary_how_often')
                    ->label('How often')
                    ->state(fn (Get $get): string => self::INTERVAL_OPTIONS[(int) $get('interval_seconds')] ?? '—'),
                TextEntry::make('summary_when')
                    ->label('When it goes down')
                    ->state(fn (Get $get): string => (self::ALERT_DELAY_OPTIONS[(int) $get('alert_delay_minutes')] ?? '—')
                        .' · reminders: '.strtolower(self::ALERT_REPEAT_OPTIONS[(int) $get('alert_repeat_minutes')] ?? 'never')),
                TextEntry::make('summary_who')
                    ->label('Who is told')
                    ->state(fn (Get $get): string => collect([
                        $get('notify_workspace_members') ? 'workspace members (bell)' : null,
                        filled($get('alert_channel_ids'))
                            ? count((array) $get('alert_channel_ids')).' selected channel(s)'
                            : (self::workspaceAlertChannelOptions() === [] ? null : 'all active channels'),
                    ])->filter()->implode(' + ') ?: 'nobody — add an alert channel'),
                TextEntry::make('summary_plan')
                    ->label('Plan usage')
                    ->state(fn (?Monitor $record): string => self::planUsage($record))
                    ->visible(fn (): bool => Filament::getTenant() instanceof Workspace),
            ]);
    }

    /**
     * Interval options allowed by the current workspace's plan.
     *
     * @return array<int, string>
     */
    public static function allowedIntervalOptions(): array
    {
        $minimumSeconds = self::minimumIntervalSeconds();

        return array_filter(
            self::INTERVAL_OPTIONS,
            fn (int $seconds): bool => $seconds >= $minimumSeconds,
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * @return array<int, string>
     */
    public static function workspaceAlertChannelOptions(): array
    {
        $workspace = Filament::getTenant();

        if (! $workspace instanceof Workspace) {
            return [];
        }

        return $workspace->notificationChannels()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'type'])
            ->mapWithKeys(fn ($channel): array => [$channel->id => "{$channel->name} (".ucfirst($channel->type->value).')'])
            ->all();
    }

    /**
     * Types the plan allows, plus the record's current type so a downgrade never locks an existing monitor.
     *
     * @return list<string>
     */
    private static function allowedTypes(?Monitor $record): array
    {
        $workspace = Filament::getTenant();
        $allowed = $workspace instanceof Workspace
            ? EntitlementService::allowedMonitorTypes($workspace)
            : array_column(MonitorType::cases(), 'value');

        return $record === null ? $allowed : array_values(array_unique([...$allowed, $record->type->value]));
    }

    /**
     * A tile is selectable when the plan allows it; Port additionally needs at least one owned host.
     */
    private static function typeIsSelectable(string $type, ?Monitor $record): bool
    {
        if (! in_array($type, self::allowedTypes($record), true)) {
            return false;
        }

        return $type !== MonitorType::Port->value || $record !== null || self::ownedHostNames() !== [];
    }

    /**
     * Allowed extra checks, plus any the record already has (kept through a plan downgrade).
     *
     * @return list<string>
     */
    private static function allowedExtraTypes(?Monitor $record): array
    {
        $existing = $record?->children()->pluck('type')->map(fn (MonitorType $type): string => $type->value)->all() ?? [];

        return array_values(array_unique([...self::allowedTypes(null), ...$existing]));
    }

    private static function extraCheckIsSelectable(string $type, Get $get, ?Monitor $record): bool
    {
        return in_array($type, self::allowedExtraTypes($record), true)
            && ! ($type === MonitorType::Port->value && self::portExtraCheckIsBlocked($get));
    }

    /**
     * The "Server ports" extra check scans this monitor's own host, so it needs that host to be owned.
     */
    private static function portExtraCheckIsBlocked(Get $get): bool
    {
        return in_array(MonitorType::Port->value, MonitorChildChecksSync::childTypesFor(self::selectedType($get)), true)
            && ! self::hostIsOwned(self::extraChecksHost($get));
    }

    private static function extraChecksHost(Get $get): ?string
    {
        return MonitorChildChecksSync::hostFor(self::selectedType($get), $get('url'), $get('host'));
    }

    /**
     * Record types a DNS check watches. Their current values become the baseline; any later change alerts.
     */
    public static function dnsRecordTypesInput(string $name): CheckboxList
    {
        return CheckboxList::make($name)
            ->label('Records to watch')
            ->options(self::DNS_RECORD_TYPES)
            ->default(['A'])
            ->columns(['default' => 2, 'sm' => 3])
            ->helperText('The values found on the first check become the baseline. You are alerted when any of them changes or disappears — e.g. MX records swapped (email hijack) or the A record pointing somewhere else. After an intentional DNS change, click "Accept current DNS" on the monitor page.')
            ->rules(['array', 'min:1']);
    }

    /**
     * Tag input for up to 10 TCP ports, with the common service ports offered as suggestions.
     */
    public static function portsInput(string $name): TagsInput
    {
        return TagsInput::make($name)
            ->label('Ports to check')
            ->placeholder('Type a port or pick one: 22, 3306…')
            ->suggestions(collect(Monitor::COMMON_PORTS)->keys()->map(fn (int $port): string => (string) $port)->all())
            ->helperText(collect(Monitor::COMMON_PORTS)->map(fn (string $service, int $port): string => "{$service} {$port}")->implode(' · ')
                .'. Up to '.Monitor::MAX_PORTS.' ports; the monitor is down when any of them is closed. Needs verified ownership of the host — a website verified in Backup Management already counts.')
            ->formatStateUsing(fn (mixed $state): array => array_map('strval', Monitor::normalizePorts((array) $state)))
            ->nestedRecursiveRules(['integer', 'between:1,65535'])
            ->rules(['array', 'max:'.Monitor::MAX_PORTS])
            ->validationMessages(['max' => 'Check at most '.Monitor::MAX_PORTS.' ports per monitor.'])
            ->dehydrateStateUsing(fn (mixed $state): array => Monitor::normalizePorts((array) $state));
    }

    /**
     * Whether the workspace has proven it owns the host (exact name, or the IP of an owned domain/server).
     * Memoised for the request because the form re-renders on every live update.
     */
    private static function hostIsOwned(?string $host): bool
    {
        $workspace = self::currentWorkspace();
        $host = Monitor::normalizeHost($host);

        if ($workspace === null || $host === null) {
            return false;
        }

        $cache = request()->attributes->get('monitor_form.owned_hosts', []);

        if (! array_key_exists($host, $cache)) {
            $cache[$host] = app(HostOwnershipVerifier::class)->ownership($workspace, $host)['owned'];
            request()->attributes->set('monitor_form.owned_hosts', $cache);
        }

        return $cache[$host];
    }

    /**
     * @return list<string>
     */
    private static function ownedHostNames(): array
    {
        $workspace = self::currentWorkspace();

        if ($workspace === null) {
            return [];
        }

        if (! request()->attributes->has('monitor_form.owned_host_names')) {
            request()->attributes->set('monitor_form.owned_host_names', app(HostOwnershipVerifier::class)->ownedHostNames($workspace));
        }

        return request()->attributes->get('monitor_form.owned_host_names');
    }

    /**
     * What the last check found for an expiry monitor, e.g. "Nov 17, 2027 · 370 days left · read automatically
     * from the registry, checked 2 minutes ago". Shown read-only in the form: copying it into the manual field
     * would freeze it and hide a renewal.
     */
    public static function detectedExpiryText(?Monitor $monitor): ?string
    {
        if ($monitor === null || ! $monitor->type->isExpiryCheck()) {
            return null;
        }

        if ($monitor->expires_at === null) {
            return match (true) {
                $monitor->last_checked_at === null => 'Not checked yet — the date appears here after the first check.',
                $monitor->expiryIsUnknown() => 'Not available from the registry — enter the date below.',
                default => 'Not found in the last check.',
            };
        }

        $daysLeft = (int) floor(now()->diffInDays($monitor->expires_at, false));
        $source = match ($monitor->last_probe_method) {
            'rdap' => 'read automatically from the registry',
            'manual' => 'your manual date',
            'shared' => 'shared from another check of '.RegistrableDomain::of($monitor->host),
            'cached' => 'last known date',
            default => $monitor->type === MonitorType::Ssl ? 'read from the certificate' : null,
        };

        return $monitor->expiryDateLabel()
            .' · '.($daysLeft >= 0 ? "{$daysLeft} ".str('day')->plural($daysLeft).' left' : 'expired')
            .($source ? " · {$source}" : '')
            .($monitor->last_checked_at ? ', checked '.$monitor->last_checked_at->diffForHumans() : '');
    }

    /**
     * The extra check of the given type on the record being edited, if any.
     */
    private static function childCheck(?Monitor $record, MonitorType $type): ?Monitor
    {
        return $record?->children->firstWhere('type', $type);
    }

    /**
     * The .bd registry publishes no expiry date to machines; BTCL's own lookup (behind a CAPTCHA) shows it
     * to a person, who can then type it in here.
     */
    public static function manualExpiryHelp(?string $host): HtmlString|string
    {
        if (! str_ends_with(mb_strtolower(rtrim((string) $host, '.')), '.bd')) {
            return 'Leave empty when a date is shown above: it is read automatically and follows renewals. Only needed if the registry publishes no expiry date (e.g. .bd).';
        }

        return new HtmlString(
            '.bd domains publish no expiry date automatically. Look it up on BTCL, then enter it here — it is shared with other checks of '
            .e(RegistrableDomain::of($host) ?? (string) $host).'. '
            .'<a href="'.e(self::BTCL_DOMAIN_LOOKUP_URL).'" target="_blank" rel="noopener noreferrer" style="color:var(--sv-primary);font-weight:600;">Check on BTCL →</a>'
        );
    }

    private static function verifiedDomainsLink(string $text): HtmlString
    {
        return new HtmlString(e($text).' <a href="'.e(VerifiedDomainResource::getUrl('index')).'" target="_blank" style="color:var(--sv-primary);font-weight:600;">Verify a domain →</a>');
    }

    private static function currentWorkspace(): ?Workspace
    {
        $workspace = Filament::getTenant();

        return $workspace instanceof Workspace ? $workspace : null;
    }

    private static function minimumIntervalSeconds(): int
    {
        $workspace = Filament::getTenant();

        return $workspace instanceof Workspace
            ? EntitlementService::minMonitorIntervalSeconds($workspace)
            : 60;
    }

    private static function summaryTarget(Get $get): ?string
    {
        $type = self::selectedType($get);

        return match (true) {
            $type->usesUrl() => $get('url'),
            $type->isPassive() => 'incoming heartbeat URL',
            $type === MonitorType::Port => filled($get('host')) ? $get('host').':'.$get('port') : null,
            default => $get('host'),
        };
    }

    private static function planUsage(?Monitor $record): string
    {
        $workspace = Filament::getTenant();

        if (! $workspace instanceof Workspace) {
            return '—';
        }

        $used = $workspace->monitors()->count() + ($record === null ? 1 : 0);

        return "{$used} of ".EntitlementService::monitorLimit($workspace).' monitors'
            .($record === null ? ' (including this one, plus 1 per extra check)' : '');
    }

    private static function selectedType(Get $get): MonitorType
    {
        $type = $get('type');

        if ($type instanceof MonitorType) {
            return $type;
        }

        return MonitorType::tryFrom((string) $type) ?? MonitorType::Http;
    }
}
