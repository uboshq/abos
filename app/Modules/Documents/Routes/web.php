<?php

declare(strict_types=1);

use App\Modules\Documents\Http\Controllers\PlanController;
use App\Modules\Documents\Support\DocumentPlan;
use Illuminate\Support\Facades\Route;

/*
 * ডকুমেন্ট ম্যানেজমেন্ট — কেবল পড়ার পাতা, তাই কেবল GET (৩০ সেপ্টেম্বর ২০২৬)।
 *
 * ⓘ রুটের নাম `documents.` দিয়ে শুরু — [[ModuleServiceProvider]] নিজেই বসায়।
 * ⚠️ `{screen}` কেবল পরিকল্পনার বিশটা নাম চেনে ([[DocumentPlan::SCREENS]]);
 * ঠিকানায় যা খুশি লিখে একটা "পর্দা" বানানো যায় না — বাকি সব ৪০৪।
 */
Route::middleware(['auth', 'can:documents.view'])->prefix('documents')->group(function () {
    Route::get('/', [PlanController::class, 'dashboard'])->name('dashboard');

    Route::get('/{screen}', [PlanController::class, 'show'])
        ->whereIn('screen', DocumentPlan::screenSlugs())
        ->name('screen');
});
