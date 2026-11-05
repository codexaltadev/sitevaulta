<?php

namespace App\Console\Commands;

use App\Models\Workspace;
use Database\Seeders\DemoWorkspaceSeeder;
use Illuminate\Console\Command;

class SeedDemoWorkspaceCommand extends Command
{
    protected $signature = 'sitevaulta:seed-demo {workspace? : Workspace slug to seed demo data into} {--all : Seed for all existing workspaces}';

    protected $description = 'Seed rich, realistic demonstration websites, policies, and backup runs for testing animated dashboard widgets';

    public function handle(DemoWorkspaceSeeder $seeder): int
    {
        $all = (bool) $this->option('all');
        $slug = $this->argument('workspace');

        if ($all) {
            $workspaces = Workspace::all();
            if ($workspaces->isEmpty()) {
                $this->error('No workspaces found in the database. Please run migrations and base seeders first.');
                return self::FAILURE;
            }

            foreach ($workspaces as $ws) {
                $this->info("Seeding demo data for workspace: {$ws->name} ({$ws->slug})...");
                $seeder->seedDemoForWorkspace($ws);
            }

            $this->info('✅ Successfully seeded demo data across all workspaces!');
            return self::SUCCESS;
        }

        if ($slug) {
            $workspace = Workspace::where('slug', $slug)->first();
            if (! $workspace) {
                $this->error("Workspace with slug [{$slug}] not found.");
                return self::FAILURE;
            }
        } else {
            // Default: pick demo-agency or sitevaulta-hq or first workspace
            $workspace = Workspace::where('slug', 'demo-agency')->first()
                ?? Workspace::where('slug', 'sitevaulta-hq')->first()
                ?? Workspace::first();
        }

        if (! $workspace) {
            $this->error('No active workspace found to seed demo data.');
            return self::FAILURE;
        }

        $this->info("Seeding rich demo data into workspace: {$workspace->name} (slug: {$workspace->slug})...");
        $seeder->seedDemoForWorkspace($workspace);

        $this->info("✅ Demo data ready! Log into /app to view live animated reports for workspace [{$workspace->slug}].");

        return self::SUCCESS;
    }
}

