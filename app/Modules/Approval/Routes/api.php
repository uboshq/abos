<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AuthController;
use App\Http\Middleware\RefuseModulesOffOnThePhone;
use App\Http\Middleware\ResolveCompanyContext;
use App\Modules\Approval\Http\Controllers\ApprovalDelegationApiController;
use Illuminate\Support\Facades\Route;

/*
 * অনুমোদনের ফোন-দরজা — `/api/v1/approvals/delegations` (মালিক, ১৭ অক্টোবর ২০২৬: "direct webpage app e bujajayna")।
 *
 * ⓘ মডিউলের নিজের ফাইল, তাই পাহারা হাতে — `routes/api.php`-এর অনুমোদন-দরজার হুবহু: টোকেন, `abilities:app`, কোম্পানির প্রসঙ্গ,
 * আর ফোনে অনুমোদন বন্ধ থাকলে ৪০৩ `module_off`। চাবি `approval.decide` কন্ট্রোলারে ([[ApprovalDelegationApiController]])।
 * ⚠️ পথটা এক অংশের (`/approvals/delegations`) — মূল ফাইলের `/approvals/{approval}/…` দুই অংশের, তাই কেউ কাউকে গিলে না।
 */
Route::prefix('v1/approvals')
    ->middleware([
        'auth:sanctum',
        'throttle:app',
        'abilities:'.AuthController::APP,
        ResolveCompanyContext::class,
        RefuseModulesOffOnThePhone::class.':approval',
    ])
    ->group(function (): void {
        Route::get('/delegations', [ApprovalDelegationApiController::class, 'index'])->name('delegation.index');
        Route::post('/delegations', [ApprovalDelegationApiController::class, 'store'])
            ->middleware(\App\Http\Middleware\RemembersAPhoneWrite::class)->name('delegation.store');
        Route::delete('/delegations/{id}', [ApprovalDelegationApiController::class, 'destroy'])->whereUuid('id')->name('delegation.destroy');
    });
