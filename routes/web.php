<?php

use App\Http\Controllers\CallRecordingController;
use App\Http\Controllers\Webhooks\RingoverWebhookController;
use App\Http\Controllers\Webhooks\TelephonyWebhookController;
use Illuminate\Support\Facades\Route;

// Le CRM (Filament) est servi à la racine du domaine.

// Webhooks des services de téléphonie (Ringover, Aircall, Twilio, webhook générique)
Route::match(['get', 'post'], '/webhooks/telephony/{token}', TelephonyWebhookController::class)
    ->middleware('throttle:1200,1')
    ->name('webhooks.telephony');

// Ancienne URL Ringover (compatibilité)
Route::post('/webhooks/ringover/{token}', RingoverWebhookController::class)
    ->middleware('throttle:1200,1')
    ->name('webhooks.ringover');

// Écoute des enregistrements via le CRM (utilisateur connecté)
Route::get('/calls/{call}/recording', CallRecordingController::class)
    ->middleware(['web', 'auth'])
    ->name('calls.recording');
