<?php

declare(strict_types=1);

use App\Modules\Customer\Http\Controllers\CustomerController;
use Illuminate\Support\Facades\Route;

/*
 * মডিউলের নিজের রুট — ModuleServiceProvider নিজে থেকে নিবন্ধন করে
 * (সেকশন ১৯.৩), তাই routes/web.php-তে এই ফাইলের কোনো উল্লেখ নেই।
 *
 * নামের উপসর্গ "customer." আপনাআপনি বসে, কারণ প্রোভাইডার মডিউলের code
 * দিয়ে name() ঠিক করে দেয়। module.php-তে ঘোষিত রুটের নামগুলো তাই
 * এখানকার নামের সাথে মেলে।
 */

Route::middleware('auth')->prefix('customers')->group(function () {
    Route::get('/', [CustomerController::class, 'index'])->name('index');
    Route::get('/create', [CustomerController::class, 'create'])->name('create');
    Route::post('/', [CustomerController::class, 'store'])->name('store');
    Route::get('/{customer}', [CustomerController::class, 'show'])->name('show');
    Route::get('/{customer}/edit', [CustomerController::class, 'edit'])->name('edit');
    Route::put('/{customer}', [CustomerController::class, 'update'])->name('update');
    Route::delete('/{customer}', [CustomerController::class, 'destroy'])->name('destroy');
});
