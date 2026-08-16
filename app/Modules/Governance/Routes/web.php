<?php

declare(strict_types=1);

use App\Modules\Governance\Http\Controllers\AuditController;
use App\Modules\Governance\Http\Controllers\ExportLogController;
use App\Modules\Governance\Http\Controllers\LoginHistoryController;
use App\Modules\Governance\Http\Controllers\SessionController;
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

    /*
     * ঢোকার খাতা — কে ঢুকল, আর কে ঢুকতে চেয়ে পারল না।
     *
     * অডিটের পাশে, কারণ প্রশ্নটা একই: "কে কী করেছে"। অডিট বলে বিলে কী
     * বসেছে; এটা বলে লোকটা আদৌ ঢুকেছিল কি না।
     */
    Route::get('/logins', [LoginHistoryController::class, 'index'])->name('login.index');

    /*
     * নিজের খোলা সেশনগুলো — অনুমতি ছাড়া, কারণ এগুলো নিজেরই।
     *
     * ── কেন `governance.audit.view` লাগে না ─────────────────────────
     * "আমি কোথায় কোথায় লগইন আছি" প্রতিটা ব্যবহারকারীর নিজের প্রশ্ন,
     * প্রশাসনিক নয়। চাবির পেছনে রাখলে যাঁর সবচেয়ে বেশি দরকার — যে
     * কর্মী কাউন্টারে লগইন রেখে এসেছেন — তিনিই পৌঁছাতে পারতেন না।
     *
     * কেবল নিজেরটাই দেখা যায় ও বন্ধ করা যায়; অন্যেরটা নয়।
     */
    Route::get('/my-sessions', [SessionController::class, 'index'])->name('session.index');
    Route::delete('/my-sessions/others', [SessionController::class, 'destroyOthers'])->name('session.others');
    Route::delete('/my-sessions/{id}', [SessionController::class, 'destroy'])->name('session.destroy');
});
