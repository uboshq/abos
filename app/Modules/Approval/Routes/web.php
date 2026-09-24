<?php

declare(strict_types=1);

use App\Modules\Approval\Http\Controllers\ApprovalDelegationController;
use App\Modules\Approval\Http\Controllers\ApprovalExceptionController;
use App\Modules\Approval\Http\Controllers\ApprovalFlowController;
use App\Modules\Approval\Http\Controllers\ApprovalInboxController;
use App\Modules\Approval\Http\Controllers\ApprovalLimitController;
use App\Modules\Approval\Http\Controllers\ApprovalReportController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->prefix('approvals')->group(function () {
    /*
     * স্থির পথ {approval}-এর আগে (সেকশন ১৯.৬)।
     *
     * নাহলে /approvals/mine-কে রাউটার একটা id ভাবত, আর "mine" নামের
     * অনুরোধ খুঁজতে গিয়ে ৪০৪ দিত।
     */
    Route::get('/', [ApprovalInboxController::class, 'index'])->name('inbox.index');
    Route::get('/mine', [ApprovalInboxController::class, 'mine'])->name('inbox.mine');

    /* ⭐ একসাথে অনেকগুলো — স্থির পথ, `{approval}`-এর আগে */
    Route::post('/bulk-approve', [ApprovalInboxController::class, 'bulkApprove'])->name('inbox.bulk');

    /*
     * রিপোর্ট — `{approval}` ধরার আগে, স্থির পথ আগে (সেকশন ১৯.৬)।
     *
     * নাহলে /approvals/reports-কে রাউটার একটা id ভাবত, আর "reports"
     * নামের অনুরোধ খুঁজতে গিয়ে ৪০৪ দিত।
     */
    Route::get('/reports/{slug}', [ApprovalReportController::class, 'show'])->name('report.show');

    /*
     * ⭐ সই দেওয়ার ভার — ২৪ সেপ্টেম্বর ২০২৬।
     *
     * ⓘ `{approval}`-এর আগে, স্থির পথ আগে — নাহলে "delegations"
     * একটা id ভেবে বাঁধাই ভাঙত।
     */
    Route::get('/delegations', [ApprovalDelegationController::class, 'index'])->name('delegation.index');
    Route::post('/delegations', [ApprovalDelegationController::class, 'store'])->name('delegation.store');
    Route::delete('/delegations/{delegation}', [ApprovalDelegationController::class, 'destroy'])
        ->whereNumber('delegation')->name('delegation.destroy');

    /*
     * ⭐ কর্তৃত্বের সীমা — ২৪ সেপ্টেম্বর ২০২৬।
     *
     * ⓘ `{approval}`-এর আগে, স্থির পথ আগে — নাহলে "limits"
     * একটা id ভেবে বাঁধাই ভাঙত।
     */
    Route::get('/limits', [ApprovalLimitController::class, 'index'])->name('limit.index');
    Route::post('/limits', [ApprovalLimitController::class, 'store'])->name('limit.store');
    Route::delete('/limits/{limit}', [ApprovalLimitController::class, 'destroy'])
        ->whereNumber('limit')->name('limit.destroy');

    /* ⛔ নিয়মের বাইরে — স্থির পথ, `{approval}`-এর আগে */
    Route::get('/exceptions', [ApprovalExceptionController::class, 'index'])->name('exception.index');

    Route::get('/flows', [ApprovalFlowController::class, 'index'])->name('flow.index');
    /* ⭐ কোথায় সই বসানো যায়, আর কোথায় বসানো আছে — মালিকের
       প্রশ্নের উত্তর, ২২ সেপ্টেম্বর ২০২৬। ℹ `{flow}`-এর আগে, নাহলে
       "coverage" একটা আইডি ভেবে বাঁধাই ভাঙত। */
    Route::get('/flows/coverage', [ApprovalFlowController::class, 'coverage'])->name('flow.coverage');
    Route::get('/flows/create', [ApprovalFlowController::class, 'create'])->name('flow.create');
    Route::post('/flows', [ApprovalFlowController::class, 'store'])->name('flow.store');
    Route::get('/flows/{flow}/edit', [ApprovalFlowController::class, 'edit'])
        ->whereNumber('flow')->name('flow.edit');
    Route::put('/flows/{flow}', [ApprovalFlowController::class, 'update'])
        ->whereNumber('flow')->name('flow.update');
    Route::delete('/flows/{flow}', [ApprovalFlowController::class, 'destroy'])
        ->whereNumber('flow')->name('flow.destroy');

    Route::get('/{approval}', [ApprovalInboxController::class, 'show'])
        ->whereNumber('approval')->name('inbox.show');
    Route::post('/{approval}/approve', [ApprovalInboxController::class, 'approve'])
        ->whereNumber('approval')->name('inbox.approve');
    Route::post('/{approval}/reject', [ApprovalInboxController::class, 'reject'])
        ->whereNumber('approval')->name('inbox.reject');
    Route::post('/{approval}/withdraw', [ApprovalInboxController::class, 'withdraw'])
        ->whereNumber('approval')->name('inbox.withdraw');

    /*
     * ⭐ সই অন্যের হাতে দেওয়া — ২২ সেপ্টেম্বর ২০২৬।
     *
     * ⓘ চাবিটা `approval.decide`-ই, আলাদা কিছু নয়: যিনি সই দিতে পারেন
     * কেবল তিনিই সেটা অন্যকে দিতে পারেন। ⛔ আলাদা চাবি দিলে এমন কেউ
     * কাগজ পাঠাতে পারতেন যিনি নিজে ওটায় সই দিতেই পারতেন না।
     */
    Route::post('/{approval}/forward', [ApprovalInboxController::class, 'forward'])
        ->whereNumber('approval')->name('inbox.forward');
});
