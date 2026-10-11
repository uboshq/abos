<?php

declare(strict_types=1);

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
});
