<?php

declare(strict_types=1);

namespace App\Modules\Customer\Http\Controllers;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * গ্রাহকের দুইটা রিপোর্ট, হিসাবের পর্দার ভিউ দিয়েই।
 *
 * accounts::report.show ভিউটা ReportDefinition ছাড়া আর কিছু জানে না —
 * কলাম, ফিল্টার, যোগফল, ছাপা, রপ্তানি সবই সংজ্ঞা থেকে আসে। নতুন ভিউ
 * লেখার মানে হত একই টেবিল দ্বিতীয়বার লেখা (সেকশন ১৯.৮)।
 */
class CustomerReportController extends Controller implements HasMiddleware
{
    /**
     * URL-বান্ধব নাম থেকে রিপোর্টের কী।
     *
     * @var array<string, string>
     */
    private const SLUGS = [
        /*
         * আদায়ের তালিকা — কার কাছ থেকে কত এল।
         *
         * হিসাব মডিউলের `inflow`-এর মতোই এখানেও সেতুটা বাদ পড়েছিল:
         * রিপোর্ট লেখা, ইঞ্জিনে নিবন্ধিত (`customer.collection`), মেনুতে
         * সারি — অথচ ঠিকানা থেকে ওখানে পৌঁছানো যেত না, ৪০৪ আসত।
         *
         * দুইটাই ধরা পড়েছে একই দিনে: একটা HP-র পরীক্ষকের হাতে, আর
         * এটা ওই ভুল থেকে লেখা নতুন পরীক্ষায় (`ModuleMenuTest`), প্রথম
         * চালেই। একই ভুল দুই মডিউলে — তাই পাহারাটাই আসল সমাধান ছিল।
         */
        'collection' => 'customer.collection',

        'due-list' => 'customer.due_list',
        'ageing' => 'customer.ageing',
    ];

    public function __construct(
        private readonly ReportEngine $reports,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware('can:customer.report')];
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
        ]);
    }
}
