<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AuthController;
use App\Http\Middleware\RefuseModulesOffOnThePhone;
use App\Http\Middleware\RemembersAPhoneWrite;
use App\Http\Middleware\ResolveCompanyContext;
use App\Modules\CreditControl\Http\Controllers\CreditControlApiController;
use Illuminate\Support\Facades\Route;

/*
 * ⭐ বাকি নিয়ন্ত্রণের ফোন-দরজা — `/api/v1/credit-control/…` (মালিক, ১৩ অক্টোবর ২০২৬: "app e baki module & menu")।
 *
 * ⓘ বিক্রয়ের ফোন-দরজার হুবহু পাহারা ([[Sales/Routes/api.php]]): টোকেন, `abilities:app`, কোম্পানির প্রসঙ্গ, আর ফোনে বাকি
 * নিয়ন্ত্রণ বন্ধ থাকলে ৪০৩ `module_off`। চাবি ওয়েবের একই — দেখা `credit_control.view`, অনুরোধ `credit_control.request_limit`,
 * বাকি বন্ধ তোলা গ্রাহকের `update` নীতি আর চেক ফেরতের বন্ধ কেবল মালিক ([[CustomerService::clearCreditBlock()]])।
 */
Route::prefix('v1/credit-control')
    ->middleware([
        'auth:sanctum',
        'throttle:app',
        'abilities:'.AuthController::APP,
        ResolveCompanyContext::class,
        RefuseModulesOffOnThePhone::class.':credit_control',
    ])
    ->group(function (): void {
        Route::get('/dashboard', [CreditControlApiController::class, 'dashboard'])->middleware('can:credit_control.view')->name('dashboard');
        Route::get('/lists/{list}', [CreditControlApiController::class, 'list'])
            ->where('list', '[a-z\-]+')->middleware('can:credit_control.view')->name('list');
        Route::get('/customers/{id}', [CreditControlApiController::class, 'show'])
            ->whereUuid('id')->middleware('can:credit_control.view')->name('customer.show');
        Route::post('/customers/{id}/limit-requests', [CreditControlApiController::class, 'requestLimit'])
            ->whereUuid('id')->middleware(['can:credit_control.request_limit', RemembersAPhoneWrite::class])->name('limit_request.store');
        Route::post('/customers/{id}/lift-block', [CreditControlApiController::class, 'liftBlock'])
            ->whereUuid('id')->middleware(['can:credit_control.view', RemembersAPhoneWrite::class])->name('lift_block');
    });
