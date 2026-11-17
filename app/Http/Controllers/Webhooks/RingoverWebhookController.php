<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessRingoverEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Point d'entrée des webhooks Ringover.
 * URL à déclarer dans Ringover (Dashboard > Développeur > Webhooks) :
 *   https://votre-crm.fr/webhooks/ringover/{RINGOVER_WEBHOOK_TOKEN}
 * Cocher au minimum : appels terminés (hangup), manqués, messagerie, et si dispo : enregistrements / transcriptions Empower.
 */
class RingoverWebhookController extends Controller
{
    public function __invoke(Request $request, string $token): JsonResponse
    {
        $expected = (string) config('services.ringover.webhook_token');

        if ($expected === '' || ! hash_equals($expected, $token)) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        $payload = $request->json()->all() ?: $request->all();

        if (empty($payload)) {
            return response()->json(['status' => 'ignored']);
        }

        Log::channel(config('logging.default'))->debug('Ringover webhook', ['event' => $payload['event'] ?? null]);

        // Réponse immédiate (Ringover attend un 2xx rapide), traitement en file d'attente
        ProcessRingoverEvent::dispatch($payload, $request->query('event'));

        return response()->json(['status' => 'queued']);
    }
}
