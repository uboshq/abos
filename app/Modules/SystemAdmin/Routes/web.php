<?php

declare(strict_types=1);

use App\Modules\SystemAdmin\Http\Controllers\BackupController;
use App\Modules\SystemAdmin\Http\Controllers\CompanyController;
use App\Modules\SystemAdmin\Http\Controllers\ControlPanelController;
use App\Modules\SystemAdmin\Http\Controllers\CustomFieldController;
use App\Modules\SystemAdmin\Http\Controllers\ImportController;
use App\Modules\SystemAdmin\Http\Controllers\LookController;
use App\Modules\SystemAdmin\Http\Controllers\RoleController;
use App\Modules\SystemAdmin\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

/*
 * System Administration-এর রুট।
 *
 * ব্যাকআপের পর্দা এখনো লেখা হয়নি, আর module.php-তে সেটা planned
 * হিসেবেই আছে। মেনুতে মৃত সারি রাখা হয় না।
 */

Route::middleware('auth')->prefix('system')->group(function () {
    /*
     * ব্যাকআপ — দেখা যায়, নেওয়া যায়, ফিরিয়ে আনা যায় না।
     *
     * ফিরিয়ে আনার রুট ইচ্ছাকৃতভাবে নেই। `BackupService::restore()`
     * আছে, কিন্তু ফিরিয়ে আনা মানে আজকের সব কাজ মুছে ফেলা — একটা ভুল
     * ক্লিকের দাম গোটা দিনের বই। ওটা কমান্ড লাইনের কাজ, আর পর্দায়
     * থাকে কেবল নির্দেশটা।
     */
    Route::get('/backups', [BackupController::class, 'index'])->name('backup.index');
    Route::post('/backups', [BackupController::class, 'store'])->name('backup.store');

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
     * ব্যবহারকারী ও রোল।
     *
     * মোছার কোনো রুট নেই, দুইটাতেই — ব্যবহারকারীর নাম প্রতিটা বিলে ও
     * অডিটের সারিতে বসে আছে, আর রোল মুছলে যাঁরা ওটা ধরে আছেন তাঁরা
     * নীরবে সব অধিকার হারাতেন। নিষ্ক্রিয় করাই যথেষ্ট।
     */
    Route::prefix('users')->name('user.')->group(function () {
        Route::get('/', [UserController::class, 'index'])->name('index');
        Route::get('/create', [UserController::class, 'create'])->name('create');
        Route::post('/', [UserController::class, 'store'])->name('store');
        Route::get('/{user}/edit', [UserController::class, 'edit'])->whereNumber('user')->name('edit');
        Route::put('/{user}', [UserController::class, 'update'])->whereNumber('user')->name('update');
    });

    Route::prefix('roles')->name('role.')->group(function () {
        Route::get('/', [RoleController::class, 'index'])->name('index');
        Route::get('/create', [RoleController::class, 'create'])->name('create');
        Route::post('/', [RoleController::class, 'store'])->name('store');
        Route::get('/{role}/edit', [RoleController::class, 'edit'])->whereNumber('role')->name('edit');
        Route::put('/{role}', [RoleController::class, 'update'])->whereNumber('role')->name('update');
    });

    /*
     * কোম্পানির নিজের রূপ — থিম ইঞ্জিনের ধাপ ৩।
     *
     * ── মোছার রুট নেই, আর এখানে কারণটা আলাদা ─────────────────────────
     * অন্য পর্দাগুলোয় মোছা হয় না কারণ কাগজপত্র সারিটার নাম ধরে আছে।
     * এখানে কারণটা হলো: একটা রূপ মুছলে যাঁরা ওটা পরে আছেন তাঁদের
     * পর্দা ওই মুহূর্তে বদলে যেত, আর কেউ জানত না কেন। রূপ পুরনো হয়,
     * ব্যবহার বন্ধ হয় — কিন্তু সারিটা থাকে।
     *
     * ── প্রিভিউ POST, GET নয় ─────────────────────────────────────────
     * প্রিভিউ সেশন বদলায়, তাই ওটা একটা কাজ — দেখা নয়। GET রাখলে
     * উপরের speculationrules ব্লকটা মাউস ছোঁয়া মাত্র প্রিভিউ চালু
     * করে দিত, আর ব্যবহারকারী কিছু না করেই গোটা ERP অন্য রঙে দেখতেন।
     */
    Route::prefix('looks')->name('look.')->group(function () {
        Route::get('/', [LookController::class, 'index'])->name('index');
        Route::get('/create', [LookController::class, 'create'])->name('create');
        Route::post('/', [LookController::class, 'store'])->name('store');
        Route::post('/preview/stop', [LookController::class, 'previewStop'])->name('preview.stop');

        /*
         * আমদানি — POST, কারণ এটা একটা সারি বসায়।
         *
         * রপ্তানি GET, আর সেটাও ঠিক: সে কিছু বদলায় না। তবে পাতাটার
         * speculationrules ব্লকে ডাউনলোড-লিংকগুলো আগেই বাদ দেওয়া আছে
         * (`[download]`), তাই মাউস ছোঁয়া মাত্র ফাইলটা নেমে আসে না।
         */
        Route::post('/import', [LookController::class, 'import'])->name('import');
        Route::get('/{skin}/edit', [LookController::class, 'edit'])->whereNumber('skin')->name('edit');
        Route::put('/{skin}', [LookController::class, 'update'])->whereNumber('skin')->name('update');
        Route::post('/{skin}/publish', [LookController::class, 'publish'])
            ->whereNumber('skin')->name('publish');
        Route::get('/{skin}/export', [LookController::class, 'export'])
            ->whereNumber('skin')->name('export');
        Route::post('/{skin}/preview', [LookController::class, 'preview'])
            ->whereNumber('skin')->name('preview');
        Route::post('/{skin}/revert/{version}', [LookController::class, 'revert'])
            ->whereNumber('skin')->whereNumber('version')->name('revert');
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
