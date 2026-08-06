<?php

declare(strict_types=1);

use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\ProfileController;
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

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile');
    Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::post('/profile/avatar', [ProfileController::class, 'updateAvatar'])->name('profile.avatar');
    Route::delete('/profile/avatar', [ProfileController::class, 'removeAvatar'])->name('profile.avatar.remove');

    Route::get('/appearance', [WorkspaceController::class, 'appearance'])->name('appearance');
    Route::post('/appearance', [WorkspaceController::class, 'saveAppearance'])->name('appearance.save');

    Route::post('/company/switch', [WorkspaceController::class, 'switchCompany'])->name('company.switch');
    Route::post('/branch/switch', [WorkspaceController::class, 'switchBranch'])->name('branch.switch');
    Route::post('/locale/switch', [WorkspaceController::class, 'switchLocale'])->name('locale.switch');
    Route::post('/theme/switch', [WorkspaceController::class, 'switchTheme'])->name('theme.switch');
});

require __DIR__.'/auth.php';
