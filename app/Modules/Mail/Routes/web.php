<?php

declare(strict_types=1);

use App\Modules\Mail\Http\Controllers\MailController;
use Illuminate\Support\Facades\Route;

/*
 * ⭐ মেইল — নিজের ট্যাবে (মালিক, ২৬ নভেম্বর ২০২৬)। ⓘ চাবি নিয়ামকের ভিতরে (`can:mail.use`); মেইলবক্সে ঢোকা [[MailAccess]]।
 */
Route::middleware(['auth'])->prefix('mail')->group(function () {
    Route::get('/', [MailController::class, 'index'])->name('index');
    Route::get('/accounts', [MailController::class, 'accounts'])->name('accounts');
    Route::get('/party', [MailController::class, 'party'])->name('party');
    Route::post('/accounts', [MailController::class, 'addAccount'])->name('accounts.add');
    Route::post('/accounts/{account}/decide', [MailController::class, 'decide'])->whereUuid('account')->name('accounts.decide');
    Route::post('/accounts/{account}/off', [MailController::class, 'off'])->whereUuid('account')->name('accounts.off');

    Route::get('/{account}/folders', [MailController::class, 'folders'])->whereUuid('account')->name('folders');
    Route::get('/{account}/list', [MailController::class, 'list'])->whereUuid('account')->name('list');
    Route::get('/{account}/read', [MailController::class, 'read'])->whereUuid('account')->name('read');
    Route::get('/{account}/file', [MailController::class, 'file'])->whereUuid('account')->name('file');
    Route::post('/{account}/flag', [MailController::class, 'flag'])->whereUuid('account')->name('flag');
    Route::post('/{account}/move', [MailController::class, 'move'])->whereUuid('account')->name('move');
    Route::post('/{account}/send', [MailController::class, 'send'])->whereUuid('account')->name('send');
});
