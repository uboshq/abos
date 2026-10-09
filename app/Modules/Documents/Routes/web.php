<?php

declare(strict_types=1);

use App\Modules\Documents\Http\Controllers\DocumentAdminController;
use App\Modules\Documents\Http\Controllers\DocumentBinController;
use App\Modules\Documents\Http\Controllers\DocumentController;
use App\Modules\Documents\Http\Controllers\DocumentFileController;
use App\Modules\Documents\Http\Controllers\DocumentGrantController;
use App\Modules\Documents\Http\Controllers\DocumentVersionController;
use App\Modules\Documents\Http\Controllers\PlanController;
use App\Modules\Documents\Services\DocumentAdministration;
use App\Modules\Documents\Support\DocumentPlan;
use Illuminate\Support\Facades\Route;

/*
 * ডকুমেন্ট ম্যানেজমেন্ট — প্রথম ধাপের আসল পর্দা, বাকিগুলো পরিকল্পনার পাতা।
 *
 * ⓘ রুটের নাম `documents.` দিয়ে শুরু — [[ModuleServiceProvider]] নিজেই বসায়।
 * ⓘ গোটা দলের দরজা `documents.view`; প্রতিটা কাজের নিজের চাবি তার রুটে — কাগজ ছাড়া
 * কাজে অনুমতির নাম (`can:documents.upload`), কাগজের উপর কাজে পলিসি
 * (`can:download,document` → [[DocumentPolicy]])।
 *
 * ⛔ `{document}` আসে তিন দেয়াল পেরিয়ে ([[Document::resolveRouteBinding()]]): অন্য
 * কোম্পানি, অন্য শাখা বা না-দেখার গোপনীয়তা — তিনটাই ৪০৪, একই রকম।
 *
 * ⚠️ `{screen}` সবার শেষে আর কেবল পরিকল্পনার নামগুলো চেনে ([[DocumentPlan::SCREENS]]);
 * আসল পর্দার ঠিকানা (center, mine, recent, upload) ঐ তালিকায় আর নেই।
 */
Route::middleware(['auth', 'can:documents.view'])->prefix('documents')->group(function () {
    Route::get('/', [PlanController::class, 'dashboard'])->name('dashboard');

    Route::get('/center', [DocumentController::class, 'index'])->name('index');
    Route::get('/mine', [DocumentController::class, 'mine'])->name('mine');
    Route::get('/recent', [DocumentController::class, 'recent'])->name('recent');

    // ⭐ দ্বিতীয় ধাপ (৯ অক্টোবর ২০২৬) — আর্কাইভ, রিসাইকেল বিন, বিস্তারিত খোঁজ, প্রশাসন
    Route::get('/archive', [DocumentController::class, 'archived'])->name('archived');
    Route::get('/search', [DocumentController::class, 'search'])->name('search');

    Route::get('/recycle', [DocumentController::class, 'bin'])->name('bin');
    // ⓘ মোছা কাগজ সাধারণ `{document}` চেনে না — নিজের দরজা, পলিসি কন্ট্রোলারে (`restore`, `forceDelete`)
    Route::post('/recycle/{document}/restore', [DocumentBinController::class, 'restore'])
        ->whereNumber('document')->middleware('can:documents.restore')->name('bin.restore');
    Route::delete('/recycle/{document}', [DocumentBinController::class, 'purge'])
        ->whereNumber('document')->middleware('can:documents.purge')->name('bin.purge');

    Route::middleware('can:documents.admin')->prefix('/admin')->group(function () {
        Route::get('/', [DocumentAdminController::class, 'index'])->name('admin');
        Route::post('/{kind}', [DocumentAdminController::class, 'store'])
            ->whereIn('kind', DocumentAdministration::KINDS)->name('admin.store');
        Route::post('/{kind}/{id}/toggle', [DocumentAdminController::class, 'toggle'])
            ->whereIn('kind', DocumentAdministration::KINDS)->whereNumber('id')->name('admin.toggle');
    });

    Route::get('/upload', [DocumentController::class, 'create'])
        ->middleware('can:documents.upload')->name('create');
    Route::post('/upload', [DocumentController::class, 'store'])
        ->middleware('can:documents.upload')->name('store');

    Route::prefix('/{document}')->whereNumber('document')->group(function () {
        Route::get('/', [DocumentController::class, 'show'])
            ->middleware('can:view,document')->name('show');
        Route::get('/edit', [DocumentController::class, 'edit'])
            ->middleware('can:update,document')->name('edit');
        Route::put('/', [DocumentController::class, 'update'])
            ->middleware('can:update,document')->name('update');
        Route::delete('/', [DocumentController::class, 'destroy'])
            ->middleware('can:delete,document')->name('destroy');

        Route::post('/archive', [DocumentController::class, 'archive'])
            ->middleware('can:archive,document')->name('archive');
        Route::post('/unarchive', [DocumentController::class, 'unarchive'])
            ->middleware('can:unarchive,document')->name('unarchive');

        Route::get('/preview', [DocumentFileController::class, 'preview'])
            ->middleware('can:view,document')->name('preview');
        Route::get('/download', [DocumentFileController::class, 'download'])
            ->middleware('can:download,document')->name('download');
        Route::get('/print', [DocumentFileController::class, 'print'])
            ->middleware('can:print,document')->name('print');

        // ⭐ কাগজ-ধরে অধিকার (§১৩) — ⓘ scopeBindings: অধিকারটা এই কাগজেরই
        Route::post('/access', [DocumentGrantController::class, 'store'])
            ->middleware('can:grant,document')->name('grant.store');
        Route::delete('/access/{grant}', [DocumentGrantController::class, 'destroy'])
            ->whereNumber('grant')->scopeBindings()
            ->middleware('can:grant,document')->name('grant.destroy');

        Route::post('/versions', [DocumentVersionController::class, 'store'])
            ->middleware('can:addVersion,document')->name('version.store');

        // ⓘ scopeBindings — ভার্সনটা ঐ কাগজেরই হতে হবে, অন্য কাগজের id দিলে ৪০৪
        Route::get('/versions/{version}/download', [DocumentFileController::class, 'version'])
            ->whereNumber('version')->scopeBindings()
            ->middleware('can:download,document')->name('version.download');
        Route::post('/versions/{version}/restore', [DocumentVersionController::class, 'restore'])
            ->whereNumber('version')->scopeBindings()
            ->middleware('can:restoreVersion,document')->name('version.restore');
    });

    Route::get('/{screen}', [PlanController::class, 'show'])
        ->whereIn('screen', DocumentPlan::screenSlugs())
        ->name('screen');
});
