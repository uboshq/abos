<?php

declare(strict_types=1);

use App\Modules\Mail\Http\Controllers\MailAdminController;
use App\Modules\Mail\Http\Controllers\MailController;
use App\Modules\Mail\Http\Controllers\MailHistoryController;
use App\Modules\Mail\Http\Controllers\MailSignatureController;
use App\Modules\Mail\Http\Controllers\MailTemplateController;
use Illuminate\Support\Facades\Route;

/*
 * ⭐ মেইল — আলাদা মডিউল, নিজের ট্যাবে (মালিক, ২৬ নভেম্বর ২০২৬, নমুনা v4 "ok")। ⓘ চাবি নিয়ামকের ভিতরে; মেইলবক্সে ঢোকা [[MailAccess]]।
 */
Route::middleware(['auth'])->prefix('mail')->group(function () {
    Route::get('/', [MailController::class, 'index'])->name('index');
    Route::get('/accounts', [MailController::class, 'accounts'])->name('accounts');
    Route::get('/party', [MailController::class, 'party'])->name('party');
    Route::post('/parties', [MailController::class, 'parties'])->name('parties');
    Route::get('/compose', [MailController::class, 'compose'])->name('compose');
    Route::get('/unread', [MailController::class, 'unread'])->name('unread');
    Route::post('/trust', [MailController::class, 'trust'])->name('trust');
    Route::post('/accounts', [MailController::class, 'addAccount'])->name('accounts.add');
    Route::post('/accounts/{account}/decide', [MailController::class, 'decide'])->whereUuid('account')->name('accounts.decide');
    Route::post('/accounts/{account}/off', [MailController::class, 'off'])->whereUuid('account')->name('accounts.off');

    // ⭐ সই — নিজের ছবি, নকশা, লিংক (নমুনা v4 পর্দা ৪)
    Route::get('/signature', [MailSignatureController::class, 'edit'])->name('signature');
    Route::post('/signature', [MailSignatureController::class, 'update'])->name('signature.update');
    Route::get('/signature/preview/{style}', [MailSignatureController::class, 'preview'])->whereNumber('style')->name('signature.preview');

    // ⭐ টেমপ্লেট (পর্দা ৫) আর উৎসব ও দিবস (পর্দা ৬)
    Route::get('/templates', [MailTemplateController::class, 'index'])->name('templates');
    Route::post('/templates', [MailTemplateController::class, 'store'])->name('templates.store');
    Route::get('/templates/{template}/preview', [MailTemplateController::class, 'preview'])->whereUuid('template')->name('templates.preview');
    Route::put('/templates/{template}', [MailTemplateController::class, 'update'])->whereUuid('template')->name('templates.update');
    Route::post('/templates/{template}/copy', [MailTemplateController::class, 'copy'])->whereUuid('template')->name('templates.copy');
    Route::delete('/templates/{template}', [MailTemplateController::class, 'destroy'])->whereUuid('template')->name('templates.destroy');
    Route::post('/festivals/{festival}', [MailTemplateController::class, 'festival'])->whereNumber('festival')->name('festivals.update');

    // ⭐ প্রশাসন — নিজের ডোমেইনের ঠিকানা (পর্দা ৭–১২)
    Route::get('/addresses', [MailAdminController::class, 'index'])->name('addresses');
    Route::post('/addresses', [MailAdminController::class, 'store'])->name('addresses.store');
    Route::put('/addresses/{address}', [MailAdminController::class, 'update'])->whereUuid('address')->name('addresses.update');
    Route::post('/addresses/{address}/members', [MailAdminController::class, 'members'])->whereUuid('address')->name('addresses.members');
    Route::post('/addresses/{address}/password', [MailAdminController::class, 'password'])->whereUuid('address')->name('addresses.password');
    Route::post('/addresses/{address}/pause', [MailAdminController::class, 'pause'])->whereUuid('address')->name('addresses.pause');
    Route::post('/addresses/{address}/resume', [MailAdminController::class, 'resume'])->whereUuid('address')->name('addresses.resume');
    Route::post('/addresses/{address}/delete', [MailAdminController::class, 'delete'])->whereUuid('address')->name('addresses.delete');
    Route::post('/addresses/{address}/restore', [MailAdminController::class, 'restore'])->whereUuid('address')->name('addresses.restore');
    Route::get('/addresses/export', [MailAdminController::class, 'export'])->name('addresses.export');
    Route::post('/addresses/import', [MailAdminController::class, 'import'])->name('addresses.import');
    Route::post('/addresses/{address}/auto-reply', [MailAdminController::class, 'autoReply'])->whereUuid('address')->name('addresses.auto_reply');

    // ⭐ খাতা — ইতিহাস (সব বদল) আর পাঠানো মেইল
    Route::get('/history', [MailHistoryController::class, 'index'])->name('history');
    Route::get('/sent', [MailHistoryController::class, 'sent'])->name('sent');

    Route::get('/{account}/folders', [MailController::class, 'folders'])->whereUuid('account')->name('folders');
    Route::get('/{account}/list', [MailController::class, 'list'])->whereUuid('account')->name('list');
    Route::get('/{account}/read', [MailController::class, 'read'])->whereUuid('account')->name('read');
    Route::get('/{account}/file', [MailController::class, 'file'])->whereUuid('account')->name('file');
    Route::get('/{account}/raw', [MailController::class, 'raw'])->whereUuid('account')->name('raw');
    Route::post('/{account}/flag', [MailController::class, 'flag'])->whereUuid('account')->name('flag');
    Route::post('/{account}/move', [MailController::class, 'move'])->whereUuid('account')->name('move');
    Route::post('/{account}/bulk', [MailController::class, 'bulk'])->whereUuid('account')->name('bulk');
    Route::post('/{account}/link', [MailController::class, 'link'])->whereUuid('account')->name('link');
    Route::post('/{account}/send', [MailController::class, 'send'])->whereUuid('account')->name('send');
});
