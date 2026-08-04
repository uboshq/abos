<?php

declare(strict_types=1);

use App\Modules\Supplier\Http\Controllers\SupplierController;
use App\Modules\Supplier\Http\Controllers\SupplierReportController;
use Illuminate\Support\Facades\Route;

/*
 * মডিউলের নিজের রুট — ModuleServiceProvider নিজে থেকে নিবন্ধন করে
 * (সেকশন ১৯.৩), তাই routes/web.php-তে এই ফাইলের কোনো উল্লেখ নেই।
 *
 * নামের উপসর্গ "supplier." আপনাআপনি বসে, কারণ প্রোভাইডার মডিউলের code
 * দিয়ে name() ঠিক করে দেয়।
 */

Route::middleware('auth')->prefix('suppliers')->group(function () {
    Route::get('/', [SupplierController::class, 'index'])->name('index');
    Route::get('/create', [SupplierController::class, 'create'])->name('create');
    Route::post('/', [SupplierController::class, 'store'])->name('store');

    /*
     * রিপোর্টের রুট {supplier}-এর আগে, আর {supplier} সংখ্যায় বাঁধা।
     *
     * দুইটাই দরকার: আগে না রাখলে /suppliers/reports/ageing কে একটা id
     * ভেবে রুট-মডেল বাইন্ডিং ৪০৪ দিত, আর whereNumber ছাড়া ভবিষ্যতে
     * যোগ হওয়া যেকোনো স্থির পথ একই ফাঁদে পড়ত।
     */
    Route::get('/reports/{slug}', [SupplierReportController::class, 'show'])->name('report.show');

    Route::get('/{supplier}', [SupplierController::class, 'show'])
        ->whereNumber('supplier')->name('show');
    Route::get('/{supplier}/edit', [SupplierController::class, 'edit'])
        ->whereNumber('supplier')->name('edit');
    Route::put('/{supplier}', [SupplierController::class, 'update'])
        ->whereNumber('supplier')->name('update');
    Route::delete('/{supplier}', [SupplierController::class, 'destroy'])
        ->whereNumber('supplier')->name('destroy');
    Route::post('/{supplier}/activate', [SupplierController::class, 'activate'])
        ->whereNumber('supplier')->name('activate');
});
