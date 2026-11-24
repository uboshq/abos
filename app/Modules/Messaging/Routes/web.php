<?php

declare(strict_types=1);

use App\Modules\Messaging\Http\Controllers\MeetingController;
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
        /* ⓘ attach → "ERP কাগজ" (web only): a LIKE over each paper table — kept to a walking pace */
        Route::get('/papers/find', [MessengerApiController::class, 'paperSearch'])->middleware('throttle:60,1')->name('papers.find');
        Route::post('/forward', [MessengerApiController::class, 'forward'])->name('forward');
        Route::delete('/messages/{message}', [MessengerApiController::class, 'destroy'])->whereNumber('message')->name('message.delete');
        Route::get('/messages/{message}/info', [MessengerApiController::class, 'info'])->whereNumber('message')->name('message.info');

        Route::get('/calls', [MessengerCallApiController::class, 'log'])->name('calls');
        Route::get('/calls/people', [MessengerCallApiController::class, 'people'])->name('calls.people');
        Route::post('/calls', [MessengerCallApiController::class, 'start'])->name('calls.start');
        Route::post('/calls/{call}/answer', [MessengerCallApiController::class, 'answer'])->name('calls.answer');
        Route::post('/calls/{call}/decline', [MessengerCallApiController::class, 'decline'])->name('calls.decline');
        Route::post('/calls/{call}/end', [MessengerCallApiController::class, 'end'])->name('calls.end');

        /* meetings and group calls — one engine ([[MeetingService]]) */
        Route::get('/meetings', [MeetingController::class, 'mine'])->name('meetings');
        Route::post('/meetings/new/{key}', [MeetingController::class, 'start'])->whereUuid('key')->name('meetings.start');
        Route::get('/meetings/{meeting}', [MeetingController::class, 'state'])->whereUuid('meeting')->name('meetings.state');
        Route::post('/meetings/{meeting}/join', [MeetingController::class, 'join'])->whereUuid('meeting')->name('meetings.join');
        Route::post('/meetings/{meeting}/renew', [MeetingController::class, 'renew'])->whereUuid('meeting')->name('meetings.renew');
        Route::post('/meetings/{meeting}/leave', [MeetingController::class, 'leave'])->whereUuid('meeting')->name('meetings.leave');
        Route::post('/meetings/{meeting}/decline', [MeetingController::class, 'decline'])->whereUuid('meeting')->name('meetings.decline');
        Route::post('/meetings/{meeting}/end', [MeetingController::class, 'end'])->whereUuid('meeting')->name('meetings.end');
        Route::post('/meetings/{meeting}/invite', [MeetingController::class, 'invite'])->whereUuid('meeting')->name('meetings.invite');
        Route::post('/meetings/{meeting}/lobby', [MeetingController::class, 'lobby'])->whereUuid('meeting')->name('meetings.lobby');
        Route::post('/meetings/{meeting}/remove', [MeetingController::class, 'remove'])->whereUuid('meeting')->name('meetings.remove');
        Route::post('/meetings/{meeting}/role', [MeetingController::class, 'role'])->whereUuid('meeting')->name('meetings.role');
        Route::post('/meetings/{meeting}/set', [MeetingController::class, 'set'])->whereUuid('meeting')->name('meetings.set');
        Route::post('/meetings/{meeting}/mute', [MeetingController::class, 'mute'])->whereUuid('meeting')->name('meetings.mute');
        Route::post('/meetings/{meeting}/stop-share', [MeetingController::class, 'stopShare'])->whereUuid('meeting')->name('meetings.stop_share');
    });

    /* ⭐ a meeting's own page — its link (login required; the link alone gives only the lobby) */
    Route::get('/meetings/{meeting}', [MeetingController::class, 'page'])->whereUuid('meeting')->name('meeting');

    Route::get('/files/{file}', [MessengerApiController::class, 'file'])->whereNumber('file')->name('file');

    /* ⛔ the audit door — key `messaging.audit`, a written reason, the audit trail ([[MessengerAuditController]]) */
    Route::get('/audit', [MessengerAuditController::class, 'index'])->name('audit.index');
    Route::post('/audit/{thread}', [MessengerAuditController::class, 'open'])->name('audit.open');
    Route::get('/audit/{thread}', [MessengerAuditController::class, 'show'])->name('audit.show');
});
