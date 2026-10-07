<?php

declare(strict_types=1);

namespace App\Modules\Promotion\Http\Controllers;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * অফারের রিপোর্ট — স্পেক §১৭, হিসাবের পর্দার ভিউ দিয়েই।
 *
 * ⓘ `accounts::report.show` কেবল [[ReportDefinition]] চেনে — ছাঁকনি, খোঁজা,
 * পাতা, যোগফল, রপ্তানি, ছাপা সব সংজ্ঞা থেকে। ⛔ নতুন ভিউ লিখলে একই টেবিল
 * দ্বিতীয়বার লেখা হত, আর দুইটার একটা পরে ঠিক করতে কেউ ভুলে যেত।
 *
 * ── ⚠️ অফার ও অবস্থার ছাঁকনি কেন খোঁজার ঘরে ─────────────────────────
 * ⓘ ইঞ্জিনের ছাঁকনির তালিকা বন্ধ (`ReportDefinition::ASKED_AS`), আর
 * `ADrawnFilterBoxThatChangesNothingTest` অচেনা ছাঁকনি ঘোষণা করতে দেয় না।
 * ⭐ তাই অফারের কোড, নাম আর অবস্থার নাম লেখার কলাম — খোঁজার ঘর ওগুলোতেই
 * খোঁজে, পুরো ফলের উপর, আর যোগফলও খোঁজা ফলের। ⓘ পাতার উপরের নোটটা
 * সেটাই বলে, যাতে কেউ একটা অনুপস্থিত ড্রপডাউন খুঁজে না বেড়ান।
 */
class PromotionReportController extends Controller implements HasMiddleware
{
    /**
     * ঠিকানার নাম থেকে ইঞ্জিনের চাবি।
     *
     * ⚠️ `ALinkThatLooksAliveAndIsNotTest` আর `EveryReportScreenOpensInEveryModuleTest`
     * দুইজনেই এই ধ্রুবকটা reflection দিয়ে পড়ে — নাম বদলাবেন না।
     *
     * @var array<string, string>
     */
    private const SLUGS = [
        'register' => 'promotion.register',
        'active' => 'promotion.active',
        'expired' => 'promotion.expired',
        'utilization' => 'promotion.utilization',
        'by-customer' => 'promotion.by_customer',
        'by-product' => 'promotion.by_product',
        'discounts' => 'promotion.discounts',
        'gifts' => 'promotion.gifts',
        'gift-stock' => 'promotion.gift_stock',
        'budgets' => 'promotion.budgets',
        'overrides' => 'promotion.overrides',
        'reversals' => 'promotion.reversals',
        'cancelled-offers' => 'promotion.cancelled_offers',
    ];

    /**
     * যে রিপোর্টের সারি একটা গণনা, কাগজ নয় — পাঠককে বলে দেওয়া হয়।
     *
     * ⓘ মানুষ নামটায় ক্লিক করবেনই; কিছু না হলে ভাববেন পাতাটা ভাঙা।
     *
     * @var list<string>
     */
    private const COUNTS_ONLY = ['utilization', 'by-customer', 'by-product', 'gift-stock'];

    public function __construct(
        private readonly ReportEngine $reports,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware('can:promotion.report')];
    }

    public function show(Request $request, string $slug): View
    {
        abort_unless(isset(self::SLUGS[$slug]), 404);

        $key = self::SLUGS[$slug];
        $definition = $this->reports->get($key);

        return view('accounts::report.show', [
            'menu' => $this->menu->forUser($request->user()),
            'slug' => $slug,
            'report' => $definition,
            'result' => $this->reports->run(
                $key,
                /* ⭐ ঘরগুলো ঘোষণা থেকেই — হাতে লেখা তালিকা নয় */
                $request->only($definition->requestKeys()),
                page: max(1, (int) $request->query('page', 1)),
                // ⭐ "সব শাখা"-তে শাখা ধরে ভাগ + সর্বমোট — ভাগ হবে কি না ইঞ্জিন ঠিক করে ([[ReportEngine::branchPlan()]])
                byBranch: true,
            ),

            /*
             * ⚠️ অফারের কোনো রিপোর্টে শাখা, খাত বা পক্ষের ধরন নেই —
             * `promotion_applications`-এ `branch_id` কলামই নেই। ⓘ খালি
             * তালিকা মানে ঘরটা আঁকাই হয় না; ⛔ একটা অকেজো ড্রপডাউন বসানো
             * পর্দার মিথ্যা কথা হত।
             */
            'branches' => collect(),
            'accounts' => collect(),
            'partyTypes' => collect(),

            'notice' => $this->noticeFor($slug),
        ]);
    }

    /** ⓘ পাতার উপরের এক লাইন — কীভাবে ছাঁকবেন, আর কোনটা ক্লিক হয় না। */
    private function noticeFor(string $slug): string
    {
        $lines = [__('promotion::report.notice_search')];

        if (in_array($slug, self::COUNTS_ONLY, true)) {
            $lines[] = __('promotion::report.notice_counts_only');
        }

        if ($slug === 'active') {
            $lines[] = __('promotion::report.notice_active_today');
        }

        if ($slug === 'budgets') {
            $lines[] = __('promotion::report.notice_budget_units');
        }

        if ($slug === 'cancelled-offers') {
            $lines[] = __('promotion::report.notice_cancelled_when');
        }

        return implode(' ', $lines);
    }
}
