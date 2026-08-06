<?php

declare(strict_types=1);

use App\Modules\SystemAdmin\Http\Controllers\ControlPanelController;
use App\Modules\SystemAdmin\Http\Controllers\CustomFieldController;
use App\Modules\SystemAdmin\Http\Controllers\ImportController;
use Illuminate\Support\Facades\Route;

/*
 * System Administration-এর রুট।
 *
 * এখন শুধু Control Panel — কোম্পানি, ব্যবহারকারী, রোল ও ব্যাকআপের
 * পর্দাগুলো এখনো লেখা হয়নি, আর module.php-তে সেগুলো planned হিসেবেই
 * আছে। মেনুতে মৃত সারি রাখা হয় না।
 */

Route::middleware('auth')->prefix('system')->group(function () {
    Route::get('/control-panel', [ControlPanelController::class, 'edit'])->name('control-panel');
    Route::put('/control-panel', [ControlPanelController::class, 'update'])->name('control-panel.update');

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
