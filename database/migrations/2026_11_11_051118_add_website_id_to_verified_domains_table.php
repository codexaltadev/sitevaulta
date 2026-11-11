<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Websites verified in Backup Management are mirrored into verified_domains, so the Verified domains
 * page lists every proven domain in one place.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('verified_domains', function (Blueprint $table): void {
            $table->foreignId('website_id')->nullable()->after('workspace_id')->constrained()->cascadeOnDelete();
        });

        $websites = DB::table('websites')
            ->where('verification_status', 'verified')
            ->whereNotNull('domain')
            ->get(['id', 'workspace_id', 'domain', 'verified_at']);

        foreach ($websites as $website) {
            $domain = (string) preg_replace('/^www\./', '', rtrim(mb_strtolower(trim($website->domain)), '.'));

            if ($domain === '') {
                continue;
            }

            $existing = DB::table('verified_domains')->where('workspace_id', $website->workspace_id)->where('domain', $domain)->first();
            $attributes = [
                'website_id' => $website->id,
                'verification_method' => 'backup_website',
                'verified_at' => $existing->verified_at ?? $website->verified_at ?? now(),
                'last_error' => null,
                'updated_at' => now(),
            ];

            if ($existing !== null) {
                DB::table('verified_domains')->where('id', $existing->id)->update($attributes);

                continue;
            }

            DB::table('verified_domains')->insert([
                ...$attributes,
                'workspace_id' => $website->workspace_id,
                'domain' => $domain,
                'token' => 'sv-verify-'.Str::lower(Str::random(32)),
                'created_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('verified_domains')->where('verification_method', 'backup_website')->delete();

        Schema::table('verified_domains', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('website_id');
        });
    }
};
