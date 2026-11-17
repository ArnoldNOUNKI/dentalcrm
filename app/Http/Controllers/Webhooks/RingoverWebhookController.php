<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\TelephonyProvider;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ancienne URL /webhooks/ringover/{token} conservée pour les webhooks déjà configurés.
 */
class RingoverWebhookController extends Controller
{
    public function __invoke(Request $request, string $token): Response
    {
        $provider = TelephonyProvider::where('driver', 'ringover')->where('webhook_token', $token)->first();

        $legacy = (string) config('services.ringover.webhook_token');
        if (! $provider && $legacy !== '' && hash_equals($legacy, $token)) {
            $provider = TelephonyProvider::ringoverFromEnv();
        }

        if (! $provider) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        return app(TelephonyWebhookController::class)->handle($request, $provider);
    }
}
