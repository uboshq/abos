<?php

declare(strict_types=1);

use App\Modules\Finance\Http\Controllers\BudgetController;
use App\Modules\Finance\Http\Controllers\CfoController;
use App\Modules\Finance\Http\Controllers\ForecastController;
use Illuminate\Support\Facades\Route;

/*
 * ফিন্যান্সের পরিকল্পনা — বাজেট, নগদের পূর্বাভাস, CFO ড্যাশবোর্ড।
 * মানচিত্রের §১, §৮, §১৬, §২৯; ২০ সেপ্টেম্বর ২০২৬।
 *
 * ⓘ নিজের ফাইলে, web.php-এর শেষে `require` করা। ⚠️ কারণ web.php একসাথে
 * কয়েকজন বদলায়; এক ফাইলে থাকলে কমিটে একজনের অর্ধেক কাজ আরেকজনের সাথে
 * চলে যেত। নাম-উপসর্গ (`finance.`) web.php-এর গ্রুপ থেকেই আসে।
 */
Route::middleware('auth')->prefix('finance')->group(function () {
    Route::prefix('budget')->name('budget.')->group(function () {
        Route::get('/', [BudgetController::class, 'index'])->name('index');
        Route::get('/actual', [BudgetController::class, 'actual'])->name('actual');
        Route::get('/centers', [BudgetController::class, 'centers'])->name('centers');
        Route::get('/report', [BudgetController::class, 'report'])->name('report');
        Route::get('/create', [BudgetController::class, 'create'])->name('create');
        Route::post('/', [BudgetController::class, 'store'])->name('store');
    });

    Route::get('/cash-forecast', [ForecastController::class, 'cash'])->name('forecast.cash');
    Route::get('/cfo', [CfoController::class, 'index'])->name('cfo');
});
