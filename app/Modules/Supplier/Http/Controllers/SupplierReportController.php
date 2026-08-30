<?php

declare(strict_types=1);

namespace App\Modules\Supplier\Http\Controllers;

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
 * সরবরাহকারীর দুইটা রিপোর্ট, হিসাবের পর্দার ভিউ দিয়েই।
 *
 * accounts::report.show ভিউটা ReportDefinition ছাড়া আর কিছু জানে না —
 * কলাম, ফিল্টার, যোগফল, ছাপা, রপ্তানি সবই সংজ্ঞা থেকে আসে। তাই এখানে
 * নতুন ভিউ লেখার মানে হত একই টেবিল দ্বিতীয়বার লেখা, আর দুইটার একটা
 * পরে ঠিক করতে ভুলে যাওয়া (সেকশন ১৯.৮)।
 */
class SupplierReportController extends Controller implements HasMiddleware
{
    /**
     * URL-বান্ধব নাম থেকে রিপোর্টের কী।
     *
     * /suppliers/reports/ageing — engine-এর ভেতরের কী (supplier.ageing)
     * ঠিকানায় না দেখানোই ভালো: ওটা বদলালে বুকমার্ক ভাঙত।
     *
     * @var array<string, string>
     */
    private const SLUGS = [
        'payable-list' => 'supplier.payable_list',
        'ageing' => 'supplier.ageing',
    ];

    public function __construct(
        private readonly ReportEngine $reports,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware('can:supplier.report')];
    }

    public function show(Request $request, string $slug): View
    {
        abort_unless(isset(self::SLUGS[$slug]), 404);

        $key = self::SLUGS[$slug];
        $definition = $this->reports->get($key);

        $result = $this->reports->run(
            $key,
            $request->only(['from', 'to', 'branch_id', 'party_type_id', 'top', 'compare']),
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
