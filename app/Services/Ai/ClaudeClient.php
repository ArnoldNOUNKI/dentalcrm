<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Client minimal pour l'API Messages d'Anthropic.
 *
 * - text()       : réponse texte libre
 * - structured() : réponse JSON garantie via un outil forcé (tool_choice), validée par un schéma
 */
class ClaudeClient
{
    public function __construct(
        private readonly ?string $apiKey = null,
        private readonly ?string $model = null,
    ) {}

    public function isConfigured(): bool
    {
        return filled($this->key());
    }

    public function text(string $system, string $prompt, int $maxTokens = 1500, bool $fast = false): string
    {
        $response = $this->send([
            'model' => $this->resolveModel($fast),
            'max_tokens' => $maxTokens,
            'system' => $system,
            'messages' => [['role' => 'user', 'content' => $prompt]],
        ]);

        return trim(collect($response['content'] ?? [])
            ->where('type', 'text')
            ->pluck('text')
            ->implode("\n"));
    }

    /**
     * @param  array<string, mixed>  $schema  JSON Schema de la sortie attendue
     * @return array<string, mixed>
     */
    public function structured(string $system, string $prompt, string $toolName, string $toolDescription, array $schema, int $maxTokens = 2000, bool $fast = false): array
    {
        $response = $this->send([
            'model' => $this->resolveModel($fast),
            'max_tokens' => $maxTokens,
            'system' => $system,
            'tools' => [[
                'name' => $toolName,
                'description' => $toolDescription,
                'input_schema' => $schema,
            ]],
            'tool_choice' => ['type' => 'tool', 'name' => $toolName],
            'messages' => [['role' => 'user', 'content' => $prompt]],
        ]);

        $block = collect($response['content'] ?? [])->firstWhere('type', 'tool_use');

        if (! $block || ! is_array($block['input'] ?? null)) {
            throw new RuntimeException('Réponse IA inattendue : aucune sortie structurée.');
        }

        return $block['input'];
    }

    /** @return array<string, mixed> */
    private function send(array $payload): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('ANTHROPIC_API_KEY non configurée.');
        }

        try {
            return Http::baseUrl(rtrim(config('services.anthropic.base_url'), '/'))
                ->withHeaders([
                    'x-api-key' => $this->key(),
                    'anthropic-version' => config('services.anthropic.version'),
                ])
                ->acceptJson()
                ->asJson()
                ->timeout(config('services.anthropic.timeout', 60))
                ->retry(3, fn (int $attempt) => $attempt * 2000, function ($exception) {
                    // On ne réessaie que sur surcharge / limite de débit / erreurs réseau
                    return ! $exception instanceof RequestException
                        || in_array($exception->response->status(), [429, 500, 502, 503, 529], true);
                })
                ->post('/v1/messages', $payload)
                ->throw()
                ->json();
        } catch (RequestException $e) {
            $message = $e->response->json('error.message') ?? $e->getMessage();

            throw new RuntimeException('Erreur API Claude : '.$message, $e->getCode(), $e);
        }
    }

    private function key(): ?string
    {
        return $this->apiKey ?? config('services.anthropic.key');
    }

    private function resolveModel(bool $fast): string
    {
        return $this->model ?? ($fast ? config('services.anthropic.model_fast') : config('services.anthropic.model'));
    }
}
