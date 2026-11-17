<?php

namespace App\Services\Ringover;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Client API Ringover v2.
 * Auth : header « Authorization: <API_KEY> » (clé créée dans Dashboard Ringover > Développeur > API).
 */
class RingoverClient
{
    public function isConfigured(): bool
    {
        return filled(config('services.ringover.api_key'));
    }

    /**
     * Click-to-call : Ringover fait d'abord sonner l'agent (sur son app / softphone),
     * puis compose le numéro du prospect dès qu'il décroche.
     *
     * @return array<string, mixed>
     */
    public function clickToCall(string $agentNumber, string $prospectNumber): array
    {
        return $this->request()
            ->post('/callback', [
                'from_number' => (int) $this->digits($agentNumber),
                'to_number' => (int) $this->digits($prospectNumber),
                'timeout' => 30,
                'device' => 'ALL',
                'clir' => false,
            ])
            ->throw(fn ($response, $e) => throw new RuntimeException('Ringover click-to-call : '.($response->json('message') ?? $response->body()), 0, $e))
            ->json() ?? [];
    }

    /**
     * Liste des appels entre deux dates (utilisé par la synchronisation périodique).
     *
     * @return array<int, array<string, mixed>>
     */
    public function listCalls(Carbon $from, Carbon $to, int $limit = 500): array
    {
        $calls = [];
        $offset = 0;

        do {
            $response = $this->request()->get('/calls', [
                'start_date' => $from->copy()->utc()->format('Y-m-d\TH:i:s.v\Z'),
                'end_date' => $to->copy()->utc()->format('Y-m-d\TH:i:s.v\Z'),
                'limit_count' => min(1000, $limit),
                'limit_offset' => $offset,
            ]);

            if ($response->status() === 204) {
                break;
            }

            $response->throw();
            $page = $response->json('call_list') ?? [];
            $calls = array_merge($calls, $page);
            $offset += count($page);
        } while (count($page) > 0 && count($page) >= min(1000, $limit) && count($calls) < 5000);

        return $calls;
    }

    /** @return array<string, mixed>|null */
    public function getCall(string $callId): ?array
    {
        try {
            $data = $this->request()->get('/calls/'.urlencode($callId))->throw()->json();

            return $data['call_list'][0] ?? $data ?? null;
        } catch (RequestException) {
            return null;
        }
    }

    private function request(): PendingRequest
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('RINGOVER_API_KEY non configurée.');
        }

        return Http::baseUrl(rtrim(config('services.ringover.base_url'), '/'))
            ->withHeaders(['Authorization' => config('services.ringover.api_key')])
            ->acceptJson()
            ->timeout(20)
            ->retry(2, 1000, throw: false);
    }

    private function digits(string $number): string
    {
        return preg_replace('/\D+/', '', $number);
    }
}
