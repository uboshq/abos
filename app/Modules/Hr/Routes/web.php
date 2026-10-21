<?php

declare(strict_types=1);

use App\Modules\Hr\Http\Controllers\AttendanceController;
use App\Modules\Hr\Http\Controllers\EmployeeController;
use App\Modules\Hr\Http\Controllers\ExpenseClaimController;
use App\Modules\Hr\Http\Controllers\HolidayController;
use App\Modules\Hr\Http\Controllers\GratuityController;
use App\Modules\Hr\Http\Controllers\HeldWageController;
use App\Modules\Hr\Http\Controllers\HrReportController;
use App\Modules\Hr\Http\Controllers\LeaveController;
use App\Modules\Hr\Http\Controllers\OvertimeController;
use App\Modules\Hr\Http\Controllers\PayrollController;
use App\Modules\Hr\Http\Controllers\PayslipPrintController;
use App\Modules\Hr\Http\Controllers\ProvidentFundController;
use App\Modules\Hr\Http\Controllers\SalaryHeadController;
use App\Modules\Hr\Http\Controllers\SalaryStructureController;
use App\Modules\Hr\Http\Controllers\TaxCertificateController;
use App\Modules\Hr\Http\Controllers\WppfController;
use Illuminate\Support\Facades\Route;

/*
 * HR-এর রুট। নামের উপসর্গ "hr." প্রোভাইডার নিজে বসায়।
 */

Route::middleware('auth')->prefix('hr')->group(function () {

    Route::prefix('employees')->name('employee.')->group(function () {
        Route::get('/', [EmployeeController::class, 'index'])->name('index');
        Route::get('/create', [EmployeeController::class, 'create'])->name('create');
        Route::post('/', [EmployeeController::class, 'store'])->name('store');

        /*
         * বেতনের কাঠামোর ঠিকানা {employee}-এর নিচে, কারণ কাঠামোটা
         * কর্মীরই — আলাদা মেনু সারি নেই, কর্মীর পর্দা থেকেই যাওয়া হয়।
         */
        Route::get('/{employee}/salary', [SalaryStructureController::class, 'edit'])
            ->whereNumber('employee')->name('salary');
        Route::post('/{employee}/salary', [SalaryStructureController::class, 'store'])
            ->whereNumber('employee')->name('salary.store');

        // ⭐ বছরের কর-সনদ — বেতনের চাবি আর নাগাল (a6, ২১ অক্টোবর ২০২৬; [[TaxCertificateController]])
        Route::get('/{employee}/tax-certificate', [TaxCertificateController::class, 'show'])
            ->whereNumber('employee')->name('tax_certificate');

        Route::get('/{employee}', [EmployeeController::class, 'show'])->whereNumber('employee')->name('show');
        Route::get('/{employee}/edit', [EmployeeController::class, 'edit'])->whereNumber('employee')->name('edit');
        Route::put('/{employee}', [EmployeeController::class, 'update'])->whereNumber('employee')->name('update');
        Route::delete('/{employee}', [EmployeeController::class, 'destroy'])->whereNumber('employee')->name('destroy');

        // ⭐ বেতনের ঠিকানার বদলে দ্বিতীয় সই — #৮, ২০ অক্টোবর ২০২৬ ([[PaymentChanges]])
        Route::post('/{employee}/payment-change/{change}/approve', [EmployeeController::class, 'approvePaymentChange'])
            ->whereNumber('employee')->whereNumber('change')->name('payment_change.approve');
        Route::post('/{employee}/payment-change/{change}/reject', [EmployeeController::class, 'rejectPaymentChange'])
            ->whereNumber('employee')->whereNumber('change')->name('payment_change.reject');
    });

    /*
     * বেতনের রান।
     *
     * ব্যাংক ফাইলের ঠিকানা {run}-এর নিচে, কারণ ফাইলটা একটা রানেরই —
     * আলাদা কোনো পর্দা নেই, রানের পাতা থেকেই নামানো হয়।
     */
    /*
     * ⭐ খরচের দাবি আর অগ্রিম অনুরোধ — মালিকের আদেশ, ৭ অক্টোবর ২০২৬ ([[ExpenseClaimController]])। সই সইয়ের বাক্সে, টাকা
     * ক্যাশিয়ার দেন ভাউচারের পর্দা থেকে।
     */
    Route::prefix('claims')->name('claim.')->group(function () {
        Route::get('/', [ExpenseClaimController::class, 'index'])->name('index');
        Route::get('/create', [ExpenseClaimController::class, 'create'])->name('create');
        Route::post('/', [ExpenseClaimController::class, 'store'])->name('store');
        // ⓘ নিজের দাবি, বা সবার-চাবি আর নাগাল ([[ExpenseClaimPolicy]])
        Route::get('/{claim}', [ExpenseClaimController::class, 'show'])->whereNumber('claim')->middleware('can:view,claim')->name('show');
        // ⭐ বাকি অগ্রিম নগদে ফেরত — দাবি দেখতে পারেন আর ভাউচার লেখেন এমন মানুষ (টাকার পরিকল্পনা দফা ১৩, ধাপ ৪)
        Route::post('/{claim}/take-back', [ExpenseClaimController::class, 'takeBackAdvance'])->whereNumber('claim')
            ->middleware(['can:view,claim', 'can:accounts.voucher.create'])->name('take_back');
    });

    Route::prefix('payroll')->name('payroll.')->group(function () {
        Route::get('/', [PayrollController::class, 'index'])->name('index');
        Route::get('/create', [PayrollController::class, 'create'])->name('create');
        Route::post('/', [PayrollController::class, 'store'])->name('store');
        Route::get('/{run}', [PayrollController::class, 'show'])->whereNumber('run')->name('show');
        Route::post('/{run}/rebuild', [PayrollController::class, 'rebuild'])->whereNumber('run')->name('rebuild');
        Route::post('/{run}/confirm', [PayrollController::class, 'confirm'])->whereNumber('run')->name('confirm');
        Route::post('/{run}/cancel', [PayrollController::class, 'cancel'])->whereNumber('run')->name('cancel');
        Route::get('/{run}/bank-file', [PayrollController::class, 'bankFile'])->whereNumber('run')->name('bank_file');
        // ⭐ ব্যাংক ফাইলের সঙ্গী-তালিকা — কারা বাদ আর কেন (fe, ২১ অক্টোবর ২০২৬)
        Route::get('/{run}/bank-file/left-out', [PayrollController::class, 'leftOut'])->whereNumber('run')->name('bank_file_left_out');
        // ⭐ দেওয়ার অপেক্ষায় বকেয়া মজুরি — যেকোনো দিন, ব্যাংক বা MFS (fe, ২১ অক্টোবর ২০২৬; [[HeldWageController]])
        Route::get('/held', [HeldWageController::class, 'index'])->name('held.index');
        Route::post('/held/pay/{channel}', [HeldWageController::class, 'pay'])->whereIn('channel', ['bank', 'mfs'])->name('held.pay');
        Route::get('/held/paper/{voucher}', [HeldWageController::class, 'paper'])->whereNumber('voucher')->name('held.paper');
        // ⭐ বাতিল রানের ব্যাংক ফাইলের লাইন — "ব্যাংক এই লাইন দেয়নি" আর তার দ্বিতীয় সই (fe-র সিদ্ধান্ত ২, ২১ অক্টোবর ২০২৬)
        Route::post('/held/bank-unpaid/{payment}', [HeldWageController::class, 'bankUnpaid'])->whereNumber('payment')->name('held.bank_unpaid');
        Route::post('/held/bank-unpaid-line/{line}/sign', [HeldWageController::class, 'signBankUnpaid'])->whereNumber('line')->name('held.bank_unpaid_sign');
        Route::get('/{run}/payslips', [PayslipPrintController::class, 'all'])->whereNumber('run')->name('payslips');
    });

    Route::get('/payslips/{payslip}/print', [PayslipPrintController::class, 'one'])
        ->whereNumber('payslip')->name('payslip.print');

    /*
     * হাজিরা — একটা দিনের পর্দা, আর সেই দিনের সবার সারি একসাথে সংরক্ষণ।
     *
     * মাসিক গ্রিড নয়: ত্রিশ দিন × বিশ জন মানে ছয়শো ঘর, আর ফোনে সেটা
     * ভরা যায় না। গুদামের লোক সকালে একবার আজকের পর্দাটা খুলে সবার
     * হাজিরা বসান — সেটাই আসল কাজের ধরন।
     */
    Route::prefix('attendance')->name('attendance.')->group(function () {
        Route::get('/', [AttendanceController::class, 'index'])->name('index');
        Route::post('/', [AttendanceController::class, 'store'])->name('store');
        Route::get('/sheet', [AttendanceController::class, 'sheet'])->name('sheet');
    });

    Route::prefix('leave')->name('leave.')->group(function () {
        Route::get('/', [LeaveController::class, 'index'])->name('index');
        Route::get('/create', [LeaveController::class, 'create'])->name('create');
        Route::post('/', [LeaveController::class, 'store'])->name('store');
        Route::post('/{application}/approve', [LeaveController::class, 'approve'])
            ->whereNumber('application')->name('approve');
        Route::post('/{application}/reject', [LeaveController::class, 'reject'])
            ->whereNumber('application')->name('reject');
        Route::post('/{application}/cancel', [LeaveController::class, 'cancel'])
            ->whereNumber('application')->name('cancel');
        // ⭐ প্রসবের আসল দিন — লেখা, তারপর "সই দিন" বা প্রত্যাখ্যান (ধারা ৪৫; fe-র উত্তর ৩; [[MaternityDeliveryService]])
        Route::post('/{application}/delivery', [LeaveController::class, 'recordDelivery'])
            ->whereNumber('application')->name('delivery');
        Route::post('/deliveries/{delivery}/sign', [LeaveController::class, 'signDelivery'])
            ->whereNumber('delivery')->name('delivery_sign');
        Route::post('/deliveries/{delivery}/reject', [LeaveController::class, 'rejectDelivery'])
            ->whereNumber('delivery')->name('delivery_reject');
    });

    /*
     * ⭐ ছুটির দিনের তালিকা — সরকারি ছুটি আর উৎসব, সব শাখার বা একটা শাখার (শ্রম আইন ধারা ১১৮; [[HolidayController]]; a6, ২১ অক্টোবর ২০২৬)।
     * ⓘ চাবি নিয়ামকে: দেখা `hr.attendance.view` বা `hr.leave.view`, লেখা কেবল `hr.attendance.manage`; শাখার দেয়ালও সেখানে।
     */
    Route::prefix('holidays')->name('holiday.')->group(function () {
        Route::get('/', [HolidayController::class, 'index'])->name('index');
        Route::post('/', [HolidayController::class, 'store'])->name('store');
        // ⭐ শাখার সাপ্তাহিক ছুটি (ধারা ১০৩, ১১৪; fe, ২১ অক্টোবর ২০২৬)
        Route::post('/weekly-off', [HolidayController::class, 'storeWeeklyOff'])->name('weekly_off.store');
        Route::get('/{holiday}/edit', [HolidayController::class, 'edit'])->whereNumber('holiday')->name('edit');
        Route::put('/{holiday}', [HolidayController::class, 'update'])->whereNumber('holiday')->name('update');
        Route::delete('/{holiday}', [HolidayController::class, 'destroy'])->whereNumber('holiday')->name('destroy');
    });

    Route::prefix('leave-types')->name('leave_type.')->group(function () {
        Route::get('/', [LeaveController::class, 'types'])->name('index');
        Route::post('/', [LeaveController::class, 'storeType'])->name('store');
        Route::post('/install-defaults', [LeaveController::class, 'installTypes'])->name('install');
    });

    /*
     * ⭐ HR-এর রিপোর্ট — হাজিরার সারাংশ, বেতনশিট, কর্মী সংখ্যা, ছুটির জের (ঘাটতির তালিকা #১৬; [[HrReportController]])।
     * ⓘ চাবি প্রতিটা স্লাগের নিজের, পদ্ধতির ভেতরে।
     */
    Route::get('/reports/{slug}', [HrReportController::class, 'show'])->name('report.show');

    /*
     * ⭐ শ্রমিকের মুনাফায় অংশগ্রহণ তহবিল — বছরের হিসাব আর "সই দিন" (শ্রম আইন ২০০৬, অধ্যায় ১৫; a6, ২১ অক্টোবর ২০২৬; [[WppfController]])।
     * ⛔ কোম্পানির সুইচ বন্ধ থাকলে দুইটাই ৪০৪।
     */
    Route::get('/wppf', [WppfController::class, 'show'])->name('wppf.show');
    Route::post('/wppf/{year}/provision', [WppfController::class, 'provision'])->whereNumber('year')->name('wppf.provision');

    Route::prefix('salary-heads')->name('salary_head.')->group(function () {
        Route::get('/', [SalaryHeadController::class, 'index'])->name('index');
        Route::get('/create', [SalaryHeadController::class, 'create'])->name('create');
        Route::post('/', [SalaryHeadController::class, 'store'])->name('store');
        Route::post('/install-defaults', [SalaryHeadController::class, 'installDefaults'])->name('install');
        Route::get('/{head}/edit', [SalaryHeadController::class, 'edit'])->whereNumber('head')->name('edit');
        Route::put('/{head}', [SalaryHeadController::class, 'update'])->whereNumber('head')->name('update');
        Route::delete('/{head}', [SalaryHeadController::class, 'destroy'])->whereNumber('head')->name('destroy');
    });

    /*
     * ⭐ ভবিষ্য তহবিলের প্রস্তাবিত বদল — দেখা বেতনের চাবিতে, বসানো বেতন বদলানোর চাবিতে (a6, ২১ অক্টোবর ২০২৬; [[ProvidentFundController]])।
     */
    /*
     * ⭐ অধিকালের শিট — হাজিরার চাবিতে তালিকা, হাজিরা বদলানোর চাবিতে বানানো, রিপোর্টিং লাইনে সই (BLA ধারা ১০৮; a6, ২১ অক্টোবর ২০২৬;
     * [[OvertimeController]])।
     */
    Route::prefix('overtime')->name('overtime.')->group(function () {
        Route::get('/', [OvertimeController::class, 'index'])->name('index');
        Route::post('/', [OvertimeController::class, 'store'])->name('store');
        Route::post('/{sheet}/sign', [OvertimeController::class, 'sign'])->whereNumber('sheet')->name('sign');
    });

    Route::prefix('provident-fund')->name('pf.')->group(function () {
        Route::get('/', [ProvidentFundController::class, 'proposal'])->name('proposal');
        Route::post('/', [ProvidentFundController::class, 'store'])->name('store');
        // ⭐ একজনকে তহবিলে যোগ — আলাদা কাজ, নিজে থেকে নয় (BLA ধারা ২৬৪; fe, দফা ১)
        Route::post('/{employee}/enrol', [ProvidentFundController::class, 'enrol'])->whereNumber('employee')->name('enrol');
    });

    // ⭐ গ্র্যাচুইটি — রিপোর্ট বেতনের চাবিতে, মাসের সঞ্চিতি বেতনের রান চালানোর চাবিতে (fe, দফা ৮; [[GratuityController]])
    Route::prefix('gratuity')->name('gratuity.')->group(function () {
        Route::get('/', [GratuityController::class, 'report'])->name('report');
        Route::post('/', [GratuityController::class, 'store'])->name('store');
    });
});
