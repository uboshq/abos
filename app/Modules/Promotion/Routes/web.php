<?php

declare(strict_types=1);

use App\Modules\Promotion\Http\Controllers\PromotionBudgetController;
use App\Modules\Promotion\Http\Controllers\PromotionCalendarController;
use App\Modules\Promotion\Http\Controllers\PromotionController;
use App\Modules\Promotion\Http\Controllers\PromotionGiftController;
use App\Modules\Promotion\Http\Controllers\PromotionOverrideController;
use App\Modules\Promotion\Http\Controllers\PromotionReportController;
use App\Modules\Promotion\Http\Controllers\PromotionSuggestController;
use Illuminate\Support\Facades\Route;

/*
 * মডিউলের নিজের রুট — ModuleServiceProvider নিজে নিবন্ধন করে, তাই
 * `routes/web.php`-তে এই ফাইলের কোনো উল্লেখ নেই।
 *
 * ⓘ নামের উপসর্গ `promotion.` আপনাআপনি বসে, মডিউলের `code` থেকে।
 * ⚠️ তাই `module.php`-তে ঘোষিত `promotion.index` আর এখানকার `index`
 * একই জিনিস — দুই জায়গায় দুইবার লেখা নয়।
 */
Route::middleware('auth')->prefix('promotions')->group(function () {
    Route::get('/', [PromotionController::class, 'index'])->name('index');
    Route::get('/new', [PromotionController::class, 'create'])->name('create');

    /*
     * ⭐ বিক্রয়ের পর্দা যে প্রশ্নটা করে — `/{promotion}`-এর **আগে**।
     *
     * ⚠️ পরে বসালে `suggest` শব্দটাকে Laravel একটা অফারের কী ভাবত, আর
     * মডেল খুঁজে না পেয়ে ৪০৪ দিত — পর্দা নীরবে ধরে নিত *"কোনো অফার নেই"*।
     */
    Route::get('/suggest', PromotionSuggestController::class)->name('suggest');

    /* ⓘ ক্যালেন্ডার (§১৬) আর প্রতিবেদন (§১৭) — `/{promotion}`-এর আগে, যাতে শব্দগুলো অফারের কী না ধরা হয় */
    Route::get('/calendar', PromotionCalendarController::class)->name('calendar');
    Route::get('/reports/{slug}', [PromotionReportController::class, 'show'])->name('report.show');

    /* ⓘ হাতে বদল — `/{promotion}`-এর আগে, যাতে `applications` শব্দটা অফারের কী না ধরা হয় */
    Route::post('/applications/{application}/override', PromotionOverrideController::class)->name('override');

    /* ⓘ উপহার দেওয়া — `/{promotion}`-এর আগে, যাতে `gifts` শব্দটা অফারের কী না ধরা হয় */
    Route::get('/gifts', [PromotionGiftController::class, 'index'])->name('gift.index');
    Route::post('/applications/{application}/gifts', [PromotionGiftController::class, 'store'])->name('gift.store');

    /*
     * ⭐ কুপন আর পয়েন্টের পর্দা — `/{promotion}`-এর আগে, নইলে "coupons" একটা
     * অফারের আইডি ধরা হত (২৮ সেপ্টেম্বর ২০২৬)।
     * ⓘ চাবি দরজার নিজের: `promotion.coupon`, `promotion.loyalty`, আর কাউন্টারের
     * কোড যাচাই `promotion.apply` ([[PromotionCouponController]])।
     */
    Route::get('/coupons', [\App\Modules\Promotion\Http\Controllers\PromotionCouponController::class, 'index'])->name('coupon.index');
    Route::post('/coupons/redeem', [\App\Modules\Promotion\Http\Controllers\PromotionCouponController::class, 'redeem'])->name('coupon.redeem');
    Route::get('/loyalty', [\App\Modules\Promotion\Http\Controllers\PromotionLoyaltyController::class, 'index'])->name('loyalty.index');
    Route::get('/loyalty/{customer}', [\App\Modules\Promotion\Http\Controllers\PromotionLoyaltyController::class, 'show'])
        ->whereNumber('customer')->name('loyalty.show');

    Route::post('/', [PromotionController::class, 'store'])->name('store');

    /*
     * ⭐ অবস্থার দরজাগুলো — প্রতিটা `POST`, `GET` নয়।
     *
     * ⚠️ `GET` হলে একটা লিংক খুললেই অফার চালু হয়ে যেত — ব্রাউজারের
     * আগাম-লোড, একটা বুকমার্ক, বা কারও পাঠানো লিংক। ⛔ টাকা নড়ে এমন
     * কাজ কখনো কেবল একটা ঠিকানা খোলায় ঘটবে না।
     */
    Route::get('/{promotion}', [PromotionController::class, 'show'])->name('show');

    /* ⓘ নিয়ম — কেবল খসড়ায় বদলানো যায়; সেবা থামায়, রুট নয় */
    Route::post('/{promotion}/steps', [PromotionController::class, 'addStep'])->name('step.store');
    Route::delete('/{promotion}/steps/{step}', [PromotionController::class, 'removeStep'])->name('step.destroy');
    Route::post('/{promotion}/scopes', [PromotionController::class, 'addScope'])->name('scope.store');
    Route::delete('/{promotion}/scopes/{scope}', [PromotionController::class, 'removeScope'])->name('scope.destroy');

    Route::post('/{promotion}/submit', [PromotionController::class, 'submit'])->name('submit');
    Route::post('/{promotion}/approve', [PromotionController::class, 'approve'])->name('approve');
    Route::post('/{promotion}/send-back', [PromotionController::class, 'sendBack'])->name('send_back');
    Route::post('/{promotion}/withdraw', [PromotionController::class, 'withdraw'])->name('withdraw');
    Route::post('/{promotion}/activate', [PromotionController::class, 'activate'])->name('activate');
    Route::post('/{promotion}/pause', [PromotionController::class, 'pause'])->name('pause');
    Route::post('/{promotion}/cancel', [PromotionController::class, 'cancel'])->name('cancel');
    Route::post('/{promotion}/reschedule', [PromotionController::class, 'reschedule'])->name('reschedule');

    /* ⓘ ছাদ — প্রতিটা ধরনে একটা; নতুন অঙ্ক পুরনোটা বদলায়, নিরীক্ষা পুরনোটা রাখে */
    Route::post('/{promotion}/budgets', PromotionBudgetController::class)->name('budget.store');

    /* ⭐ কুপনের কোড আর কম্বোর উপাদান — অফারের নিজের পাতা থেকে */
    Route::post('/{promotion}/coupons', [\App\Modules\Promotion\Http\Controllers\PromotionCouponController::class, 'store'])->name('coupon.store');
    Route::post('/{promotion}/combo', [\App\Modules\Promotion\Http\Controllers\PromotionComboController::class, 'store'])->name('combo.store');
    Route::delete('/{promotion}/combo/{item}', [\App\Modules\Promotion\Http\Controllers\PromotionComboController::class, 'destroy'])
        ->whereNumber('item')->name('combo.destroy');
});
