<?php

declare(strict_types=1);

namespace App\Modules\Restaurant\Http\Controllers;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * রেস্তোরাঁর রিপোর্টের দরজা — আজ একটাই স্লাগ।
 *
 * ── কেন প্রতিটা মডিউলের নিজের দরজা ─────────────────────────────────
 * ⓘ ঠিকানাটা `/{module}/reports/{slug}` — মজুদ, বিক্রয় ও হিসাব
 * প্রত্যেকের নিজেরটা আছে। একটাই সাধারণ দরজা বানানো যেত, কিন্তু তখন
 * অনুমতিটা সব রিপোর্টে এক হত, আর রেস্তোরাঁর রিপোর্ট দেখতে মজুদের
 * চাবি লাগত কি না সেটা আর আলাদা করে বলা যেত না।
 *
 * ── ⭐ চাবিটা `restaurant.report`, মজুদেরটা নয় ─────────────────────
 * পর্দাটা মজুদ থেকে এসেছে, কিন্তু চাবিটা সাথে আনা হয়নি — রেস্তোরাঁ
 * নিজেরটা ঘোষণা করে ([[module.php]]-র `permissions`)।
 *
 * ⚠️ চাবি বদলানোর একটাই বিপদ: যাঁরা আজ পর্দাটা দেখেন তাঁরা কাল হারান।
 * ⓘ সেটা এখানে ঠেকানো হয়েছে `role_templates`-এ `Manager` →
 * `restaurant.report` দিয়ে, কারণ মজুদের `Manager` টেমপ্লেটে
 * `inventory.report` আছে। অর্থাৎ যিনি কাল দেখতেন, আজও দেখবেন।
 */
class RestaurantReportController extends Controller implements HasMiddleware
{
    /**
     * ⓘ স্লাগ থেকে রিপোর্টের চাবি — সাদা তালিকা, খোলা ম্যাপিং নয়।
     *
     * ⛔ `$slug` সরাসরি ইঞ্জিনে পাঠালে যেকোনো মডিউলের যেকোনো রিপোর্ট
     * এই ঠিকানা দিয়ে খোলা যেত, আর অনুমতির ছাঁকনিটা অর্থহীন হত।
     *
     * @var array<string, string>
     */
    private const SLUGS = [
        'food-cost' => 'restaurant.food_cost',
    ];

    public function __construct(
        private readonly ReportEngine $reports,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware('can:restaurant.report')];
    }

    public function show(Request $request, string $slug): View
    {
        abort_unless(isset(self::SLUGS[$slug]), 404);

        $key = self::SLUGS[$slug];
        $definition = $this->reports->get($key);

        $result = $this->reports->run(
            $key,
            $request->only(['from', 'to', 'branch_id', 'top', 'compare']),
            page: max(1, (int) $request->query('page', 1)),
        );

        return view('accounts::report.show', [
            'menu' => $this->menu->forUser($request->user()),
            'slug' => $slug,
            'report' => $definition,
            'result' => $result,
            'branches' => $definition->hasFilter('branch')
                ? Branch::query()->active()->orderBy('name_en')->get()
                : collect(),
            'accounts' => collect(),

            /*
             * ⓘ পক্ষের ধরনের ছাঁকনি এই রিপোর্টে নেই, তাই খালি — কিন্তু
             * ঘরটা পাঠাতেই হয়, কারণ ভিউটা সব মডিউলের জন্য একটাই।
             */
            'partyTypes' => collect(),
        ]);
    }
}
