<?php

declare(strict_types=1);

use App\Modules\Messaging\Http\Controllers\MessengerApiController;
use App\Modules\Messaging\Http\Controllers\MessengerAuditController;
use App\Modules\Messaging\Http\Controllers\MessengerCallApiController;
use App\Modules\Messaging\Http\Controllers\MessengerPageController;
use Illuminate\Support\Facades\Route;

/*
 * The messenger on the web (sample M1UddRxK v7) — one page, and the SAME JSON doors as the phone
 * ([[MessengerApiController]]) under `/messages/x/…`, so the page and the app can never disagree.
 * ⓘ Names: `messaging.*` (the module loader's prefix). The key `messaging.use` sits in the controllers.
 */
Route::middleware('auth')->prefix('messages')->group(function (): void {
    Route::get('/', [MessengerPageController::class, 'index'])->name('index');

    Route::prefix('x')->name('x.')->group(function (): void {
        Route::get('/threads', [MessengerApiController::class, 'threads'])->name('threads');
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

        Route::get('/unread', [MessengerPageController::class, 'unread'])->name('unread');
        Route::get('/people', [MessengerApiController::class, 'people'])->name('people');
        Route::post('/direct', [MessengerApiController::class, 'direct'])->name('direct');
        Route::post('/groups', [MessengerApiController::class, 'group'])->name('groups');
        Route::post('/papers', [MessengerApiController::class, 'papers'])->name('papers');
        Route::post('/forward', [MessengerApiController::class, 'forward'])->name('forward');
        Route::delete('/messages/{message}', [MessengerApiController::class, 'destroy'])->whereNumber('message')->name('message.delete');
        Route::get('/messages/{message}/info', [MessengerApiController::class, 'info'])->whereNumber('message')->name('message.info');

        Route::get('/calls', [MessengerCallApiController::class, 'log'])->name('calls');
        Route::post('/calls', [MessengerCallApiController::class, 'start'])->name('calls.start');
        Route::post('/calls/{call}/answer', [MessengerCallApiController::class, 'answer'])->name('calls.answer');
        Route::post('/calls/{call}/decline', [MessengerCallApiController::class, 'decline'])->name('calls.decline');
        Route::post('/calls/{call}/end', [MessengerCallApiController::class, 'end'])->name('calls.end');
    });

    Route::get('/files/{file}', [MessengerApiController::class, 'file'])->whereNumber('file')->name('file');

    /* ⛔ the audit door — key `messaging.audit`, a written reason, the audit trail ([[MessengerAuditController]]) */
    Route::get('/audit', [MessengerAuditController::class, 'index'])->name('audit.index');
    Route::post('/audit/{thread}', [MessengerAuditController::class, 'open'])->name('audit.open');
    Route::get('/audit/{thread}', [MessengerAuditController::class, 'show'])->name('audit.show');
});
