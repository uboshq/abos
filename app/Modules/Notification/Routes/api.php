<?php

declare(strict_types=1);

use App\Modules\Notification\Http\Controllers\WhatsAppWebhookController;
use Illuminate\Support\Facades\Route;

/*
 * ⭐ Meta's WhatsApp webhook — `/api/v1/webhooks/whatsapp/{company public_id}` (sample HUXqpfhxng8823eEFrvH54, tab ঘ).
 *
 * ⛔ Open to the world on purpose (Meta has no login): GET answers only this company's verify token, POST only a body
 *   signed with this company's app secret (X-Hub-Signature-256), and only entries for its own number/account are used
 *   ([[WhatsAppWebhookController]]). Throttled. Declared in [[EveryRouteIsGuardedTest]]::OPEN_TO_THE_WORLD.
 * ⓘ Names: `api.notification.*` (the module loader's prefix).
 */
Route::get('v1/webhooks/whatsapp/{company}', [WhatsAppWebhookController::class, 'verify'])
    ->middleware('throttle:30,1,whatsapp-webhook-verify')
    ->name('whatsapp_webhook.verify');

Route::post('v1/webhooks/whatsapp/{company}', [WhatsAppWebhookController::class, 'receive'])
    ->middleware('throttle:300,1,whatsapp-webhook')
    ->name('whatsapp_webhook');
