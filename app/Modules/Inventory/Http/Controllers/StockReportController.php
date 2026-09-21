<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Modules\MasterData\Models\PartyType;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * মজুদের তিনটা রিপোর্ট, হিসাবের পর্দার ভিউ দিয়েই।
 *
 * ভিউটা ReportDefinition ছাড়া আর কিছু জানে না, তাই নতুন ভিউ লেখার মানে
 * হত একই টেবিল তৃতীয়বার লেখা (সেকশন ১৯.৮)।
 */
class StockReportController extends Controller implements HasMiddleware
{
    /**
     * @var array<string, string>
     */
    private const SLUGS = [
        'stock-ledger' => 'inventory.stock_ledger',
        'stock-summary' => 'inventory.stock_summary',
        'hold' => 'inventory.hold',

        /*
         * ⛔ `expiring` এই তালিকায় ছিল না — ১৮ সেপ্টেম্বর ২০২৬তে
         * ধরা পড়ল।
         *
         * ⓘ [[StockReports::expiring()]] লেখা হয়েছিল, ইঞ্জিনে নিবন্ধিতও
         * হত, আর মেনুতে সারিটাও ছিল। ⛔ কেবল এই একটা সারি
         * না থাকায় সারিতায় চাপলে **৪০৪** আসত।
         *
         * ⚠️ ঠিক সেই চেনা ধরন: তিনটা অংশই ছিল, জোড়াটা ছিল না,
         * আর কিছুই ভাঙেনি — কারণ কেউ ব্যাচের সুইচ চালু করে সারিটায়
         * চাপেনি।
         */
        'expiring' => 'inventory.expiring',

        /* ⭐ ব্যাচভিত্তিক মজুদ — ১৮ সেপ্টেম্বর ২০২৬, মালিকের
           *"স্টক আলাদা ম্যানেজ"* নির্দেশে। ⓘ হাইফেন, আন্ডারস্কোর
           নয় — এই তালিকার বাকি সব ঠিকানাও তাই। */
        'stock-by-batch' => 'inventory.stock_by_batch',

        /* ⭐ মজুদ মূল্যসহ — ২১ সেপ্টেম্বর ২০২৬, মালিকের নির্দেশে।
           ⓘ হাইফেন, আন্ডারস্কোর নয় — এই তালিকার বাকি সব ঠিকানাও তাই। */
        'stock-value' => 'inventory.stock_value',
        'stock-by-warehouse' => 'inventory.stock_by_warehouse',
        'adjustments' => 'inventory.adjustments',

        /* ⓘ খাদ্য-খরচ এখানে ছিল — ১৫ সেপ্টেম্বর ২০২৬-এ রেস্তোরাঁয় গেছে
           ([[App\Modules\Restaurant\Http\Controllers\RestaurantReportController]]),
           মালিকের দাগানো অনুযায়ী। প্রশ্নটা রান্না করা খাবারের, মজুদের নয়। */
    ];

    public function __construct(
        private readonly ReportEngine $reports,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware('can:inventory.report')];
    }

    public function show(Request $request, string $slug): View
    {
        abort_unless(isset(self::SLUGS[$slug]), 404);

        $key = self::SLUGS[$slug];
        $definition = $this->reports->get($key);

        $result = $this->reports->run(
            $key,
            /*
             * ⭐ ঘরগুলো ঘোষণা থেকেই — ২১ সেপ্টেম্বর ২০২৬।
             *
             * ⛔ আগে এখানে একটা হাতে লেখা তালিকা ছিল, আর আটটা রিপোর্ট
             * কন্ট্রোলারে আটটা তালিকা এক ছিল না। ⚠️ ছয়টা `party_type_id`
             * পাঠাত না, অথচ রিপোর্টগুলো ছাঁকনিটা ঘোষণা করত আর পর্দায় ঘরটা
             * আঁকা হত — ব্যবহারকারী বেছে দিতেন আর কিছুই বদলাত না।
             *
             * ⓘ যে ঘোষণা থেকে ঘরটা আঁকা হয়, এখন সেখান থেকেই পড়া হয়।
             */
            $request->only($definition->requestKeys()),
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
             * পক্ষের ধরনের ছাঁকনি — কেবল যে রিপোর্ট চেয়েছে তার জন্য।
             *
             * ঘোষণা না করলে তালিকাটা খালি যায়, আর পর্দা ঘরটাই আঁকে না।
             * সব রিপোর্টে জোর করে বসালে মজুদের রিপোর্টেও "পক্ষের ধরন"
             * ড্রপডাউন বসত, যেখানে প্রশ্নটার কোনো মানে নেই।
             */
            'partyTypes' => $definition->hasFilter('party_type')
                ? PartyType::query()->active()->orderBy('code')->get()
                : collect(),
        ]);
    }
}
