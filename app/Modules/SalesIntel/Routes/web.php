<?php

declare(strict_types=1);

use App\Modules\SalesIntel\Http\Controllers\SoonController;
use Illuminate\Support\Facades\Route;

/*
 * ⭐ সেলস ইন্টেলিজেন্স — এখন একটাই পথ: প্রতিটা মেনুর "শীঘ্রই আসছে" পাতা (মালিক, ২৮ নভেম্বর ২০২৬)।
 * ⓘ `{page}` কেবল জানা পাতাগুলোর একটা ([[SoonController::PAGES]]) — অজানা হলে 404।
 */
Route::middleware(['auth', 'can:sales_intel.view'])->prefix('sales-intel')->group(function () {
    Route::get('/{page}', SoonController::class)->where('page', '[a-z0-9-]+')->name('soon');
});
