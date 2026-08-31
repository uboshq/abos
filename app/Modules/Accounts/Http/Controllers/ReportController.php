<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Http\Controllers;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Modules\Accounts\Models\Account;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * আটটা রিপোর্ট, একটা স্ক্রিন।
 *
 * প্রতিটা রিপোর্টের নিজের কন্ট্রোলার ও ভিউ লিখলে আটবার একই কাজ হত —
 * ফিল্টার পড়া, পাতা ভাগ, যোগফল, ছাপা, রপ্তানি — আর নবমটা যোগ করার
 * সময় কেউ একটা ধাপ ভুলে যেত। Report engine ঠিক এই পুনরাবৃত্তিটার
 * জন্যই আছে (সেকশন ২.২): রিপোর্ট শুধু বলে সে কী চায়, বাকিটা engine-এর।
 *
 * ফলে নতুন রিপোর্ট যোগ করতে এই ফাইলটা ছুঁতে হয় না — শুধু CoreReports-এ
 * একটা সংজ্ঞা আর module.php-তে একটা মেনু সারি।
 */
class ReportController extends Controller implements HasMiddleware
{
    /**
     * URL-বান্ধব নাম থেকে রিপোর্টের কী।
     *
     * /accounts/reports/day-book — ঠিকানায় engine-এর ভেতরের কী
     * (accounts.day_book) না দেখানোই ভালো: ওটা বদলালে বুকমার্ক ভাঙত।
     *
     * @var array<string, string>
     */
    private const SLUGS = [
        'day-book' => 'accounts.day_book',

        /*
         * কোন কেন্দ্রে কত — রুট ধরে খরচ ও আয়।
         *
         * সারিটা এখানেই বসানো হলো, মেনুতে যোগ করার একই মুহূর্তে। উপরে
         * ঠিক এই ভুলটার ইতিহাস লেখা আছে, আর গতকাল সরবরাহকারীর দুইটা
         * রিপোর্টেও একই জিনিস ঘটেছিল।
         */
        'by-cost-centre' => 'accounts.by_cost_centre',
        'cash-book' => 'accounts.cash_book',
        'bank-book' => 'accounts.bank_book',

        /*
         * আদায়ের তালিকা — "আজ কত টাকা ঢুকল, কোন কাগজে"।
         *
         * ── কেন এটা এখানে ছিল না ────────────────────────────────────
         * রিপোর্টটা লেখা হয়েছিল, ইঞ্জিনে নিবন্ধিতও হয়েছিল
         * (`CoreReports::inflow()`), মেনুতে সারিও বসেছিল — কেবল
         * ঠিকানা থেকে ওই কী-তে পৌঁছানোর এই সেতুটা কেউ বসায়নি। ফলে
         * সাইডবারে লিংক দেখা যেত, ক্লিক করলে ৪০৪।
         *
         * `ModuleMenuTest` ধরেনি কারণ সে দেখে রুটের **নাম** আছে কি না
         * (`accounts.report.show` ছিলই), প্যারামিটারটা কাজ করে কি না
         * তা নয়। পাহারাটা এখন সেটাও দেখে।
         *
         * ধরা পড়েছে HP-র পরীক্ষকের ২২টা মেনু ধরে ধরে খোলায়, ১৪ আগস্ট।
         */
        'inflow' => 'accounts.inflow',
        'ledger' => 'accounts.ledger',
        'trial-balance' => 'accounts.trial_balance',
        'profit-loss' => 'accounts.profit_loss',
        'balance-sheet' => 'accounts.balance_sheet',
        'cash-flow' => 'accounts.cash_flow',
    ];

    /** যেগুলোতে চূড়ান্ত হিসাবের অনুমতি লাগে। */
    private const FINAL_ACCOUNTS = ['profit-loss', 'balance-sheet', 'cash-flow'];

    public function __construct(
        private readonly ReportEngine $reports,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware('can:accounts.report')];
    }

    public function show(Request $request, string $slug): View
    {
        abort_unless(isset(self::SLUGS[$slug]), 404);

        /*
         * লাভ-লোকসান, ব্যালেন্স শিট ও ক্যাশ ফ্লো আলাদা অনুমতি চায়।
         *
         * ইচ্ছাকৃত: হিসাবরক্ষককে ডে বুক ও লেজার দেখতে হয় রোজ, কিন্তু
         * প্রতিষ্ঠানের মুনাফা কত সেটা সবার জানার কথা নয়।
         */
        if (in_array($slug, self::FINAL_ACCOUNTS, true)) {
            abort_unless($request->user()->can('accounts.report.final'), 403);
        }

        $key = self::SLUGS[$slug];
        $definition = $this->reports->get($key);

        $result = $this->reports->run(
            $key,
            $request->only(['from', 'to', 'branch_id', 'account_id', 'top', 'compare']),
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
            'accounts' => $definition->hasFilter('account')
                ? Account::query()->postable()->active()->orderBy('code')->get()
                : collect(),

            /*
             * পক্ষের ধরনের ছাঁকনি — এই পর্দায় সবসময় খালি, আর সেটাই ঠিক।
             *
             * ── কেন খালি ────────────────────────────────────────────
             * ভিউটা ছয়টা মডিউল শেয়ার করে, আর ছাঁকনিটা সত্যিই লাগে
             * Customer ও Supplier-এর রিপোর্টে। এখানকার আটটা রিপোর্টের
             * একটাও `party_type` ছাঁকনি ঘোষণা করে না (CoreReports),
             * তাই তালিকাটা কোনোদিনই আঁকা হয় না।
             *
             * ── কেন এখানে PartyType ডাকা যায় না ─────────────────────
             * ওটা MasterData-র মডেল, আর MasterData নিজেই accounts-এর
             * উপর দাঁড়ায়। এখানে `depends_on`-এ master_data লিখলে
             * accounts → master_data → accounts চক্র হত, আর
             * ModuleRegistry::sortByDependency() বুট-টাইমেই ব্যতিক্রম
             * ছুঁড়ত — অর্থাৎ পুরো অ্যাপ দাঁড়াত না।
             *
             * যে মডিউলের রিপোর্টে ছাঁকনিটা আছে সে নিজেই তালিকাটা দেয়
             * (CustomerReportController, SupplierReportController), আর
             * তারা দুইজনেই master_data-কে নির্ভরতা বলে ঘোষণা করে।
             * এখানে কোনোদিন ছাঁকনিটা লাগলে ওটাও ঐভাবেই আসবে —
             * ধার করা import দিয়ে নয়। পাহারা: [[BoundariesTest]]।
             */
            'partyTypes' => collect(),
        ]);
    }
}
