<?php

declare(strict_types=1);

use App\Modules\Approval\Http\Controllers\ApprovalFlowController;
use App\Modules\Approval\Http\Controllers\ApprovalInboxController;
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

    /*
     * রিপোর্ট — `{approval}` ধরার আগে, স্থির পথ আগে (সেকশন ১৯.৬)।
     *
     * নাহলে /approvals/reports-কে রাউটার একটা id ভাবত, আর "reports"
     * নামের অনুরোধ খুঁজতে গিয়ে ৪০৪ দিত।
     */
    Route::get('/reports/{slug}', [ApprovalReportController::class, 'show'])->name('report.show');

    Route::get('/flows', [ApprovalFlowController::class, 'index'])->name('flow.index');
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
});
