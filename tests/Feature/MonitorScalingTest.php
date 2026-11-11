<?php

namespace Tests\Feature;

use App\Jobs\Monitoring\RunMonitorCheckJob;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Services\Monitoring\MonitorCheckRunner;
use App\Services\Monitoring\MonitorUptimeStatistics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MonitorScalingTest extends TestCase
{
    use RefreshDatabase;

    public function test_scheduler_queues_only_due_monitors_on_the_monitoring_queue(): void
    {
        $this->freezeTime();
        Queue::fake();
        $due = Monitor::factory()->up()->create(['last_checked_at' => now()->subMinutes(10), 'interval_seconds' => 300]);
        $notDue = Monitor::factory()->up()->create(['last_checked_at' => now()->subMinute(), 'interval_seconds' => 300]);
        Monitor::factory()->paused()->create();

        $this->artisan('sitevaulta:check-monitors')->expectsOutput('Queued 1 due monitors')->assertSuccessful();

        Queue::assertPushedOn('monitoring', RunMonitorCheckJob::class, fn (RunMonitorCheckJob $job): bool => $job->monitorId === $due->id);
        Queue::assertPushed(RunMonitorCheckJob::class, 1);
        $this->assertSame(now()->addSeconds(120)->toDateTimeString(), $due->refresh()->next_check_at->toDateTimeString(), 'Claimed while queued.');
        $this->assertSame(now()->addMinutes(4)->toDateTimeString(), $notDue->refresh()->next_check_at->toDateTimeString());
    }

    public function test_a_not_yet_due_monitor_without_a_next_check_time_gets_one(): void
    {
        $this->freezeTime();
        Queue::fake();
        $monitor = Monitor::factory()->up()->create(['last_checked_at' => now()->subHour(), 'interval_seconds' => 86400]);
        $monitor->forceFill(['next_check_at' => null])->saveQuietly();

        $this->artisan('sitevaulta:check-monitors')->expectsOutput('Queued 0 due monitors');

        $this->assertSame(now()->addHours(23)->toDateTimeString(), $monitor->refresh()->next_check_at->toDateTimeString());
    }

    public function test_a_finished_check_schedules_the_next_one_after_the_interval(): void
    {
        $this->freezeTime();
        Http::fake(['*' => Http::response('ok')]);
        $monitor = Monitor::factory()->create(['url' => 'https://shop.test/', 'interval_seconds' => 300]);

        app(MonitorCheckRunner::class)->run($monitor);

        $this->assertSame(now()->addMinutes(5)->toDateTimeString(), $monitor->refresh()->next_check_at->toDateTimeString());
        $this->assertSame(now()->toDateTimeString(), $monitor->first_checked_at->toDateTimeString());
    }

    public function test_a_duplicate_job_for_an_already_checked_monitor_does_nothing(): void
    {
        Http::preventStrayRequests();
        $monitor = Monitor::factory()->up()->create(['last_checked_at' => now(), 'interval_seconds' => 300]);

        RunMonitorCheckJob::dispatchSync($monitor->id);

        $this->assertSame(0, $monitor->checks()->count());
    }

    public function test_sync_option_runs_checks_in_the_scheduler_process(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response('ok')]);
        $monitor = Monitor::factory()->create(['url' => 'https://shop.test/']);

        $this->artisan('sitevaulta:check-monitors', ['--sync' => true])->expectsOutput('Checked 1 due monitors')->assertSuccessful();

        Queue::assertNothingPushed();
        $this->assertSame(1, $monitor->checks()->count());
    }

    public function test_every_check_updates_hourly_and_daily_totals_with_response_times_of_successful_checks_only(): void
    {
        $this->travelTo(now()->setTime(10, 30));
        $monitor = Monitor::factory()->up()->create();
        MonitorCheck::factory()->for($monitor)->create(['checked_at' => now()->setTime(10, 5), 'response_time_ms' => 100]);
        MonitorCheck::factory()->for($monitor)->create(['checked_at' => now()->setTime(10, 10), 'response_time_ms' => 300]);
        MonitorCheck::factory()->for($monitor)->failed()->create(['checked_at' => now()->setTime(10, 15), 'response_time_ms' => 40]);
        MonitorCheck::factory()->for($monitor)->create(['checked_at' => now()->setTime(9, 5), 'response_time_ms' => 200]);

        $hour = DB::table('monitor_hourly_stats')->where('monitor_id', $monitor->id)->where('period_start', now()->setTime(10, 0)->toDateTimeString())->sole();
        $this->assertSame([3, 1, 400, 2, 100, 300], [(int) $hour->total_checks, (int) $hour->down_checks, (int) $hour->response_time_sum, (int) $hour->response_time_count, (int) $hour->response_time_min, (int) $hour->response_time_max]);

        $day = DB::table('monitor_daily_stats')->where('monitor_id', $monitor->id)->sole();
        $this->assertSame([4, 1, 600, 3, 100, 300], [(int) $day->total_checks, (int) $day->down_checks, (int) $day->response_time_sum, (int) $day->response_time_count, (int) $day->response_time_min, (int) $day->response_time_max]);
    }

    public function test_pruning_old_raw_checks_keeps_uptime_history_from_the_totals(): void
    {
        $this->freezeTime();
        config(['monitoring.retention.raw_days' => 30]);
        $monitor = Monitor::factory()->up()->create(['created_at' => now()->subDays(60)]);
        MonitorCheck::factory()->for($monitor)->failed()->create(['checked_at' => now()->subDays(45)]);
        MonitorCheck::factory()->for($monitor)->create(['checked_at' => now()->subDays(40)]);
        MonitorCheck::factory()->for($monitor)->create(['checked_at' => now()->subDays(2)]);
        MonitorCheck::factory()->for($monitor)->create(['checked_at' => now()->subDay()]);

        $this->artisan('sitevaulta:prune-monitor-data')->expectsOutput('Deleted 2 raw checks')->assertSuccessful();

        $this->assertSame(2, $monitor->checks()->count());
        $this->assertSame(75.0, app(MonitorUptimeStatistics::class)->monitorPeriodSummary($monitor, 90)['uptime_percent']);
        $this->assertSame(now()->subDays(45)->toDateTimeString(), $monitor->refresh()->first_checked_at->toDateTimeString());
    }

    public function test_totals_can_be_rebuilt_from_raw_checks(): void
    {
        $monitor = Monitor::factory()->up()->create();
        MonitorCheck::factory()->for($monitor)->create(['checked_at' => now()->subMinutes(10), 'response_time_ms' => 120]);
        DB::table('monitor_hourly_stats')->delete();
        DB::table('monitor_daily_stats')->delete();

        $this->artisan('sitevaulta:rebuild-monitor-stats')->expectsOutput('Rebuilt 1 hourly and 1 daily rows')->assertSuccessful();

        $this->assertSame(1, (int) DB::table('monitor_hourly_stats')->where('monitor_id', $monitor->id)->value('total_checks'));
        $this->assertSame(120, (int) DB::table('monitor_daily_stats')->where('monitor_id', $monitor->id)->value('response_time_max'));
    }
}
