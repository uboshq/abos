<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AuthController;
use App\Http\Middleware\RefuseModulesOffOnThePhone;
use App\Http\Middleware\ResolveCompanyContext;
use App\Modules\Messaging\Http\Controllers\LiveKitWebhookController;
use App\Modules\Messaging\Http\Controllers\MessengerApiController;
use App\Modules\Messaging\Http\Controllers\MessengerCallApiController;
use Illuminate\Support\Facades\Route;

/*
 * ⭐ LiveKit's webhook — no login, proven by our own secret's signature ([[LiveKitWebhookController]]).
 */
Route::post('v1/messenger/livekit/webhook', LiveKitWebhookController::class)->middleware('throttle:600,1,livekit')->name('livekit.webhook');

/*
 * The messenger's phone doors — `/api/v1/messenger/…` (contract: docs/Contract — বার্তা ও কল API (প্রথম ধাপ).md).
 *
 * ⓘ The same guard as every phone door ([[Sales/Routes/api.php]]): token, `abilities:app`, company context, and the
 * module's phone switch. The key (`messaging.use`) is in the controllers; membership of a thread in the service.
 * ⓘ Names: `api.messaging.*` (the module loader's prefix).
 */
Route::prefix('v1/messenger')
    ->middleware([
        'auth:sanctum',
        'throttle:app',
        'abilities:'.AuthController::APP,
        ResolveCompanyContext::class,
        RefuseModulesOffOnThePhone::class.':messaging',
    ])
    ->group(function (): void {
        // ⭐ unchanged → 304, scoped weak ETag ([[AnswersUnchanged]], speed work 23 Nov 2026)
        Route::get('/threads', [MessengerApiController::class, 'threads'])->middleware(\App\Http\Middleware\AnswersUnchanged::class)->name('threads');
        Route::get('/threads/{thread}', [MessengerApiController::class, 'thread'])->name('thread');
        Route::get('/threads/{thread}/messages', [MessengerApiController::class, 'messages'])->name('messages');
        Route::post('/threads/{thread}/messages', [MessengerApiController::class, 'send'])->name('send');
        Route::post('/threads/{thread}/read', [MessengerApiController::class, 'read'])->name('read');
        Route::post('/threads/{thread}/typing', [MessengerApiController::class, 'typing'])->name('typing');
        Route::post('/threads/{thread}/mute', [MessengerApiController::class, 'mute'])->name('mute');
        Route::get('/threads/{thread}/members', [MessengerApiController::class, 'members'])->name('members');
        Route::post('/threads/{thread}/members', [MessengerApiController::class, 'addMembers'])->name('members.add');
        Route::get('/threads/{thread}/media', [MessengerApiController::class, 'media'])->name('media');
        Route::get('/threads/{thread}/search', [MessengerApiController::class, 'search'])->name('search');

        Route::post('/delivered', [MessengerApiController::class, 'delivered'])->name('delivered');
        Route::get('/people', [MessengerApiController::class, 'people'])->name('people');
        Route::post('/direct', [MessengerApiController::class, 'direct'])->name('direct');
        Route::post('/groups', [MessengerApiController::class, 'group'])->name('groups');
        Route::post('/papers', [MessengerApiController::class, 'papers'])->name('papers');
        Route::post('/forward', [MessengerApiController::class, 'forward'])->name('forward');
        Route::delete('/messages/{message}', [MessengerApiController::class, 'destroy'])->whereNumber('message')->name('message.delete');
        Route::get('/messages/{message}/info', [MessengerApiController::class, 'info'])->whereNumber('message')->name('message.info');
        Route::get('/files/{file}', [MessengerApiController::class, 'file'])->whereNumber('file')->name('file');

        Route::get('/calls', [MessengerCallApiController::class, 'log'])->name('calls');
        Route::get('/calls/people', [MessengerCallApiController::class, 'people'])->name('calls.people');
        Route::post('/calls', [MessengerCallApiController::class, 'start'])->name('calls.start');
        Route::post('/calls/{call}/answer', [MessengerCallApiController::class, 'answer'])->name('calls.answer');
        Route::post('/calls/{call}/decline', [MessengerCallApiController::class, 'decline'])->name('calls.decline');
        Route::post('/calls/{call}/end', [MessengerCallApiController::class, 'end'])->name('calls.end');
    });
