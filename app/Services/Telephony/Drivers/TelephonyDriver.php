<?php

namespace App\Services\Telephony\Drivers;

use App\Models\Call;
use App\Models\TelephonyAgent;
use App\Models\TelephonyProvider;
use App\Models\User;
use App\Services\Telephony\NormalizedCall;
use Filament\Forms\Components\Component;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Contrat d'un connecteur de téléphonie.
 *
 * Un connecteur sait (selon ses capacités) :
 *  - décrire ses paramètres de connexion (formulaire Filament)
 *  - tester la connexion
 *  - récupérer les appels sur une période (synchronisation automatique)
 *  - lire les webhooks temps réel et vérifier leur authenticité
 *  - lancer un appel (click-to-call)
 *  - fournir l'enregistrement d'un appel
 */
abstract class TelephonyDriver
{
    abstract public function key(): string;

    abstract public function label(): string;

    /** Court texte d'aide affiché dans le formulaire. */
    public function description(): string
    {
        return '';
    }

    /** @return array<Component> champs stockés (chiffrés) dans credentials */
    abstract public function credentialFields(): array;

    /** @return array<Component> champs stockés dans settings */
    public function settingFields(): array
    {
        return [];
    }

    /** Instructions de configuration du webhook côté fournisseur (HTML simple). */
    public function webhookInstructions(TelephonyProvider $provider): ?string
    {
        return null;
    }

    public function supportsWebhook(): bool
    {
        return true;
    }

    public function supportsSync(): bool
    {
        return true;
    }

    public function supportsClickToCall(TelephonyProvider $provider): bool
    {
        return false;
    }

    /** Lève une exception explicite si la connexion échoue ; renvoie un message de succès. */
    abstract public function testConnection(TelephonyProvider $provider): string;

    /**
     * Appels de la période [from, to].
     *
     * @return iterable<NormalizedCall>
     */
    public function fetchCalls(TelephonyProvider $provider, Carbon $from, Carbon $to): iterable
    {
        return [];
    }

    public function verifyWebhook(TelephonyProvider $provider, Request $request): bool
    {
        return true; // le jeton secret de l'URL est déjà vérifié
    }

    /** @return array<NormalizedCall> */
    public function parseWebhook(TelephonyProvider $provider, Request $request): array
    {
        return [];
    }

    /** Réponse HTTP renvoyée au fournisseur après un webhook. */
    public function webhookResponse(): Response
    {
        return response()->json(['status' => 'ok']);
    }

    public function clickToCall(TelephonyProvider $provider, User $user, ?TelephonyAgent $agent, string $number): string
    {
        throw new RuntimeException('Ce service ne permet pas de lancer un appel depuis le CRM.');
    }

    /**
     * URL et en-têtes pour lire l'enregistrement (le CRM sert de relais authentifié).
     *
     * @return array{url: string, headers?: array<string, string>, auth?: array{0: string, 1: string}}|null
     */
    public function recordingSource(TelephonyProvider $provider, Call $call, bool $voicemail = false): ?array
    {
        $url = $voicemail ? $call->voicemail_url : $call->recording_url;

        return $url ? ['url' => $url] : null;
    }

    /** Durée max d'une fenêtre de récupération (certaines API limitent la plage). */
    public function maxSyncWindowDays(): int
    {
        return 7;
    }
}
