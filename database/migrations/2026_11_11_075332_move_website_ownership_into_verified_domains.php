<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * verified_domains becomes the single source of domain ownership. Each backup website gets (or is linked to)
 * the verified_domains row of its domain; the website's own status columns and challenge table go away.
 *
 * The website's existing challenge token is carried over, so a DNS record / file / meta tag the customer
 * already placed keeps matching.
 */
return new class extends Migration
{
    public function up(): void
    {
        $websites = DB::table('websites')->get(['id', 'workspace_id', 'domain', 'verification_status', 'verified_at', 'verification_method']);

        foreach ($websites as $website) {
            $domain = (string) preg_replace('/^www\./', '', rtrim(mb_strtolower(trim((string) $website->domain)), '.'));

            if ($domain === '') {
                continue;
            }

            $challenge = DB::table('website_verifications')->where('website_id', $website->id)->orderByDesc('id')->first();
            $row = DB::table('verified_domains')->where('workspace_id', $website->workspace_id)->where('domain', $domain)->first();
            $isVerified = $website->verification_status === 'verified';
            $rowProvenElsewhere = $row !== null && $row->verified_at !== null && $row->verification_method !== 'backup_website';

            $attributes = [
                'website_id' => $website->id,
                'updated_at' => now(),
            ];

            if (! $rowProvenElsewhere) {
                $attributes += [
                    'token' => $challenge->token ?? $row->token ?? 'sv-verify-'.Str::lower(Str::random(32)),
                    'verified_at' => $isVerified ? ($website->verified_at ?? now()) : null,
                    'verification_method' => $isVerified ? ($website->verification_method ?: 'dns_txt') : null,
                    'last_attempted_at' => $challenge->last_checked_at ?? null,
                    'last_error' => $isVerified ? null : ($challenge->last_error ?? null),
                ];
            }

            if ($row !== null) {
                DB::table('verified_domains')->where('id', $row->id)->update($attributes);

                continue;
            }

            DB::table('verified_domains')->insert([
                ...$attributes,
                'workspace_id' => $website->workspace_id,
                'domain' => $domain,
                'created_at' => now(),
            ]);
        }

        // Any mirror row left over had no website behind it any more, so it proves nothing.
        DB::table('verified_domains')->where('verification_method', 'backup_website')->delete();

        Schema::table('websites', function (Blueprint $table): void {
            $table->dropIndex(['verification_status']);
            $table->dropColumn(['verification_status', 'verified_at', 'verification_method']);
        });

        Schema::dropIfExists('website_verifications');
    }

    public function down(): void
    {
        Schema::create('website_verifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignId('website_id')->constrained('websites')->cascadeOnDelete();
            $table->string('token', 100);
            $table->string('dns_host', 191);
            $table->string('file_path', 191);
            $table->string('meta_tag_name', 100)->default('sitevaulta-verification');
            $table->timestamp('last_checked_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
        });

        Schema::table('websites', function (Blueprint $table): void {
            $table->string('verification_status', 30)->default('unverified')->index();
            $table->timestamp('verified_at')->nullable();
            $table->string('verification_method', 30)->nullable();
        });

        foreach (DB::table('verified_domains')->whereNotNull('website_id')->get() as $row) {
            DB::table('websites')->where('id', $row->website_id)->update([
                'verification_status' => $row->verified_at !== null ? 'verified' : 'pending',
                'verified_at' => $row->verified_at,
                'verification_method' => $row->verification_method,
            ]);
        }
    }
};
