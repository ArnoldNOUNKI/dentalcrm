<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\IngestTelephonyCall;
use App\Models\TelephonyProvider;
use App\Services\Telephony\TelephonyManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Point d'entrée unique des webhooks de téléphonie : /webhooks/telephony/{token}
 * Le jeton identifie le service ; chaque connecteur vérifie ensuite sa propre signature.
 */
class TelephonyWebhookController extends Controller
{
    public function __invoke(Request $request, string $token): Response
    {
        $provider = TelephonyProvider::where('webhook_token', $token)->first();

        return $this->handle($request, $provider);
    }

    public function handle(Request $request, ?TelephonyProvider $provider): Response
    {
        if (! $provider || ! $provider->is_active || ! app(TelephonyManager::class)->has($provider->driver)) {
            return response()->json(['error' => 'unknown'], 404);
        }

        $driver = $provider->driver();

        if (! $driver->verifyWebhook($provider, $request)) {
            Log::warning("Webhook téléphonie refusé (signature) : {$provider->name}");

            return response()->json(['error' => 'invalid signature'], 401);
        }

        $calls = $driver->parseWebhook($provider, $request);
        $provider->forceFill(['last_webhook_at' => now()])->saveQuietly();

        // Réponse immédiate, traitement en file d'attente
        foreach ($calls as $call) {
            IngestTelephonyCall::dispatch($provider->id, $call->toArray());
        }

        return $driver->webhookResponse();
    }
}
