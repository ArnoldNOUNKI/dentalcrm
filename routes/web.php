<?php

use App\Http\Controllers\Webhooks\RingoverWebhookController;
use Illuminate\Support\Facades\Route;

// Le CRM (Filament) est servi à la racine du domaine.

Route::post('/webhooks/ringover/{token}', RingoverWebhookController::class)
    ->middleware('throttle:600,1')
    ->name('webhooks.ringover');
