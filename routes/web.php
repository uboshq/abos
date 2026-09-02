<?php

declare(strict_types=1);

use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\MfaController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SavedViewController;
use App\Http\Controllers\ModuleDashboardController;
use App\Http\Controllers\WorkspaceController;
use Illuminate\Support\Facades\Route;

/*
 * শেলের নিজের রুট। মডিউলের রুট এখানে নয় — প্রতিটা মডিউল নিজের
 * Routes/web.php রাখে আর ModuleServiceProvider সেটা নিজে থেকে নিবন্ধন করে
 * (সেকশন ১৯.৩)। এখানে মডিউলের নাম লিখলে "কোর না ছুঁয়ে নতুন মডিউল"
 * কথাটাই মিথ্যা হয়ে যায়।
 */

Route::middleware('auth')->group(function () {
    Route::get('/', [WorkspaceController::class, 'dashboard'])->name('dashboard');

    /*
     * মডিউলের নিজস্ব ড্যাশবোর্ড — মালিকের নির্দেশ, ২ সেপ্টেম্বর ২০২৬।
     *
     * ── কেন `/dashboard/{module}`, `/inventory/dashboard` নয় ────────
     * দ্বিতীয়টা দেখতে সুন্দর, কিন্তু তাতে বারোটা মডিউলের রুট ফাইলে
     * বারোটা প্রায়-একই লাইন লিখতে হত, আর নতুন মডিউল ওটা লিখতে ভুলে
     * গেলে ড্যাশবোর্ডটা **নীরবে থাকত না**।
     *
     * এখানে রুট একটাই, আর মডিউল কেবল `module.php`-তে ঘোষণা করে।
     * অনুমতি ইঞ্জিন দেখে, সংখ্যা ধরে ধরে।
     */
    Route::get('/dashboard/{module}', [ModuleDashboardController::class, 'show'])
        ->name('module.dashboard');
    Route::get('/components', [WorkspaceController::class, 'components'])->name('components');

    /*
     * ডকুমেন্টের কাগজপত্র — যেকোনো মডিউলের যেকোনো ডকুমেন্টে।
     *
     * শেলের রুট, কারণ কাগজ কোনো একটা মডিউলের জিনিস নয়। তবু এখানে
     * কোনো মডিউলের নাম নেই: কাগজটা কার, সেটা (source_type, id) জোড়া
     * থেকেই বেরোয় — ঠিক যেভাবে ড্রিল-ডাউন কাজ করে।
     */
    Route::post('/attachments', [AttachmentController::class, 'store'])->name('attachment.store');
    Route::get('/attachments/{attachment}', [AttachmentController::class, 'download'])
        ->whereNumber('attachment')->name('attachment.download');
    Route::delete('/attachments/{attachment}', [AttachmentController::class, 'destroy'])
        ->whereNumber('attachment')->name('attachment.destroy');

    /*
     * বিজ্ঞপ্তি — নিজের কোনো পাতা নেই, কেবল খোলা ও পড়া।
     *
     * খবরটা যেখানে নিয়ে যাওয়ার কথা সেখানেই নিয়ে যায়; "বিজ্ঞপ্তির
     * তালিকা" নামে আলাদা পাতা বানালে সেটা আরেকটা ইনবক্স হত।
     */
    Route::get('/notifications/{notification}', [NotificationController::class, 'open'])
        ->whereNumber('notification')->name('notifications.open');
    Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])
        ->name('notifications.read-all');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile');
    Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::post('/profile/avatar', [ProfileController::class, 'updateAvatar'])->name('profile.avatar');
    Route::delete('/profile/avatar', [ProfileController::class, 'removeAvatar'])->name('profile.avatar.remove');

    Route::get('/appearance', [WorkspaceController::class, 'appearance'])->name('appearance');
    Route::post('/appearance', [WorkspaceController::class, 'saveAppearance'])->name('appearance.save');

    /*
     * দুই ধাপের লগইন — নিজের অ্যাকাউন্টে, তাই নিজের পর্দা।
     *
     * প্রশাসকের হাতে দিলে সেটা "চালু করে দেওয়া" হত, আর যাঁর ফোনে অ্যাপ
     * নেই তিনি নিজের ব্যবস্থা থেকে বাইরে থাকতেন। চেহারা বা প্রোফাইলের
     * মতোই — নিজের সিদ্ধান্ত।
     */
    Route::get('/two-step', [MfaController::class, 'show'])->name('mfa');
    Route::post('/two-step', [MfaController::class, 'begin'])->name('mfa.begin');
    Route::post('/two-step/confirm', [MfaController::class, 'confirm'])->name('mfa.confirm');
    Route::delete('/two-step', [MfaController::class, 'destroy'])->name('mfa.destroy');

    /*
     * সংরক্ষিত দৃশ্য — নিজের ছাঁকনি, নাম দিয়ে রাখা।
     *
     * চেহারা, ভাষা বা প্রোফাইলের মতোই ব্যক্তিগত পছন্দ, তাই শেলের রুট।
     * একটা দৃশ্য যেকোনো তালিকার পর্দার হতে পারে, তাই কোনো একটা মডিউলের
     * ভেতরে রাখলে ওটা ভুল জায়গা হত — ঠিক কাগজপত্রের মতোই।
     *
     * `index` নেই, আর সেটা ইচ্ছাকৃত — কারণটা কন্ট্রোলারে লেখা।
     */
    Route::post('/views', [SavedViewController::class, 'store'])->name('views.store');
    Route::post('/views/{savedView}/default', [SavedViewController::class, 'makeDefault'])
        ->whereNumber('savedView')->name('views.default');
    Route::delete('/views/{savedView}', [SavedViewController::class, 'destroy'])
        ->whereNumber('savedView')->name('views.destroy');

    Route::post('/company/switch', [WorkspaceController::class, 'switchCompany'])->name('company.switch');
    Route::post('/branch/switch', [WorkspaceController::class, 'switchBranch'])->name('branch.switch');
    Route::post('/locale/switch', [WorkspaceController::class, 'switchLocale'])->name('locale.switch');
    Route::post('/theme/switch', [WorkspaceController::class, 'switchTheme'])->name('theme.switch');
});

require __DIR__.'/auth.php';
