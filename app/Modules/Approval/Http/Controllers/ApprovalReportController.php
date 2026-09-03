<?php

declare(strict_types=1);

namespace App\Modules\Approval\Http\Controllers;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * অনুমোদনের চারটা রিপোর্ট — হিসাবের পর্দার ভিউ দিয়েই।
 *
 * `accounts::report.show` ভিউটা `ReportDefinition` ছাড়া আর কিছু জানে না —
 * কলাম, ফিল্টার, যোগফল, ছাপা, রপ্তানি সবই সংজ্ঞা থেকে আসে। নতুন ভিউ
 * লেখার মানে হত একই টেবিল দ্বিতীয়বার লেখা, আর দুইটার একটা পরে ঠিক করতে
 * ভুলে যাওয়া (সেকশন ১৯.৮)।
 */
class ApprovalReportController extends Controller implements HasMiddleware
{
    /**
     * URL-বান্ধব নাম থেকে রিপোর্টের কী।
     *
     * /approvals/reports/pending — engine-এর ভেতরের কী (approval.pending)
     * ঠিকানায় না দেখানোই ভালো: ওটা বদলালে বুকমার্ক ভাঙত।
     *
     * ⚠️ এই তালিকাটা `ALinkThatLooksAliveAndIsNotTest` reflection দিয়ে
     * পড়ে — অর্থাৎ মেনুতে বা কোথাও ভুল slug লিখলে টেস্ট লাল হবে, ৪০৪
     * পর্যন্ত যেতে হবে না।
     *
     * @var array<string, string>
     */
    private const SLUGS = [
        'pending' => 'approval.pending',
        'approved' => 'approval.approved',
        'rejected' => 'approval.rejected',
        'by-user' => 'approval.by_user',
    ];

    /**
     * যে রিপোর্টের সারি ক্লিকযোগ্য নয়, আর পাঠককে সেটা বলে দেওয়া হয়।
     *
     * ── কেন একটা লাইন লেখার দরকার পড়ল ───────────────────────────────
     * "রহিম ১২টা অনুমোদন" দেখে মানুষ নামটায় ক্লিক করবেনই। কিছু না হলে
     * তাঁরা ভাববেন পাতাটা ভাঙা — আর ভাঙা মনে হওয়াটা ভাঙার প্রায় সমান
     * খরচের। সারিটা একটা কাগজ নয়, একটা গণনা; তাই সীমাটা **লুকানো হয় না,
     * লেখা হয়**, আর সাথে যেখানে গেলে কাগজগুলো আছে সেই পথটাও।
     *
     * @var array<string, string>
     */
    private const NOT_CLICKABLE = [
        'by-user' => 'approval::message.report_counts_only',
    ];

    public function __construct(
        private readonly ReportEngine $reports,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware('can:approval.report')];
    }

    public function show(Request $request, string $slug): View
    {
        abort_unless(isset(self::SLUGS[$slug]), 404);

        $key = self::SLUGS[$slug];

        return view('accounts::report.show', [
            'menu' => $this->menu->forUser($request->user()),
            'slug' => $slug,
            'report' => $this->reports->get($key),
            'result' => $this->reports->run(
                $key,
                $request->only(['from', 'to', 'top', 'compare']),
                page: max(1, (int) $request->query('page', 1)),
            ),

            /*
             * ⚠️ ঘরগুলো ঘোষণা না করলে ভিউ ওগুলো আঁকেই না।
             *
             * অনুমোদনের কোনো রিপোর্টে শাখার ছাঁকনি নেই — `approvals`
             * টেবিলে `branch_id` কলামই নেই। খালি তালিকা পাঠানো মানে
             * ঘরটা না দেখানো, আর সেটাই সৎ: একটা শাখা-ড্রপডাউন বসিয়ে
             * সেটা কিছু না করাটা পর্দার মিথ্যা কথা হত।
             */
            'branches' => collect(),
            'accounts' => collect(),
            'partyTypes' => collect(),

            'notice' => isset(self::NOT_CLICKABLE[$slug])
                ? __(self::NOT_CLICKABLE[$slug])
                : null,
        ]);
    }
}
