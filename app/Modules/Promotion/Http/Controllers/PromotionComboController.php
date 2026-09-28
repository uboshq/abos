<?php

declare(strict_types=1);

namespace App\Modules\Promotion\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Promotion\Models\ComboItem;
use App\Modules\Promotion\Models\Promotion;
use App\Modules\Promotion\Services\ComboRules;
use App\Modules\Promotion\Support\Decimal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * কম্বো ও বান্ডলের উপাদান বসানোর দরজা — *"কোন পণ্য, প্রতি সেটে অন্তত কতটা"*।
 *
 * ── ⚠️ কী ঘটছিল ────────────────────────────────────────────────────
 * ⓘ [[ComboRules]] ছিল, [[BillPromotionEngine]] উপাদান পড়ত — কিন্তু কোনো
 * পর্দা বা রুট উপাদান লিখতে দিত না। ⛔ অর্থাৎ কম্বোর নিয়ম কোডে ছিল,
 * অথচ মানুষের হাতে *"ক + খ"* লেখার জায়গাই ছিল না — "কাজটা আছে, জোড়াটা
 * নেই" আকারের ভুল।
 *
 * ── ⭐ কেন আলাদা কন্ট্রোলার, [[PromotionController]]-এ নয় ─────────────
 * ⓘ ঐ ফাইলে অন্য সেশনের কাজ চলছে, আর গাছটা সবার এক। ⚠️ তাই নতুন ফাইল;
 * নিয়মগুলো তবু এক জায়গায় — [[ComboRules]]। এই দরজা কেবল ইনপুট যাচাই
 * করে আর সেবাকে ডাকে; খসড়া-কি-না, কম্বো-কি-না, কোম্পানির-পণ্য-কি-না —
 * সব সেবা থামায়।
 *
 * ⛔ চাবি `promotion.update` — নিয়ম বসানো মানে অফার বদলানো, ধাপ আর
 * সুযোগ-সারির মতোই।
 */
final class PromotionComboController extends Controller implements HasMiddleware
{
    public function __construct(private readonly ComboRules $rules) {}

    public static function middleware(): array
    {
        return [new Middleware('can:promotion.update')];
    }

    public function store(Request $request, Promotion $promotion): RedirectResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'min:1'],

            /*
             * ⛔ শূন্য বা ঋণাত্মক নয়, আর বৈজ্ঞানিক রূপও নয়।
             *
             * ⓘ `Decimal::RULE` — `1e5` bcmath-এ ৫০০ দিত ([[Decimal]])। ⚠️ `gt:0`
             * এখানে প্রথম দেয়াল; সেবা তবু নিজে আবার থামায়, কারণ অন্য কোনো
             * পথ (আমদানি, API) একদিন সরাসরি সেবাকে ডাকবে।
             */
            'min_qty' => ['required', 'numeric', Decimal::RULE, 'gt:0'],
        ]);

        $this->rules->addItem($promotion, (int) $data['product_id'], (string) $data['min_qty']);

        return back()->with('saved', __('promotion::combo.item_added'));
    }

    /**
     * ⓘ `{item}` রুট-বাঁধনে কোম্পানির ছাঁকনি বসে — অন্য কোম্পানির উপাদান ৪০৪।
     * ⛔ একই কোম্পানির **অন্য অফারের** উপাদান [[ComboRules::removeItem()]] ৪০৪ দেয়।
     */
    public function destroy(Promotion $promotion, ComboItem $item): RedirectResponse
    {
        $this->rules->removeItem($promotion, $item);

        return back()->with('saved', __('promotion::combo.item_removed'));
    }
}
