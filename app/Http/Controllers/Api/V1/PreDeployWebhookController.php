<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\Backup\ExecuteBackupRunJob;
use App\Models\Website;
use App\Services\Billing\EntitlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class PreDeployWebhookController extends Controller
{
    public function trigger(Request $request, string $websiteUuid): JsonResponse
    {
        $website = Website::with(['workspace', 'backupPolicies'])
            ->where('uuid', $websiteUuid)
            ->first();

        if (! $website || ! $website->isVerified()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Website not found or domain ownership is not verified.',
            ], 404);
        }

        $bearerToken = (string) $request->bearerToken();
        if ($bearerToken === '' || empty($website->webhook_secret_hash) || ! Hash::check($bearerToken, $website->webhook_secret_hash)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized pre-deploy webhook token.',
            ], 401);
        }

        $policyUuid = $request->input('policy_uuid');
        $policy = $policyUuid
            ? $website->backupPolicies()->where('uuid', $policyUuid)->first()
            : $website->backupPolicies()->where('status', 'active')->first();

        if (! $policy || ! EntitlementService::canExecutePolicy($policy)) {
            return response()->json([
                'status' => 'error',
                'message' => 'No active backup policy or workspace subscription entitlement is inactive.',
            ], 403);
        }

        $tag = (string) ($request->input('tag') ?: 'pre-deploy-' . now()->utc()->format('Ymd-His'));

        ExecuteBackupRunJob::dispatch($policy->id, 'pre_deploy_webhook', $tag);

        return response()->json([
            'status' => 'queued',
            'website' => $website->domain,
            'policy_uuid' => $policy->uuid,
            'tag' => $tag,
            'message' => 'Pre-deployment backup job has been queued.',
        ], 202);
    }
}

