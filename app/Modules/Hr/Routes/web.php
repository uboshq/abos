<?php

declare(strict_types=1);

use App\Modules\Hr\Http\Controllers\EmployeeController;
use App\Modules\Hr\Http\Controllers\PayrollController;
use App\Modules\Hr\Http\Controllers\PayslipPrintController;
use App\Modules\Hr\Http\Controllers\SalaryHeadController;
use App\Modules\Hr\Http\Controllers\SalaryStructureController;
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

        Route::get('/{employee}', [EmployeeController::class, 'show'])->whereNumber('employee')->name('show');
        Route::get('/{employee}/edit', [EmployeeController::class, 'edit'])->whereNumber('employee')->name('edit');
        Route::put('/{employee}', [EmployeeController::class, 'update'])->whereNumber('employee')->name('update');
        Route::delete('/{employee}', [EmployeeController::class, 'destroy'])->whereNumber('employee')->name('destroy');
    });

    /*
     * বেতনের রান।
     *
     * ব্যাংক ফাইলের ঠিকানা {run}-এর নিচে, কারণ ফাইলটা একটা রানেরই —
     * আলাদা কোনো পর্দা নেই, রানের পাতা থেকেই নামানো হয়।
     */
    Route::prefix('payroll')->name('payroll.')->group(function () {
        Route::get('/', [PayrollController::class, 'index'])->name('index');
        Route::get('/create', [PayrollController::class, 'create'])->name('create');
        Route::post('/', [PayrollController::class, 'store'])->name('store');
        Route::get('/{run}', [PayrollController::class, 'show'])->whereNumber('run')->name('show');
        Route::post('/{run}/rebuild', [PayrollController::class, 'rebuild'])->whereNumber('run')->name('rebuild');
        Route::post('/{run}/confirm', [PayrollController::class, 'confirm'])->whereNumber('run')->name('confirm');
        Route::post('/{run}/cancel', [PayrollController::class, 'cancel'])->whereNumber('run')->name('cancel');
        Route::get('/{run}/bank-file', [PayrollController::class, 'bankFile'])->whereNumber('run')->name('bank_file');
        Route::get('/{run}/payslips', [PayslipPrintController::class, 'all'])->whereNumber('run')->name('payslips');
    });

    Route::get('/payslips/{payslip}/print', [PayslipPrintController::class, 'one'])
        ->whereNumber('payslip')->name('payslip.print');

    Route::prefix('salary-heads')->name('salary_head.')->group(function () {
        Route::get('/', [SalaryHeadController::class, 'index'])->name('index');
        Route::get('/create', [SalaryHeadController::class, 'create'])->name('create');
        Route::post('/', [SalaryHeadController::class, 'store'])->name('store');
        Route::post('/install-defaults', [SalaryHeadController::class, 'installDefaults'])->name('install');
        Route::get('/{head}/edit', [SalaryHeadController::class, 'edit'])->whereNumber('head')->name('edit');
        Route::put('/{head}', [SalaryHeadController::class, 'update'])->whereNumber('head')->name('update');
        Route::delete('/{head}', [SalaryHeadController::class, 'destroy'])->whereNumber('head')->name('destroy');
    });
});
