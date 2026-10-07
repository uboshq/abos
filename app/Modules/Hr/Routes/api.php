<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AuthController;
use App\Http\Middleware\RefuseModulesOffOnThePhone;
use App\Http\Middleware\ResolveCompanyContext;
use App\Modules\Hr\Http\Controllers\ExpenseClaimController;
use Illuminate\Support\Facades\Route;

/*
 * HR-এর ফোন-দরজা — `/api/v1/hr/…` (মালিকের আদেশ, ৭ অক্টোবর ২০২৬: খরচের দাবি আর অগ্রিম অনুরোধ ফোন থেকে)।
 *
 * ⓘ বিক্রয়ের ফোন-দরজার হুবহু পাহারা ([[Sales/Routes/api.php]]): টোকেন, `abilities:app`, কোম্পানির প্রসঙ্গ, আর ফোনে HR বন্ধ থাকলে
 * ৪০৩ `module_off`। চাবি দেখে কন্ট্রোলার নিজে; একটা দাবির পাতা নীতি দেখে ([[ExpenseClaimPolicy]])। সই আগের সইয়ের বাক্সেই
 * (`/api/v1/approvals/…`)।
 */
Route::prefix('v1/hr')
    ->middleware([
        'auth:sanctum',
        'abilities:'.AuthController::APP,
        ResolveCompanyContext::class,
        RefuseModulesOffOnThePhone::class.':hr',
    ])
    ->group(function (): void {
        Route::get('/claims/heads', [ExpenseClaimController::class, 'apiHeads'])->name('claim.heads');
        Route::get('/claims', [ExpenseClaimController::class, 'apiIndex'])->name('claim.index');
        Route::post('/claims', [ExpenseClaimController::class, 'apiStore'])->middleware(\App\Http\Middleware\RemembersAPhoneWrite::class)->name('claim.store');
        Route::get('/claims/{claim:public_id}', [ExpenseClaimController::class, 'apiShow'])->middleware('can:view,claim')->name('claim.show');
    });
