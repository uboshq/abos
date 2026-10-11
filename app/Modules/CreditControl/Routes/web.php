<?php

declare(strict_types=1);

use App\Modules\CreditControl\Http\Controllers\CreditLimitRequestController;
use App\Modules\CreditControl\Http\Controllers\CreditProfileController;
use App\Modules\CreditControl\Http\Controllers\CreditReportController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->prefix('credit-control')->group(function () {

    // ⭐ ড্যাশবোর্ডের প্রতিটা সংখ্যার তালিকা ([[CreditPositionReports]]) — নাম `*.report.show`, যাতে রিপোর্টের পাহারা দরজাটা চেনে
    Route::get('/reports/{slug}', [CreditReportController::class, 'show'])->name('report.show');

    // ⭐ গ্রাহকের বাকির পাতা (ধাপ ১.৩)
    Route::get('/customers/{customer}', [CreditProfileController::class, 'show'])
        ->whereNumber('customer')
        ->name('customer.show')
        ->middleware('can:credit_control.view');

    // ⭐ বাকির দিন — বাকির পাতা থেকে, কারণসহ (ধাপ ২.২); চাবি নিয়ন্ত্রকের ভিতরে (`update` নীতি)
    Route::post('/customers/{customer}/credit-days', [CreditProfileController::class, 'creditDays'])
        ->whereNumber('customer')
        ->name('customer.credit_days')
        ->middleware('can:credit_control.view');

    // ⭐ সীমা বাড়ানোর অনুরোধ (হিসাবরক্ষক/ম্যানেজার) আর মালিকের সরাসরি বসানো (ধাপ ২.৫); চাবি সেবার ভিতরে
    Route::post('/customers/{customer}/limit-requests', [CreditLimitRequestController::class, 'store'])
        ->whereNumber('customer')
        ->name('customer.limit_request')
        ->middleware('can:credit_control.request_limit');
    Route::post('/customers/{customer}/limit', [CreditLimitRequestController::class, 'direct'])
        ->whereNumber('customer')
        ->name('customer.limit_direct')
        ->middleware('can:credit_control.view');
});
