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
        'throttle:app',
        'abilities:'.AuthController::APP,
        ResolveCompanyContext::class,
        RefuseModulesOffOnThePhone::class.':hr',
    ])
    ->group(function (): void {
        Route::get('/claims/heads', [ExpenseClaimController::class, 'apiHeads'])->name('claim.heads');
        Route::get('/claims', [ExpenseClaimController::class, 'apiIndex'])->name('claim.index');
        Route::post('/claims', [ExpenseClaimController::class, 'apiStore'])->middleware(\App\Http\Middleware\RemembersAPhoneWrite::class)->name('claim.store');
        Route::get('/claims/{claim:public_id}', [ExpenseClaimController::class, 'apiShow'])->middleware('can:view,claim')->name('claim.show');

        // ⭐ নিজের ছুটি আর নিজের বেতনের স্লিপ — ফোনে (মালিকের আদেশ, ১৩ অক্টোবর ২০২৬; [[LeaveApiController]], [[PayslipApiController]])
        Route::get('/leave', [\App\Modules\Hr\Http\Controllers\LeaveApiController::class, 'mine'])->name('leave.mine');
        Route::post('/leave', [\App\Modules\Hr\Http\Controllers\LeaveApiController::class, 'store'])->middleware(\App\Http\Middleware\RemembersAPhoneWrite::class)->name('leave.store');
        Route::get('/payslips', [\App\Modules\Hr\Http\Controllers\PayslipApiController::class, 'mine'])->name('payslip.mine');
        Route::get('/payslips/{id}', [\App\Modules\Hr\Http\Controllers\PayslipApiController::class, 'show'])->whereUuid('id')->name('payslip.show');

        // ⭐ হাজিরা আর ছুটির রেজিস্টার — নাগালের সব কর্মীর, ওয়েবের একই দেয়াল আর চাবি (মালিক, ১৭ অক্টোবর ২০২৬; [[HrRegisterApiController]])
        Route::get('/attendance', [\App\Modules\Hr\Http\Controllers\HrRegisterApiController::class, 'attendance'])->name('register.attendance');
        Route::get('/leave/register', [\App\Modules\Hr\Http\Controllers\HrRegisterApiController::class, 'leaves'])->name('register.leave');
        Route::post('/leave/register/{id}/approve', [\App\Modules\Hr\Http\Controllers\HrRegisterApiController::class, 'approve'])->whereUuid('id')->middleware(\App\Http\Middleware\RemembersAPhoneWrite::class)->name('register.leave_approve');
        Route::post('/leave/register/{id}/reject', [\App\Modules\Hr\Http\Controllers\HrRegisterApiController::class, 'reject'])->whereUuid('id')->middleware(\App\Http\Middleware\RemembersAPhoneWrite::class)->name('register.leave_reject');
    });
