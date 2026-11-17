<?php

namespace App\Http\Controllers;

use App\Models\Call;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;

/**
 * Relais authentifié vers l'enregistrement / la messagerie d'un appel
 * (les URL des fournisseurs exigent souvent une authentification ou expirent).
 */
class CallRecordingController extends Controller
{
    public function __invoke(Request $request, Call $call): Response
    {
        abort_unless(Call::query()->visibleTo($request->user())->whereKey($call->id)->exists(), 404);

        $voicemail = $request->boolean('voicemail');
        $source = $call->provider
            ? $call->provider->driver()->recordingSource($call->provider, $call, $voicemail)
            : (($u = $voicemail ? $call->voicemail_url : $call->recording_url) ? ['url' => $u] : null);

        abort_unless($source && filter_var($source['url'], FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $source['url']), 404);

        // Anti-SSRF : pas d'adresse interne, sauf pour un PBX Asterisk (réseau local par nature)
        if ($call->provider?->driver !== 'asterisk') {
            abort_if($this->isPrivateHost((string) parse_url($source['url'], PHP_URL_HOST)), 404);
        }

        $http = Http::timeout(60)->withHeaders($source['headers'] ?? []);
        if (isset($source['auth'])) {
            $http = $http->withBasicAuth(...$source['auth']);
        }

        $r = $http->get($source['url']);
        abort_unless($r->successful(), 502, 'Enregistrement indisponible chez le fournisseur.');

        return response($r->body(), 200, [
            'Content-Type' => $r->header('Content-Type') ?: 'audio/mpeg',
            'Content-Disposition' => 'inline; filename="appel-'.$call->id.($voicemail ? '-messagerie' : '').'.mp3"',
            'Cache-Control' => 'private, max-age=600',
        ]);
    }

    private function isPrivateHost(string $host): bool
    {
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);

        if ($ips === []) {
            return true;
        }

        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return true;
            }
        }

        return false;
    }
}
