<?php

declare(strict_types=1);

use App\Modules\Governance\Http\Controllers\AuditController;
use App\Modules\Governance\Http\Controllers\ExportLogController;
use Illuminate\Support\Facades\Route;

/*
 * শুধু পড়ার রুট — POST, PUT বা DELETE নেই।
 *
 * অডিটে লেখার কোনো পথ পর্দা থেকে থাকা উচিত নয়, তাই সেই পথটা এখানে
 * নেই-ই। মডেলেও নিষেধ বসানো আছে, কিন্তু দুইটা পাহারা একটার চেয়ে ভালো।
 */
Route::middleware('auth')->prefix('governance')->group(function () {
    Route::get('/audit', [AuditController::class, 'index'])->name('audit.index');

    Route::get('/audit/{trail}', [AuditController::class, 'show'])
        ->whereNumber('trail')->name('audit.show');

    /*
     * একটা রেকর্ডের পুরো ইতিহাস।
     *
     * ঠিকানায় ক্লাসের নাম যায় না — সেটা ভেতরের কথা, আর URL-এ থাকলে
     * কেউ যেকোনো ক্লাসের নাম বসিয়ে দেখতে পারত। বদলে অডিটের সারির id
     * ধরে গিয়ে সেখান থেকে রেকর্ডটা চেনা হয়।
     */
    Route::get('/audit/{trail}/record', [AuditController::class, 'record'])
        ->whereNumber('trail')->name('audit.record');

    /*
     * রপ্তানির খাতা — কে কোন তালিকা নামিয়ে নিয়ে গেছে।
     *
     * অডিটের পাশেই, কারণ দুইটাই একই প্রশ্নের দুই দিক: একটা বলে কী
     * বদলেছে, অন্যটা বলে কী বেরিয়ে গেছে।
     */
    Route::get('/exports', [ExportLogController::class, 'index'])->name('export.index');
});
