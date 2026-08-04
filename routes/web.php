<?php

declare(strict_types=1);

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

    Route::get('/appearance', [WorkspaceController::class, 'appearance'])->name('appearance');
    Route::post('/appearance', [WorkspaceController::class, 'saveAppearance'])->name('appearance.save');

    Route::post('/company/switch', [WorkspaceController::class, 'switchCompany'])->name('company.switch');
    Route::post('/locale/switch', [WorkspaceController::class, 'switchLocale'])->name('locale.switch');
});

require __DIR__.'/auth.php';
