<?php

use App\Http\Controllers\Api\V1\PreDeployWebhookController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::post('/websites/{websiteUuid}/trigger-backup', [PreDeployWebhookController::class, 'trigger'])
        ->name('api.v1.websites.trigger-backup');
});

