<?php

namespace App\Rules;

use App\Models\Workspace;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Every submitted monitor id must belong to the given workspace. Filament's relationship selects
 * do not re-check submitted ids against the scoped options, so this closes cross-tenant attachment.
 */
class BelongsToWorkspaceMonitors implements ValidationRule
{
    public function __construct(private ?Workspace $workspace) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $monitorIds = collect((array) $value)->filter(fn (mixed $id): bool => filled($id))->map(fn (mixed $id): int => (int) $id)->unique();

        if ($monitorIds->isEmpty()) {
            return;
        }

        $ownedCount = $this->workspace?->monitors()->whereKey($monitorIds->all())->count() ?? 0;

        if ($ownedCount !== $monitorIds->count()) {
            $fail('One or more selected monitors do not belong to this workspace.');
        }
    }
}
