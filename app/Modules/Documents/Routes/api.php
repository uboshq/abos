<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AuthController;
use App\Http\Middleware\RefuseModulesOffOnThePhone;
use App\Http\Middleware\ResolveCompanyContext;
use App\Modules\Documents\Http\Controllers\DocumentLibraryApiController;
use Illuminate\Support\Facades\Route;

/*
 * ডকুমেন্টসের ফোন-দরজা — `/api/v1/documents/library/…` (মালিকের আদেশ, ১৩ অক্টোবর ২০২৬)।
 *
 * ⓘ `library` উপসর্গ ইচ্ছা করে: `/api/v1/documents/{type}/{id}/pdf|papers` আগে থেকেই ERP-কাগজের ছাপার দরজা
 * ([[DocumentApiController]] কোরে, `routes/api.php`) — একই আকারের ঠিকানা দুই অর্থ বহন না করুক।
 * পাহারা বিক্রয়ের ফোন-দরজার হুবহু; ফোনে ডকুমেন্টস বন্ধ থাকলে ৪০৩ `module_off`। সই অনুমোদনের দরজাতেই (`/api/v1/approvals/…`)।
 */
Route::prefix('v1/documents/library')
    ->middleware([
        'auth:sanctum',
        'throttle:app',
        'abilities:'.AuthController::APP,
        ResolveCompanyContext::class,
        RefuseModulesOffOnThePhone::class.':documents',
    ])
    ->group(function (): void {
        Route::get('/', [DocumentLibraryApiController::class, 'documents'])->name('library.list');
        // ⭐ ফোন থেকে তোলা আর ক্যামেরায় স্ক্যান — ওয়েবের তোলার একই নিয়ম ও সেবা (মালিক, ১৭ অক্টোবর ২০২৬)
        Route::get('/upload/setup', [DocumentLibraryApiController::class, 'uploadSetup'])->name('library.upload_setup');
        Route::post('/', [DocumentLibraryApiController::class, 'upload'])
            ->middleware(\App\Http\Middleware\RemembersAPhoneWrite::class)->name('library.upload');
        Route::get('/{id}', [DocumentLibraryApiController::class, 'show'])->whereUuid('id')->name('library.show');
        Route::get('/{id}/file', [DocumentLibraryApiController::class, 'file'])->whereUuid('id')->name('library.file');
    });

/*
 * ⭐ দলিলের অনুমোদনের সারি আর সই কেন্দ্র, ফোনের নিজের পর্দায় — মালিক, ১৭ অক্টোবর ২০২৬ ([[DocumentQueueApiController]])।
 * ⓘ লাইব্রেরির একই পাহারা; চাবি `documents.view` কন্ট্রোলারে। ⛔ সই/ফেরত এখানে নয় — ফোনের অনুমোদন-দরজায়, একই ইঞ্জিনে।
 */
Route::prefix('v1/documents')
    ->middleware([
        'auth:sanctum',
        'throttle:app',
        'abilities:'.AuthController::APP,
        ResolveCompanyContext::class,
        RefuseModulesOffOnThePhone::class.':documents',
    ])
    ->group(function (): void {
        Route::get('/approvals', [\App\Modules\Documents\Http\Controllers\DocumentQueueApiController::class, 'queue'])->name('approvals.queue');
        Route::get('/signatures', [\App\Modules\Documents\Http\Controllers\DocumentQueueApiController::class, 'signatures'])->name('signatures.center');
    });

/*
 * ⭐ দলিলের ছাঁচ ফোনে — কেবল তালিকা আর পড়া (মালিক, ১৭ অক্টোবর ২০২৬; [[DocumentTemplateApiController]])। লেখা ও বদল ওয়েবে।
 */
Route::prefix('v1/documents/templates')
    ->middleware([
        'auth:sanctum',
        'throttle:app',
        'abilities:'.AuthController::APP,
        ResolveCompanyContext::class,
        RefuseModulesOffOnThePhone::class.':documents',
    ])
    ->group(function (): void {
        Route::get('/', [\App\Modules\Documents\Http\Controllers\DocumentTemplateApiController::class, 'index'])->name('templates.list');
        Route::get('/{id}', [\App\Modules\Documents\Http\Controllers\DocumentTemplateApiController::class, 'show'])->whereUuid('id')->name('templates.read');
    });
