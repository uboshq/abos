<?php

declare(strict_types=1);

namespace App\Modules\Notification\Http\Controllers;

use App\Core\Services\PartyMessageDispatcher;
use App\Core\Services\WhatsAppCloud;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * ⭐ Meta's WhatsApp webhook — `/api/v1/webhooks/whatsapp/{company public_id}` (sample HUXqpfhxng8823eEFrvH54, tab ঘ).
 *
 *   GET  hub.mode=subscribe & hub.verify_token & hub.challenge → the challenge, when the token is this company's
 *   POST X-Hub-Signature-256: sha256=hex(HMAC-SHA256(raw body, this company's app secret)) → statuses and template
 *        status updates ([[WhatsAppCloud::receive()]])
 *
 * ⛔ No login: Meta calls it. The company comes from the URL only, the proof from that company's own secrets, and then
 *   only entries carrying that company's own phone number id / business account id are used. Anything else → 403.
 */
class WhatsAppWebhookController extends Controller
{
    /** Meta's calls are small; anything larger is not Meta */
    private const MAX_BYTES = 512 * 1024;

    public function verify(Request $request, string $company, WhatsAppCloud $cloud): Response
    {
        $id = $this->companyId($company);
        abort_if($id === null, 403);

        // ⓘ PHP turns the dots of "hub.mode" into underscores in the query array
        $mode = $request->query('hub_mode', $request->query('hub.mode'));
        $token = $request->query('hub_verify_token', $request->query('hub.verify_token'));
        $challenge = $request->query('hub_challenge', $request->query('hub.challenge'));

        $ok = $mode === 'subscribe' && is_string($challenge) && strlen($challenge) <= 200
            && CompanyContext::forCompany($id, fn () => $cloud->verifies(is_string($token) ? $token : null));

        abort_unless($ok, 403);

        return response((string) $challenge, 200, ['Content-Type' => 'text/plain']);
    }

    public function receive(Request $request, string $company, WhatsAppCloud $cloud, PartyMessageDispatcher $dispatcher): JsonResponse
    {
        $body = $request->getContent();
        abort_if(strlen($body) > self::MAX_BYTES, 413);

        $id = $this->companyId($company);
        abort_if($id === null, 403);

        $signature = $request->header('X-Hub-Signature-256');
        abort_unless(CompanyContext::forCompany($id, fn () => $cloud->signed($body, is_string($signature) ? $signature : null)), 403);

        $payload = json_decode($body, true);

        if (! is_array($payload)) {
            return response()->json(['data' => ['accepted' => 0]], 422);
        }

        return response()->json(['data' => ['accepted' => $cloud->receive($id, $payload, $dispatcher)]]);
    }

    private function companyId(string $publicId): ?int
    {
        if (! Str::isUuid($publicId)) {
            return null;
        }

        $id = Company::query()->withoutGlobalScopes()->where('public_id', $publicId)->value('id');

        return $id === null ? null : (int) $id;
    }
}
