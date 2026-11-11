<?php

namespace App\Models;

use App\Enums\MaintenanceScheduleType;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\MaintenanceWindowFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A planned period during which linked monitors are not checked, open no incidents and send no alerts.
 * Recurring windows are defined in the workspace timezone ("02:00 every Sunday for 60 minutes").
 */
class MaintenanceWindow extends Model
{
    /** @use HasFactory<MaintenanceWindowFactory> */
    use HasFactory;

    public const MAX_DURATION_MINUTES = 1440;

    /**
     * @var array<int, string>
     */
    public const WEEKDAY_LABELS = [
        0 => 'Sunday',
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
    ];

    protected $fillable = [
        'workspace_id',
        'name',
        'schedule_type',
        'starts_at',
        'start_time',
        'days_of_week',
        'day_of_month',
        'duration_minutes',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'schedule_type' => MaintenanceScheduleType::class,
            'starts_at' => 'datetime',
            'days_of_week' => 'array',
            'day_of_month' => 'integer',
            'duration_minutes' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function monitors(): BelongsToMany
    {
        return $this->belongsToMany(Monitor::class);
    }

    public function isInProgressAt(CarbonInterface $moment): bool
    {
        if (! $this->is_active) {
            return false;
        }

        $moment = CarbonImmutable::instance($moment);

        if ($this->schedule_type === MaintenanceScheduleType::OneTime) {
            return $this->starts_at !== null
                && $this->starts_at->lte($moment)
                && $this->starts_at->copy()->addMinutes($this->duration_minutes)->gt($moment);
        }

        $local = $moment->setTimezone($this->timezone());

        // A recurring window that started yesterday may still be running after midnight.
        foreach ([$local->subDay(), $local] as $day) {
            $start = $this->occurrenceStartOn($day);

            if ($start !== null && $start->lte($local) && $start->addMinutes($this->duration_minutes)->gt($local)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Start of the next occurrence that begins after $moment (UTC), or null when nothing is scheduled.
     */
    public function nextStartAfter(CarbonInterface $moment): ?CarbonImmutable
    {
        if (! $this->is_active) {
            return null;
        }

        $moment = CarbonImmutable::instance($moment);

        if ($this->schedule_type === MaintenanceScheduleType::OneTime) {
            return $this->starts_at !== null && $this->starts_at->gt($moment)
                ? CarbonImmutable::instance($this->starts_at)
                : null;
        }

        $local = $moment->setTimezone($this->timezone());

        for ($offset = 0; $offset <= 62; $offset++) {
            $start = $this->occurrenceStartOn($local->addDays($offset));

            if ($start !== null && $start->gt($local)) {
                return $start->utc();
            }
        }

        return null;
    }

    /**
     * Human-readable schedule, e.g. "Every Sunday at 02:00 for 1h".
     */
    public function scheduleSummary(): string
    {
        $duration = $this->duration_minutes >= 60 && $this->duration_minutes % 60 === 0
            ? ($this->duration_minutes / 60).'h'
            : "{$this->duration_minutes} min";

        return match ($this->schedule_type) {
            MaintenanceScheduleType::OneTime => ($this->starts_at?->setTimezone($this->timezone())->format('M j, Y H:i') ?? '—')." for {$duration}",
            MaintenanceScheduleType::Daily => "Every day at {$this->start_time} for {$duration}",
            MaintenanceScheduleType::Weekly => 'Every '.collect($this->days_of_week ?? [])
                ->sort()
                ->map(fn (int|string $day): string => self::WEEKDAY_LABELS[(int) $day] ?? '?')
                ->implode(', ')." at {$this->start_time} for {$duration}",
            MaintenanceScheduleType::Monthly => "Day {$this->day_of_month} of every month at {$this->start_time} for {$duration}",
        };
    }

    private function occurrenceStartOn(CarbonImmutable $localDay): ?CarbonImmutable
    {
        if (! is_string($this->start_time) || ! preg_match('/^(\d{2}):(\d{2})$/', $this->start_time, $time)) {
            return null;
        }

        $runsThatDay = match ($this->schedule_type) {
            MaintenanceScheduleType::Daily => true,
            MaintenanceScheduleType::Weekly => in_array($localDay->dayOfWeek, array_map('intval', $this->days_of_week ?? []), true),
            MaintenanceScheduleType::Monthly => $localDay->day === $this->day_of_month,
            default => false,
        };

        return $runsThatDay ? $localDay->setTime((int) $time[1], (int) $time[2]) : null;
    }

    private function timezone(): string
    {
        return $this->workspace?->timezone ?: (string) config('app.timezone');
    }
}
