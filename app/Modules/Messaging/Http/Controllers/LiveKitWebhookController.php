<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Messaging\Services\LiveKitToken;
use App\Modules\Messaging\Services\MeetingService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * LiveKit tells us who really came and went. ⛔ No login — the proof is the signature over the exact body, with our own
 * secret ([[LiveKitToken::webhookIsOurs()]]); anything else 401 and nothing is read.
 */
final class LiveKitWebhookController extends Controller
{
    public function __invoke(Request $request, LiveKitToken $token, MeetingService $meetings): Response
    {
        $body = (string) $request->getContent();
        $proof = (string) preg_replace('/^Bearer\s+/i', '', (string) $request->header('Authorization', ''));

        if (! $token->webhookIsOurs($proof, $body)) {
            return response('', 401);
        }

        $event = json_decode($body, true);

        if (is_array($event)) {
            $meetings->heard($event);
        }

        return response()->noContent();
    }
}
