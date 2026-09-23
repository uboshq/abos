<?php

declare(strict_types=1);

namespace App\Modules\SystemAdmin\Http\Controllers;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * নোটিশের রিপোর্ট — রেজিস্টার আর সইয়ের হিসাব।
 *
 * ── ⓘ কেন ঠিকানায় ইঞ্জিনের কী নয় ────────────────────────────────────
 * URL-এ `notice-register` বসে, ভিতরের কী `system_admin.notice_register`
 * নয়। ⚠️ কী বদলালে বুকমার্ক ভাঙত, আর ভিতরের নাম বাইরে দেখানোর কোনো
 * কারণ নেই।
 *
 * ⓘ তালিকাটা [[ALinkThatLooksAliveAndIsNotTest]] reflection দিয়ে পড়ে —
 * মেনুতে ভুল slug লিখলে পরীক্ষা লাল হয়, ৪০৪ পর্যন্ত যেতে হয় না।
 */
final class NoticeReportController extends Controller implements HasMiddleware
{
    /** @var array<string, string> */
    private const SLUGS = [
        'notice-register' => 'system_admin.notice_register',
        'notice-signatures' => 'system_admin.notice_signatures',
    ];

    public function __construct(
        private readonly ReportEngine $reports,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware('can:system_admin.notice.analytics')];
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
                $request->only($definition->requestKeys()),
                page: max(1, (int) $request->query('page', 1)),
            ),

            /*
             * ⚠️ ঘরগুলো ঘোষণা না করলে ভিউ ওগুলো আঁকেই না।
             *
             * ⓘ নোটিশের রিপোর্টে শাখার ছাঁকনি নেই, আর কারণটা
             * [[NoticeReports]]-এ লেখা: কে কোন শাখায় পড়েছেন সেটা
             * `notice_reads`-এ নেই। ⛔ একটা শাখা-ড্রপডাউন বসিয়ে সেটা
             * কিছু না করাটা পর্দার মিথ্যা কথা হত।
             */
            'branches' => collect(),
            'accounts' => collect(),
            'partyTypes' => collect(),
            'notice' => null,
        ]);
    }
}
