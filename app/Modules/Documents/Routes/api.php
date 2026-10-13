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
        Route::get('/{id}', [DocumentLibraryApiController::class, 'show'])->whereUuid('id')->name('library.show');
        Route::get('/{id}/file', [DocumentLibraryApiController::class, 'file'])->whereUuid('id')->name('library.file');
    });
