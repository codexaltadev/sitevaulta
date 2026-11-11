<?php

namespace App\Filament\App\Pages;

use App\Models\Workspace;
use App\Services\Monitoring\DomainOverview;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;

/**
 * Every domain of the workspace on one screen: ownership, backups and uptime side by side, with the
 * domains that need attention first. Each column links to the page where that part is managed.
 */
class Domains extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static ?string $navigationLabel = 'Domains';

    protected static ?string $title = 'Domains';

    protected static ?string $slug = 'domains';

    protected static ?int $navigationSort = -1;

    protected string $view = 'filament.app.pages.domains';

    #[Url]
    public string $search = '';

    #[Url]
    public string $show = 'all';

    public function getSubheading(): ?string
    {
        return 'Ownership, backups and uptime of each domain in one place. Domains that need attention are listed first.';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        /** @var Workspace $workspace */
        $workspace = Filament::getTenant();
        $overview = app(DomainOverview::class);
        $rows = $overview->rows($workspace);
        $search = mb_strtolower(trim($this->search));

        return [
            'summary' => $overview->summary($rows),
            'rows' => collect($rows)
                ->when($search !== '', fn ($rows) => $rows->filter(fn (array $row): bool => str_contains($row['host'], $search)
                    || str_contains(mb_strtolower((string) $row['website']?->name), $search)
                    || str_contains(mb_strtolower((string) $row['monitor']?->name), $search)))
                ->when($this->show === 'attention', fn ($rows) => $rows->filter(fn (array $row): bool => $row['issues'] !== []))
                ->values()
                ->all(),
            'timezone' => $workspace->timezone ?: 'UTC',
        ];
    }
}
