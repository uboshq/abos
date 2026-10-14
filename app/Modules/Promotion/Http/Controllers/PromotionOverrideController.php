<?php

declare(strict_types=1);

namespace App\Modules\Promotion\Http\Controllers;

use App\Core\Services\DataScope;
use App\Http\Controllers\Controller;
use App\Models\UserDataScope;
use App\Modules\Promotion\Models\PromotionApplication;
use App\Modules\Promotion\Services\PromotionDesk;
use App\Modules\Promotion\Support\Decimal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * একটা বিলে বসা অফারের সুবিধা হাতে বদলানো — স্পেক §১৯।
 *
 * ⛔ `promotion.override` — `apply` নয়। ⓘ অফার বসানো বিক্রয়কর্মীর রোজকার
 * কাজ; সুবিধার অঙ্ক বদলানো নিয়ম ভাঙা। ⚠️ এক চাবি হলে যিনি অফার বসাতে
 * পারেন তিনি ছাড়ের অঙ্কও বদলাতে পারতেন — স্পেকের কথায় *"সাধারণ
 * salesperson অনুমতি ছাড়া promotion value পরিবর্তন করতে পারবে না"*।
 */
final class PromotionOverrideController extends Controller implements HasMiddleware
{
    public function __construct(private readonly PromotionDesk $desk) {}

    public static function middleware(): array
    {
        return [new Middleware('can:promotion.override')];
    }

    public function __invoke(Request $request, PromotionApplication $application): RedirectResponse
    {
        /*
         * ⛔ নাগালের বাইরের শাখার কাগজের অফার বদলানো নয় — ঠিকানায় অন্য শাখার সারির নম্বর বসিয়েও না (Promotion লাইন-ধরা
         * নিরীক্ষা, ১৪ অক্টোবর ২০২৬; [[AnOverrideStaysInsideMyBranchTest]])। ⓘ উপহারের দরজার একই পাহারা
         * ([[PromotionGiftController::store()]]); আগে এখানে ছিল না, তাই ময়মনসিংহে সীমিত মানুষ নেত্রকোনার বিলের ছাড় বদলাতে পারতেন।
         */
        abort_unless(app(DataScope::class)->allows($request->user(), UserDataScope::BRANCH,
            $application->branch_id === null ? null : (int) $application->branch_id), 403);

        $data = $request->validate([
            'worth' => ['required', 'numeric', Decimal::RULE, 'gte:0'],

            /*
             * ⚠️ কারণ বাধ্যতামূলক, আর অন্তত কয়েকটা অক্ষর।
             *
             * ⓘ `required` একা থাকলে একটা ফাঁকা স্থান বা একটা বিন্দুও চলত।
             * ⛔ তখন কারণের ঘরটা থাকত, অথচ কিছুই বলত না — ঘোষিত অথচ অচল
             * পাহারার আরেক রূপ।
             */
            'override_reason' => ['required', 'string', 'min:5', 'max:300'],
        ]);

        $this->desk->override($application, (string) $data['worth'], $data['override_reason']);

        return back()->with('saved', __('promotion::message.overridden'));
    }
}
