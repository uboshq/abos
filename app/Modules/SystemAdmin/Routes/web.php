<?php

declare(strict_types=1);

use App\Modules\SystemAdmin\Http\Controllers\CompanyController;
use App\Modules\SystemAdmin\Http\Controllers\ControlPanelController;
use App\Modules\SystemAdmin\Http\Controllers\CustomFieldController;
use App\Modules\SystemAdmin\Http\Controllers\ImportController;
use Illuminate\Support\Facades\Route;

/*
 * System Administration-এর রুট।
 *
 * ব্যবহারকারী, রোল ও ব্যাকআপের পর্দা এখনো লেখা হয়নি, আর module.php-তে
 * সেগুলো planned হিসেবেই আছে। মেনুতে মৃত সারি রাখা হয় না।
 */

Route::middleware('auth')->prefix('system')->group(function () {
    Route::get('/control-panel', [ControlPanelController::class, 'edit'])->name('control-panel');
    Route::put('/control-panel', [ControlPanelController::class, 'update'])->name('control-panel.update');

    /*
     * কোম্পানি ও শাখা।
     *
     * মোছার কোনো রুট নেই, ইচ্ছাকৃতভাবে — একটা কোম্পানি মানে তার প্রতিটা
     * বিল, চালান ও খতিয়ানের সারি। নিষ্ক্রিয় করা যায় (toggle), তাতে
     * সুইচার থেকে সরে যায় কিন্তু কাগজপত্র অক্ষত থাকে।
     */
    Route::prefix('companies')->name('company.')->group(function () {
        Route::get('/', [CompanyController::class, 'index'])->name('index');
        Route::get('/create', [CompanyController::class, 'create'])->name('create');
        Route::post('/', [CompanyController::class, 'store'])->name('store');
        Route::get('/{company}/edit', [CompanyController::class, 'edit'])->whereNumber('company')->name('edit');
        Route::put('/{company}', [CompanyController::class, 'update'])->whereNumber('company')->name('update');
        Route::post('/{company}/branches', [CompanyController::class, 'storeBranch'])
            ->whereNumber('company')->name('branch.store');
        Route::post('/{company}/toggle', [CompanyController::class, 'toggle'])
            ->whereNumber('company')->name('toggle');
    });

    /*
     * নিজস্ব ঘর — এক পর্দায় সব।
     *
     * ঘর সাজানো সেটিংসের কাজ, তাই এখানে; কিন্তু ঘরগুলো ব্যবহার হয়
     * গ্রাহক, পণ্য ও সরবরাহকারীর ফর্মে।
     */
    Route::prefix('custom-fields')->name('custom_field.')->group(function () {
        Route::get('/', [CustomFieldController::class, 'index'])->name('index');
        Route::post('/', [CustomFieldController::class, 'store'])->name('store');
        Route::put('/{field}', [CustomFieldController::class, 'update'])
            ->whereNumber('field')->name('update');
        Route::delete('/{field}', [CustomFieldController::class, 'destroy'])
            ->whereNumber('field')->name('destroy');
    });
});

/*
 * পুরনো খাতা থেকে আনা।
 *
 * template রুটটা {kind} নিয়ে, আর সেটা check/store-এর আগে — নাহলে
 * ভবিষ্যতে কোনো স্থির পথ যোগ করলে সেটাকে kind ভেবে ৪০৪ দিত।
 */
Route::middleware('auth')->prefix('import')->name('import.')->group(function () {
    Route::get('/', [ImportController::class, 'index'])->name('index');
    Route::get('/template/{kind}', [ImportController::class, 'template'])->name('template');
    Route::post('/check', [ImportController::class, 'check'])->name('check');
    Route::post('/', [ImportController::class, 'store'])->name('store');
});
