<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Dashboard;

use App\Core\Contracts\ProvidesDashboard;
use App\Core\Dashboard\HomePeriod;
use App\Core\Engines\Dashboard\Breakdown;
use App\Core\Engines\Dashboard\DashboardDefinition;
use App\Core\Engines\Dashboard\DateRange;
use App\Core\Engines\Dashboard\Listing;
use App\Core\Engines\Dashboard\Stat;
use App\Core\Engines\Dashboard\Tile;
use App\Core\Dashboard\Viz\Card;
use App\Core\Dashboard\Viz\Svg;
use App\Core\Engines\Drill\DrillResolver;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Core\Support\ViewedBranch;
use App\Modules\Accounts\Models\MoneyTransfer;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\AccountsFacts;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherParties;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;

/**
 * হিসাব মডিউলের ড্যাশবোর্ড — ইঞ্জিনের ছকে।
 *
 * ── কেন পুরনো পর্দাটা নতুন করে লেখা হয়নি ─────────────────────────────
 * হিসাবের নিজস্ব একটা ড্যাশবোর্ড আগে থেকেই ছিল, আর তার সংখ্যাগুলো
 * ভালো ছিল — হাতে নগদ, ব্যাংক, প্রাপ্য, প্রদেয়, মাসের আয়-ব্যয়। ওগুলো
 * এখানে নতুন করে গুনলে **প্রতিটার দ্বিতীয় সংজ্ঞা** তৈরি হত, আর একদিন
 * দুই পর্দা দুই উত্তর দিত।
 *
 * তাই হিসাবটা কন্ট্রোলার থেকে [[AccountsFacts]]-এ সরানো হয়েছে,
 * **একটা লাইনও না বদলে**, আর দুই পর্দাই এখন সেখান থেকে নেয়।
 *
 * ── কেন এই মডিউলের ড্যাশবোর্ডে "খাতা মেলে কি না" নেই ─────────────────
 * ওটা একটা ভারী যাচাই (`abos:books-check` পুরো খতিয়ান হাঁটে)। প্রতিটা
 * পাতা খোলায় সেটা চালালে পর্দাটা ধীর হত, আর মানুষ ড্যাশবোর্ড খোলাই
 * বন্ধ করতেন। যাচাইটা রোজ ও প্রতি ডিপ্লয়ে চলে; এখানে তার নিজের
 * পাতার লিংক আছে।
 */
final class AccountsDashboard implements ProvidesDashboard
{
    public static function dashboard(): DashboardDefinition
    {
        $facts = app(AccountsFacts::class);

        /*
         * আজকের তিনটা সংখ্যা একবারেই — [[AccountsFacts::today()]] একটাই
         * কোয়েরিতে তিনটাই দেয়।
         *
         * ⚠️ তিনবার তিনটা মেথড ডাকলে তিনটা কোয়েরি হত, আর ড্যাশবোর্ডে
         * টালি দশটা। **একটাও ধীর কোয়েরি ছাড়াই পাতাটা ধীর হয়** — ঠিক
         * এভাবেই।
         */
        $today = $facts->today();

        // ⓘ মাসের আয়-ব্যয় একবারই পড়া — "এই মাস এ পর্যন্ত" ভাগ আর নিট লাভ একই দুই সংখ্যা (৫ অক্টোবর ২০২৬)
        $income = $facts->incomeThisMonth();
        $expense = $facts->expenseThisMonth();

        /*
         * সবচেয়ে বেশি বকেয়া যে দশজনের — নাম সহ।
         *
         * ⚠️ খতিয়ান নাম রাখে না, কেবল `party_type` ও `party_id`। তাই
         * নামগুলো **একবারেই** আনা হয়, প্রতি সারিতে নয় — দশ সারিতে দশটা
         * কোয়েরি হলে ঠিক সেই ধীরতা জন্মাত যেটা এড়ানোর জন্য
         * [[AccountsFacts::today()]] একটাই কোয়েরিতে তিনটা সংখ্যা দেয়।
         */
        /*
         * পক্ষের নাম আসে [[DrillResolver]] দিয়ে — **মডিউলের ক্লাস
         * import না করে**।
         *
         * ── কেন এটা জরুরি ───────────────────────────────────────────
         * ⚠️ Accounts কারও উপর নির্ভর করে না — `module.php`-এ
         * `depends_on` ফাঁকা, আর **সবাই যার উপর দাঁড়ায় সে কারও উপর
         * দাঁড়ালে সীমানাটাই থাকে না**। গ্রাহক ও সরবরাহকারী দুইটাই
         * Accounts-এর উপর দাঁড়ায়, তাই উল্টো নির্ভরতা **চক্র** তৈরি করত।
         *
         * ⛔ প্রথমে এখানে `Customer::query()` ও `Supplier::query()` লেখা
         * ছিল, আর [[BoundariesTest]] সেটা ধরেছে — ঠিকই ধরেছে।
         *
         * ── কেন নাম বাদ দিয়ে দেওয়া হলো না ───────────────────────────
         * [[CoreReports]]-এ লেখা আছে *"পক্ষের নাম এখানে নেই,
         * ইচ্ছাকৃতভাবে"* — কারণ তখন টেবিলে জোড় লাগাতে হত। ⭐ কিন্তু
         * `DrillResolver` সেই সমস্যাটার উত্তর: সে মডিউলের **ঘোষণা** পড়ে
         * ক্লাসটা বের করে, তাই Accounts কোনো টেবিল বা ক্লাসের নাম জানে
         * না — কেবল `'customer'` শব্দটা, যেটা খতিয়ানের নিজের ঘরেই আছে।
         *
         * ⓘ `whereIn` — প্রতি সারিতে `resolve()` ডাকলে দশ সারিতে দশটা
         * কোয়েরি হত, আর ড্যাশবোর্ডে তালিকা দুইটা।
         */
        $nameFor = function (string $partyType, array $rows) {
            $ids = array_column($rows, 'party_id');

            if ($ids === []) {
                return collect();
            }

            $model = app(DrillResolver::class)->map()[$partyType] ?? null;

            if ($model === null) {
                return collect();
            }

            // ⭐ গ্রাহকের পয়েন্ট আর শাখা — মালিক, ১৯ অক্টোবর ২০২৬ (স্থায়ী নিয়ম); দশ সারির এক প্রশ্নে ([[Customer::nameWithPoint()]])
            $places = method_exists($model, 'nameWithPoint') ? ['location.parent', 'branch'] : [];

            return $model::query()->whereIn('id', $ids)->with($places)->get()->keyBy('id');
        };

        $topDue = $facts->topDue('customer', StandardChart::RECEIVABLE);
        $names = $nameFor('customer', $topDue);

        $dueRows = collect($topDue)
            ->map(fn (array $row) => (object) [
                'id' => $row['party_id'],
                'name' => $names[$row['party_id']]?->nameWithPoint() ?? '—',
                'amount' => $row['amount'],
            ])
            ->values();

        /*
         * উল্টো দিকটাও — আমরা কাকে কত দিতে হবে।
         *
         * ⓘ মালিকের স্পেকে জোড়াটা একসাথে চাওয়া (*Top 10 Due
         * Customers/Suppliers*)। গ্রাহকেরটা রোজকার প্রশ্ন, কিন্তু
         * **মাসের শেষে প্রশ্নটা উল্টে যায়**: কার পাওনা মেটাতে হবে।
         */
        $topOwed = $facts->topDue('supplier', StandardChart::PAYABLE);
        $supplierNames = $nameFor('supplier', $topOwed);

        $owedRows = collect($topOwed)
            ->map(fn (array $row) => (object) [
                'id' => $row['party_id'],
                'name' => $supplierNames[$row['party_id']]?->drillLabel() ?? '—',
                'amount' => $row['amount'],
            ])
            ->values();

        /*
         * ⭐ খসড়ার নম্বরের পাশে পক্ষ — "নাম · পয়েন্ট" (মালিক, ১৯ অক্টোবর ২০২৬); আটটা সারির নাম একবারে ([[VoucherParties]])।
         * ⓘ ফোনের ড্যাশবোর্ড একই `render` পড়ে লেখা বানায় ([[DashboardApiController::text()]]), তাই সেখানেও "নম্বর · নাম · পয়েন্ট"।
         */
        $drafts = Voucher::query()->draft()->latest('id')->limit(8)->get();
        $draftParties = app(VoucherParties::class)->of($drafts);

        return new DashboardDefinition(
            title: __('accounts::dashboard.title'),
            subtitle: __('accounts::dashboard.subtitle'),

            tiles: [
                /*
                 * ⚠️ "নতুন ভাউচার" বলে কোনো একটা ঠিকানা নেই — রুটটা
                 * `{type}` চায় (আদায়/পরিশোধ/খরচ/জাবেদা/কন্ট্রা), আর
                 * টাইপ ছাড়া ডাকলে পুরো পাতাটা ৫০০ হয়ে যায়। এটা ঠিক
                 * এভাবেই একবার ধরা পড়েছে (২ সেপ্টেম্বর ২০২৬), তাই
                 * টাইলটা সবচেয়ে বেশি ব্যবহৃত ধরনটাকেই ধরে: **আদায়**।
                 * বাকি ধরনগুলো ভাউচারের নিজের মেনু থেকে।
                 */
                new Tile(label: __('accounts::menu.receipt'),
                    href: route('accounts.voucher.create', ['type' => Voucher::RECEIPT]),
                    permission: 'accounts.voucher.create', icon: 'receipt'),
                new Tile(label: __('accounts::menu.chart_of_accounts'), href: route('accounts.coa.index'),
                    permission: 'accounts.view', icon: 'book'),
                new Tile(label: __('accounts::menu.cash_tills'), href: route('accounts.till.index'),
                    permission: 'accounts.till.view', icon: 'cash'),
                new Tile(label: __('accounts::menu.books_check'), href: route('accounts.integrity'),
                    permission: 'accounts.view', icon: 'check-circle'),
            ],

            stats: [
                new Stat(
                    label: __('accounts::dashboard.cash_in_hand'),
                    value: Money::format($facts->cashInHand()),
                    hint: __('accounts::dashboard.cash_in_hand_hint'),
                    href: route('accounts.till.index'),
                    tone: Stat::GOOD,
                ),

                new Stat(
                    label: __('accounts::dashboard.bank_balance'),
                    value: Money::format($facts->bankBalance()),
                    hint: __('accounts::dashboard.bank_balance_hint'),
                    href: route('accounts.coa.index'),
                    // ⛔ ব্যাংকের জের — চূড়ান্ত রিপোর্টের চাবি (অডিট ম১৩/ম১৮, ১০ অক্টোবর ২০২৬)
                    permission: 'accounts.report.final',
                ),

                new Stat(
                    label: __('accounts::dashboard.receivable'),
                    value: Money::format($facts->receivable()),
                    hint: __('accounts::dashboard.receivable_hint'),
                    href: route('accounts.coa.index'),
                ),

                /*
                 * ⚠️ প্রদেয় বাড়া খারাপ খবর, তাই `BAD` — আর সেটাই তীরের
                 * রং ঠিক করে। দিক দেখে রং দিলে "প্রদেয় ▲২১%" সবুজ হত।
                 */
                new Stat(
                    label: __('accounts::dashboard.payable'),
                    value: Money::format($facts->payable()),
                    hint: __('accounts::dashboard.payable_hint'),
                    href: route('accounts.coa.index'),
                    tone: Stat::BAD,
                ),

                /*
                 * ── আজকের তিনটা — ৪ সেপ্টেম্বর ২০২৬ ─────────────────
                 *
                 * মালিকের Owner Dashboard-এর তালিকা থেকে। উপরের চারটা
                 * বলে **কত আছে**; এই তিনটা বলে **আজ কী ঘটল** — আর দিন
                 * শেষে দ্বিতীয় প্রশ্নটাই আগে করা হয়।
                 *
                 * ⚠️ প্রতিটার নিজের দরজা, আর সেটা মালিকের দাঁড়ানো নিয়ম:
                 * **প্রতিটা সংখ্যা তার কাগজে নিয়ে যায়**। "আজকের আদায়
                 * ১২,০০০" লিখে থেমে গেলে পরের প্রশ্নটার — *কোন কোন
                 * আদায়* — উত্তর দিতে আবার খুঁজতে হত।
                 */
                new Stat(
                    label: __('accounts::dashboard.today_collection'),
                    value: Money::format($today['collection']),
                    hint: __('accounts::dashboard.today_collection_hint'),
                    href: route('sales.collection.index'),
                    permission: 'sales.collection.view',
                    tone: Stat::GOOD,
                ),

                new Stat(
                    label: __('accounts::dashboard.today_payment'),
                    value: Money::format($today['payment']),
                    hint: __('accounts::dashboard.today_payment_hint'),
                    href: route('purchase.payment.index'),
                    permission: 'purchase.payment.view',
                ),

                /*
                 * খরচের দরজাটা আজকের তারিখ ধরেই খোলে।
                 *
                 * ⓘ ছাঁকনি ছাড়া পাঠালে খরচের পাতা **চলতি মাস** দেখাত,
                 * আর সংখ্যাটা মিলত না — ব্যবহারকারী ভাবতেন টালিটা ভুল।
                 */
                new Stat(
                    label: __('accounts::dashboard.today_expense'),
                    value: Money::format($today['expense']),
                    hint: __('accounts::dashboard.today_expense_hint'),
                    href: route('accounts.voucher.index', [
                        'type' => Voucher::EXPENSE,
                        'from' => now()->toDateString(),
                        'to' => now()->toDateString(),
                    ]),
                    permission: 'accounts.report',
                    tone: Stat::BAD,
                ),

                new Stat(
                    label: __('accounts::dashboard.outstanding_loan'),
                    value: Money::format($facts->outstandingLoan()),
                    hint: __('accounts::dashboard.outstanding_loan_hint'),
                    href: route('accounts.loan.index'),
                    permission: 'accounts.loan.view',
                    tone: Stat::BAD,
                ),

                /*
                 * সম্পদের **বইমূল্য**, কেনা দাম নয়।
                 *
                 * ⚠️ কেবল স্থায়ী সম্পদের খাত দেখালে পাঁচ বছরের পুরনো
                 * ট্রাক কেনা দামেই দেখাত। জমা অবচয় বাদ দিলে তবেই
                 * সংখ্যাটা আজকের কথা বলে।
                 */
                new Stat(
                    label: __('accounts::dashboard.asset_value'),
                    value: Money::format($facts->assetValue()),
                    hint: __('accounts::dashboard.asset_value_hint'),
                    href: route('accounts.asset.index'),
                    permission: 'accounts.asset.view',
                ),
                ...self::bookHealth($facts, $income, $expense),
            ],

            panels: [
                /*
                 * ── কেন আয় ও ব্যয় একটা ভাগে, দুইটা সংখ্যায় নয় ────────
                 * দুইটা আলাদা কার্ডে দেখালে চোখ দুইটা বড় সংখ্যা পড়ত আর
                 * **পার্থক্যটা নিজে বিয়োগ করতে হত**। পাশাপাশি রাখলে
                 * মাসটা লাভে না লোকসানে, সেটা তাকানোর সাথে সাথেই বোঝা যায়।
                 */
                config('abos.dashboards_v2') ? self::incomeExpenseProfit($facts, $income, $expense) : new Breakdown(
                    label: __('accounts::dashboard.month_so_far'),
                    parts: [
                        ['label' => __('accounts::dashboard.income'),
                            'value' => Money::format($income)],
                        ['label' => __('accounts::dashboard.expense'),
                            'value' => Money::format($expense)],
                    ],
                    hint: __('accounts::dashboard.month_so_far_hint'),
                ),
                ...self::trialBalance($facts),
                ...self::papersThisMonth(),
                ...self::incomeAndExpense($facts),
                ...self::currentPosition($facts),
            ],

            listings: [
                /*
                 * ⭐ প্রতিটা সারি তার নিজের খতিয়ানে নিয়ে যায়।
                 *
                 * "প্রাপ্য ৭,৯৯০" সংখ্যাটা উপরে আছে, কিন্তু পরের প্রশ্নটা
                 * সবসময় **কার কাছে** — আর সেটার উত্তর না থাকলে সংখ্যাটা
                 * দেখে কিছুই করার থাকে না।
                 */
                new Listing(
                    label: __('accounts::dashboard.top_due'),
                    columns: [
                        ['key' => 'name', 'label' => __('accounts::dashboard.customer'),
                            'render' => fn ($r) => $r->name],
                        ['key' => 'amount', 'label' => __('accounts::dashboard.amount'),
                            'width' => '10rem', 'numeric' => true,
                            'render' => fn ($r) => Money::format($r->amount)],
                    ],
                    rows: $dueRows,
                    empty: __('accounts::dashboard.nobody_owes'),
                    /*
                     * ⚠️ দরজাটা গ্রাহকের **বয়সের রিপোর্টে**, হিসাবের
                     * কোনো রিপোর্টে নয় — কারণ `accounts/reports/`-এ
                     * `receivable` নামে কিছু **নেই** (ReportController-এর
                     * SLUGS দেখা)। আগে ওটাই লেখা ছিল, আর সারিতে ক্লিক
                     * করলে **৪০৪** আসত।
                     *
                     * ⓘ বয়সের হিসাব ওখানেই একবার লেখা — এখানে আবার
                     * গুনলে দুইটা উত্তর তৈরি হত।
                     */
                    href: Route::has('customer.report.show')
                        ? route('customer.report.show', ['slug' => 'ageing'])
                        : null,
                ),

                new Listing(
                    label: __('accounts::dashboard.top_owed'),
                    columns: [
                        ['key' => 'name', 'label' => __('accounts::dashboard.supplier'),
                            'render' => fn ($r) => $r->name],
                        ['key' => 'amount', 'label' => __('accounts::dashboard.amount'),
                            'width' => '10rem', 'numeric' => true,
                            'render' => fn ($r) => Money::format($r->amount)],
                    ],
                    rows: $owedRows,
                    empty: __('accounts::dashboard.we_owe_nobody'),
                    href: Route::has('supplier.report.show')
                        ? route('supplier.report.show', ['slug' => 'ageing'])
                        : null,
                ),

                new Listing(
                    label: __('accounts::dashboard.needs_finishing'),
                    columns: [
                        ['key' => 'no', 'label' => __('accounts::dashboard.document'), 'width' => '18rem',
                            'render' => fn ($v) => view('accounts::partials.number-party', [
                                'no' => $v->document_no,
                                'party' => $draftParties[$v->id] ?? null,
                            ])],
                        ['key' => 'type', 'label' => __('accounts::dashboard.type'), 'width' => '8rem',
                            'render' => fn ($v) => $v->type],
                        ['key' => 'amount', 'label' => __('accounts::dashboard.amount'), 'width' => '10rem',
                            'render' => fn ($v) => Money::format($v->amount)],
                    ],
                    rows: $drafts,
                    empty: __('accounts::dashboard.nothing_draft'),
                    /*
                     * ── এখানে "সব দেখুন" লিংক নেই, আর সেটা ইচ্ছাকৃত ──
                     * ভাউচারের তালিকা ধরন-ভিত্তিক (`accounts/vouchers/{type}`)।
                     * খসড়া পাঁচ ধরনেই থাকতে পারে, তাই **একটাও তালিকা নেই
                     * যেখানে সবগুলো খসড়া একসাথে দেখা যায়**। যেকোনো একটা
                     * ধরন বেছে লিংক দিলে বাকি চার ধরনের খসড়া চুপচাপ বাদ
                     * পড়ত — আর সেটা লিংক না থাকার চেয়ে খারাপ।
                     */
                ),

                new Listing(
                    label: __('accounts::dashboard.transfers_waiting'),
                    columns: [
                        ['key' => 'no', 'label' => __('accounts::dashboard.document'), 'width' => '11rem',
                            'render' => fn ($t) => $t->document_no],
                        ['key' => 'amount', 'label' => __('accounts::dashboard.amount'), 'width' => '10rem',
                            'render' => fn ($t) => Money::format($t->amount)],
                    ],
                    rows: MoneyTransfer::query()->pending()->latest('id')->limit(8)->get(),
                    empty: __('accounts::dashboard.no_transfers_waiting'),
                    href: route('accounts.transfer.index'),
                ),
            ],

            /*
             * ⭐ খোলার পর্দায় ছয়টা গ্রাফ — মালিক, ২০ নভেম্বর ২০২৬ (নমুনা boards15 "acc"): আয়-ব্যয়ের রেখা, টাকা কোথায় (ডোনাট),
             *   প্রাপ্য-প্রদেয়ের বয়স (স্তূপ-দাগ), আজকের আসা-যাওয়া (কলাম), এ মাসের ভাউচার (ওয়াফল), খাতার স্বাস্থ্য (রেডিয়াল)। যে সংখ্যা-চার্ট
             *   গ্রাফে এল, পাতায় আর আলাদা নয়; বাকি (রেওয়ামিল, চলতি অবস্থান, দুই তালিকা, খসড়া, হস্তান্তর, ড্রয়ার, ঋণ, সম্পদ) নিচে।
             * ⓘ মালিকের ১০ নভেম্বরের সাত সারি ([[AccountsBoard]]) হারায় না — সবার নিচে, সমস্যা কেন্দ্র একদম শেষে।
             */
            sections: [
                Card::board(fn (): array => self::charts($facts, $income, $expense, $today), covers: [
                    __('accounts::dashboard.cash_in_hand'), __('accounts::dashboard.bank_balance'),
                    __('accounts::dashboard.receivable'), __('accounts::dashboard.payable'),
                    __('accounts::dashboard.today_collection'), __('accounts::dashboard.today_payment'), __('accounts::dashboard.today_expense'),
                    __('accounts::dashboard.net_profit_month'), __('accounts::dashboard.month_so_far'), __('accounts::dashboard.income_expense_months'),
                    __('accounts::dashboard.papers_this_month'), __('accounts::dashboard.posted_today'), __('accounts::dashboard.reversed_this_month'),
                    __('accounts::dashboard.backdated_this_month'), __('accounts::dashboard.awaiting_signature'),
                ], permission: 'accounts.view'),
                // ⭐ সারি 2 — লাভ-ক্ষতির সেতু, স্থিতিপত্র, রেওয়ামিল ও উপ-খাতার মিল
                [
                    'view' => 'accounts::dashboard.board-statements',
                    'place' => 'bottom',
                    /*
                     * ⛔ লাভ-ক্ষতি, বিক্রীত পণ্যের খরচ, স্থিতিপত্রে মজুদের মূল্য, স্থায়ী সম্পদ আর জমা লাভ — চূড়ান্ত বিবরণীর একই চাবি
                     *   (`accounts.report.final`; লাভ-ক্ষতি আর স্থিতিপত্রের পাতা যেটা চায়)। ড্যাশবোর্ড নিরীক্ষা, 23 Nov 2026: আগে কেবল
                     *   `accounts.view`-এ দেখা যেত, অথচ উপরের প্রথম গ্রাফ একই লাভ চাবি ছাড়া লুকায়।
                     */
                    'permission' => ['accounts.view', 'accounts.report.final'],
                    'data' => function (): array {
                        $board = app(AccountsBoard::class);

                        return ['pnl' => $board->pnl(), 'balance' => $board->balanceSheet(), 'checks' => $board->ledgerChecks()];
                    },
                ],
                // ⭐ সারি 3 — এক নজরে আর খরচের ট্রিম্যাপ
                [
                    'view' => 'accounts::dashboard.board-glance',
                    'place' => 'bottom',
                    'permission' => 'accounts.view',
                    'data' => function (): array {
                        $board = app(AccountsBoard::class);

                        // ⛔ নিট লাভের সারি আর খাত ধরে খরচ (খরচের খাতের রিপোর্টের চাবি) — কেবল `accounts.report.final`-এ; বাকি সারি সবার
                        $final = auth()->user()?->can('accounts.report.final') === true;

                        return [
                            'glance' => $final ? $board->glance() : array_values(array_filter($board->glance(), fn (array $k): bool => $k['key'] !== 'profit')),
                            'tree' => $final ? $board->expenseTree() : null,
                        ];
                    },
                ],
                // ⭐ সারি 4 — মাস বন্ধের সময়রেখা, ব্যাংক মিলকরণ, কর ও সরকারি দায়
                [
                    'view' => 'accounts::dashboard.board-close',
                    'place' => 'bottom',
                    'permission' => 'accounts.view',
                    'data' => function (): array {
                        $board = app(AccountsBoard::class);

                        return ['close' => $board->monthClose(), 'banks' => $board->banks(), 'taxes' => $board->taxes()];
                    },
                ],
                // ⭐ সারি 5 — ভাউচারের প্রবাহ, খরচ এ মাস বনাম গত মাস, আয় বনাম খরচ
                [
                    'view' => 'accounts::dashboard.board-trends',
                    'place' => 'bottom',
                    // ⛔ খাত ধরে খরচ আর আয় বনাম খরচ — খরচ/আয়ের খাতের রিপোর্টের একই চাবি (ড্যাশবোর্ড নিরীক্ষা, 23 Nov 2026)
                    'permission' => ['accounts.view', 'accounts.report.final'],
                    'data' => function (): array {
                        $board = app(AccountsBoard::class);

                        return ['flow' => $board->voucherFlow(), 'bullets' => $board->expenseVsLast(), 'ie' => $board->incomeVsExpense()];
                    },
                ],
                // ⭐ সারি 6 — কাগজের যাত্রা, ধরন ধরে টাকা, পোস্টিংয়ের ক্যালেন্ডার, নগদ বাক্স ও চেক
                [
                    'view' => 'accounts::dashboard.board-pipeline',
                    'place' => 'bottom',
                    'permission' => 'accounts.view',
                    'data' => fn (): array => ['pipe' => app(AccountsBoard::class)->pipeline()],
                ],
                // ⭐ সারি 7 — অনুমোদনে সময়, খাতার স্বাস্থ্য, সবচেয়ে বেশি নড়াচড়া, সাম্প্রতিক কাজ
                [
                    'view' => 'accounts::dashboard.board-review',
                    'place' => 'bottom',
                    'permission' => 'accounts.view',
                    'data' => function (): array {
                        $board = app(AccountsBoard::class);

                        return ['bubbles' => $board->approvalTimes(), 'health' => $board->ledgerHealth(), 'top' => $board->topAccounts(), 'recent' => $board->recentWork()];
                    },
                ],
                // ⭐ সারি 1 — সমস্যা কেন্দ্র আর মাস বন্ধ; গ্রাফের বোর্ডের পরে সবার শেষে (মালিক, ২০ নভেম্বর ২০২৬: "সমস্যা কেন্দ্র সবার নিচে")
                [
                    'view' => 'accounts::dashboard.board-top',
                    'place' => 'bottom',
                    'permission' => 'accounts.view',
                    'data' => function (): array {
                        $board = app(AccountsBoard::class);

                        return ['problems' => $board->problems(), 'close' => $board->monthClose()];
                    },
                ],
            ],
            /*
             * ⛔ পাতায় কেবল এই অংশগুলো (গ্রাফের বোর্ড + মালিকের ১০ নভেম্বরের সারি) — পুরনো সংখ্যার নয়টা বাক্স মালিক সেদিন সরিয়েছিলেন
             *   ("চূড়ান্ত নমুনায় নেই"), ২০ নভেম্বরের নমুনাও সেগুলো ফেরায়নি। সংখ্যা-চার্ট-তালিকা হোম, ফোন আর খোঁজায় আগের মতোই।
             */
            board: true,
        );
    }

    /**
     * ⭐ হিসাবের গ্রাফের বোর্ড — মালিকের অনুমোদিত নমুনা (boards15 "acc", ২০ নভেম্বর ২০২৬)।
     *
     * ⓘ প্রতিটা অঙ্ক আগের ঘরগুলোর একই সংজ্ঞা থেকে ([[AccountsFacts]]) — দেখার শাখা, চাবি সব আগের মতো; যে ঘরের চাবি নেই, তার
     *   অংশ গ্রাফেও নেই (ব্যাংক আর চূড়ান্ত হিসাব `accounts.report.final`, আজকের তিনটা নিজের নিজের চাবিতে)।
     * ⓘ টাকা স্ট্রিং হয়ে যায়; ছোট লেখায় (৳ 9.8 লা) কেবল পূর্ণ টাকার পূর্ণসংখ্যা — float নয়।
     *
     * @param  array{collection: string, payment: string, expense: string}  $today
     * @return list<array<string, mixed>>
     */
    private static function charts(AccountsFacts $facts, string $income, string $expense, array $today): array
    {
        $user = auth()->user();
        $final = (bool) $user?->can('accounts.report.final');
        $short = fn (string $amount): string => (bccomp($amount, '0', 4) < 0 ? '−' : '').'৳ '.Svg::short((int) ltrim(Money::round($amount, 0), '-'));
        $t = fn (string $key, array $with = []): string => (string) __('accounts::dashboard.'.$key, $with);

        // ── ১. আয় ও ব্যয়, ছয় মাস — [[incomeAndExpense()]]-এর একই মাস; চূড়ান্ত হিসাবের চাবিতে
        $months = $final ? $facts->incomeExpenseByMonth(6) : [];
        $profit = $facts->netProfit($income, $expense);

        // ── ২. টাকা কোথায় — হাতে নগদ (ড্রয়ার, আগের ঘরের হুবহু), ব্যাংক আর MFS (চূড়ান্ত চাবিতে)
        $money = [[$t('cash_in_hand'), $facts->cashInHand(), Svg::STATUS[0]]];
        if ($final) {
            $money[] = [$t('bank_balance'), $facts->bankBalance(), Svg::PALETTE[0]];
            $money[] = [$t('viz_mfs'), $facts->moneyPositions()['mfs'], Svg::PALETTE[5]];
        }
        $money = array_values(array_filter($money, fn (array $m) => bccomp($m[1], '0', 4) > 0));
        $moneyTotal = array_reduce($money, fn (string $sum, array $m) => bcadd($sum, $m[1], 4), '0');

        // ── ৩. প্রাপ্য বনাম প্রদেয়, বয়স ধরে — গ্রাহক আর সরবরাহকারীর বয়সের রিপোর্টের মোট (রিপোর্টের চাবি থাকলে)
        $ageing = [];
        foreach ([['customer.ageing', 'customer.report', 'receivable'], ['supplier.ageing', 'supplier.report', 'payable']] as [$key, $permission, $label]) {
            $buckets = self::ageingBuckets($key, $permission);
            if ($buckets !== null) {
                $ageing[] = [$t($label), $buckets];
            }
        }

        // ── ৪. আজকের আসা-যাওয়া — [[AccountsFacts::today()]], প্রতিটা নিজের চাবিতে (আগের তিন ঘরের মতো)
        $flow = array_values(array_filter([
            $user?->can('sales.collection.view') ? [$t('viz_in'), $today['collection'], Svg::STATUS[0], '1'] : null,
            $user?->can('purchase.payment.view') ? [$t('viz_paid'), $today['payment'], Svg::STATUS[3], '-1'] : null,
            $user?->can('accounts.report') ? [$t('viz_spent'), $today['expense'], '#f59e0b', '-1'] : null,
        ]));
        $flowNet = array_reduce($flow, fn (string $sum, array $f) => bcadd($sum, bcmul($f[1], $f[3], 4), 4), '0');

        // ── ৫. এ মাসের ভাউচার, ধরন ধরে — বাতিল বাদ; আজ পোস্ট হওয়া নিচের লাইনে
        $byType = Voucher::query()->where('status', '<>', DocumentStatus::CANCELLED)
            ->whereBetween('trx_date', [Carbon::today()->startOfMonth()->toDateString(), Carbon::today()->toDateString()])
            ->selectRaw('type, COUNT(*) as n')->groupBy('type')->toBase()->pluck('n', 'type');
        $papers = array_values(array_filter(array_map(
            fn (string $type) => [(string) __('accounts::menu.'.$type), (int) ($byType[$type] ?? 0), (string) (int) ($byType[$type] ?? 0)],
            Voucher::TYPES,
        ), fn (array $p) => $p[1] > 0));
        $paperCount = array_sum(array_column($papers, 1));
        $activity = $facts->ledgerActivity();

        // ── ৬. খাতার স্বাস্থ্য — [[papersThisMonth()]] আর [[bookHealth()]]-এর একই গোনা, অনুপাতে
        $counts = self::monthCounts();
        $awaiting = $facts->vouchersAwaitingSignature();
        $written = $counts['draft'] + $counts['posted'];
        $share = fn (int $good, int $all): float => $all > 0 ? max(0, min($good, $all)) / $all : 0;
        $health = $written === 0 ? [] : ['p' => [
            [$t('papers_posted'), $share($counts['posted'], $written)],
            [$t('viz_signed'), $share($counts['posted'], $counts['posted'] + $awaiting)],
            [$t('viz_on_date'), $counts['posted'] > 0 ? $share($counts['posted'] - $activity['backdated_this_month'], $counts['posted']) : 1],
            [$t('viz_not_reversed'), $counts['posted'] > 0 ? $share($counts['posted'] - $activity['reversed_this_month'], $counts['posted']) : 1],
        ]];

        return [
            Card::make($t('viz_ie'), 'line', [
                'v' => array_column($months, 'income'), 'v2' => array_column($months, 'expense'), 'l' => array_column($months, 'month'),
                'color' => Svg::STATUS[0], 'c2' => '#f59e0b',
            ], value: $final ? $short($profit) : '', sub: $final ? $t('viz_ie_sub', ['income' => Money::format($income), 'expense' => Money::format($expense)]) : $t('viz_ie_hint'),
                legend: [[$t('income'), Svg::STATUS[0]], [$t('expense'), '#f59e0b']],
                href: Route::has('accounts.report.final.profit_loss') && $final ? route('accounts.report.final.profit_loss') : '', colour: Svg::STATUS[0]),
            Card::make($t('viz_where'), 'donut', [
                'c' => $short($moneyTotal), 'cs' => $t('viz_total'),
                'p' => array_map(fn (array $m) => [$m[0], $m[1], $m[2], $short($m[1])], $money),
            ], value: $money === [] ? '' : $short($moneyTotal), sub: $final ? $t('viz_where_sub') : $t('viz_where_sub_cash'),
                href: route('accounts.till.index'), colour: Svg::PALETTE[0]),
            Card::make($t('viz_age'), 'stack100', ['rows' => $ageing, 'colors' => Svg::STATUS],
                sub: $t('viz_age_sub', ['in' => Money::format($facts->receivable()), 'out' => Money::format($facts->payable())]),
                legend: [[$t('viz_age_0'), Svg::STATUS[0]], [$t('viz_age_30'), Svg::STATUS[1]], [$t('viz_age_60'), Svg::STATUS[2]], [$t('viz_age_90'), Svg::STATUS[3]]],
                href: Route::has('customer.report.show') ? route('customer.report.show', ['slug' => 'ageing']) : '', colour: '#f97316'),
            Card::make($t('viz_today'), 'bars', [
                'v' => array_column($flow, 1), 'l' => array_column($flow, 0), 'colors' => array_column($flow, 2),
                'top' => array_map(fn (array $f) => $short($f[1]), $flow),
            ], value: $flow === [] ? '' : (bccomp($flowNet, '0', 4) > 0 ? '+' : '').$short($flowNet), sub: $t('viz_today_sub'), colour: Svg::PALETTE[2]),
            Card::make($t('papers_this_month'), 'waffle', ['p' => $papers],
                value: $t('viz_count', ['n' => $paperCount]), sub: $t('viz_papers_sub', ['n' => $activity['posted_today']]),
                href: Route::has('accounts.voucher.list') ? route('accounts.voucher.list') : '', colour: Svg::PALETTE[1]),
            Card::make($t('viz_health'), 'radial', $health, sub: $t('viz_health_sub', [
                'draft' => $counts['draft'], 'awaiting' => $awaiting, 'back' => $activity['backdated_this_month'],
                'reversed' => $activity['reversed_this_month'], 'cancelled' => $counts['cancelled'],
            ]), href: Route::has('approval.inbox.index') && $awaiting > 0 ? route('approval.inbox.index') : '', colour: '#e11d48'),
        ];
    }

    /**
     * বয়সের রিপোর্টের মোট চার ধাপে — রিপোর্টটাই চালানো হয় (গ্রাহক বা সরবরাহকারীর), তাই রিপোর্ট আর গ্রাফ কখনো দুই কথা বলে না।
     * ⓘ মডিউলের ক্লাস নয়, রিপোর্টের নাম ধরে ([[ReportEngine]]) — হিসাব কারও উপর দাঁড়ায় না। ⛔ রিপোর্টের চাবি না থাকলে, বা মডিউলটাই
     * না থাকলে, null — সারিটাই নেই।
     *
     * @return list<string>|null
     */
    private static function ageingBuckets(string $key, string $permission): ?array
    {
        $engine = app(ReportEngine::class);

        if (! auth()->user()?->can($permission) || ! in_array($key, $engine->keys(), true)) {
            return null;
        }

        $totals = $engine->run($key, ['to' => Carbon::today()->toDateString(), 'branch_id' => ViewedBranch::one()], 1, 1)->totals;

        return array_map(fn (string $bucket) => (string) ($totals[$bucket] ?? '0'), ['bucket_current', 'bucket_30', 'bucket_60', 'bucket_90']);
    }

    /**
     * এ মাসের ভাউচার অবস্থা ধরে — খসড়া, পোস্ট (নিশ্চিত + বন্ধ), বাতিল। [[papersThisMonth()]] আর গ্রাফের বোর্ড দুইটাই এটা পড়ে।
     *
     * @return array{draft: int, posted: int, cancelled: int}
     */
    private static function monthCounts(): array
    {
        $counts = Voucher::query()
            ->whereBetween('trx_date', [Carbon::today()->startOfMonth()->toDateString(), Carbon::today()->toDateString()])
            ->selectRaw('status, COUNT(*) as n')
            ->groupBy('status')
            ->toBase()->pluck('n', 'status');

        return [
            'draft' => (int) ($counts[DocumentStatus::DRAFT] ?? 0),
            'posted' => (int) ($counts[DocumentStatus::CONFIRMED] ?? 0) + (int) ($counts[DocumentStatus::CLOSED] ?? 0),
            'cancelled' => (int) ($counts[DocumentStatus::CANCELLED] ?? 0),
        ];
    }

    /**
     * ⭐ রেওয়ামিল এক নজরে — মোট ডেবিট আর মোট ক্রেডিট; সমান হলে "মিলেছে", নাহলে পার্থক্য (মালিকের ড্যাশবোর্ড নকশা,
     * ৩ অক্টোবর ২০২৬)। ⓘ নতুন ড্যাশবোর্ডের অংশ (config abos.dashboards_v2), কেবল accounts.view-এ।
     *
     * @return list<Breakdown>
     */
    private static function trialBalance(AccountsFacts $facts): array
    {
        // ⛔ চূড়ান্ত হিসাবের অঙ্ক — চূড়ান্ত রিপোর্টের চাবি (অডিট ম১৩/ম১৮, ১০ অক্টোবর ২০২৬); আগে accounts.view-ই যথেষ্ট ছিল
        if (! config('abos.dashboards_v2') || ! auth()->user()?->can('accounts.report.final')) {
            return [];
        }

        $totals = $facts->trialBalanceTotals();
        $gap = bcsub($totals['debit'], $totals['credit'], 2);

        return [new Breakdown(
            label: __('accounts::dashboard.trial_balance'),
            parts: [
                ['label' => __('accounts::dashboard.total_debit'), 'value' => Money::format($totals['debit'])],
                ['label' => __('accounts::dashboard.total_credit'), 'value' => Money::format($totals['credit'])],
            ],
            hint: bccomp($gap, '0', 2) === 0
                ? __('accounts::dashboard.trial_balance_ok')
                : __('accounts::dashboard.trial_balance_off', ['gap' => Money::format(ltrim($gap, '-'))]),
        )];
    }

    /**
     * ⭐ এ মাসের ভাউচার কোন অবস্থায় — খসড়া, পোস্ট হয়েছে, বাতিল (মালিকের ড্যাশবোর্ড নকশা)। ⓘ খসড়া মানে খাতায় এখনো
     * ওঠেনি — দিনশেষে সংখ্যাটা শূন্যে নামার কথা। দেখার শাখা মানে (মডেলের নিজের পাহারা)।
     *
     * @return list<Breakdown>
     */
    private static function papersThisMonth(): array
    {
        if (! config('abos.dashboards_v2') || ! auth()->user()?->can('accounts.view')) {
            return [];
        }

        // ⓘ গোনা এক জায়গায় — গ্রাফের বোর্ডের "খাতার স্বাস্থ্য"-ও এটাই পড়ে ([[monthCounts()]])
        $counts = self::monthCounts();

        return [new Breakdown(
            label: __('accounts::dashboard.papers_this_month'),
            parts: [
                ['label' => __('accounts::dashboard.papers_draft'), 'value' => (string) $counts['draft']],
                ['label' => __('accounts::dashboard.papers_posted'), 'value' => (string) $counts['posted']],
                ['label' => __('accounts::dashboard.papers_cancelled'), 'value' => (string) $counts['cancelled']],
            ],
            hint: __('accounts::dashboard.papers_this_month_hint'),
            range: DateRange::label(\Illuminate\Support\Carbon::today()->startOfMonth(), \Illuminate\Support\Carbon::today()),
        )];
    }

    /**
     * ⭐ আয় আর ব্যয় — গত ছয় মাস (খাতার চলাচল; মালিকের ড্যাশবোর্ড নকশা)। ⓘ দুই দণ্ড একই মাপের (টাকা), এক অক্ষ।
     *
     * @return list<\App\Core\Engines\Dashboard\Series>
     */
    private static function incomeAndExpense(AccountsFacts $facts): array
    {
        // ⛔ চূড়ান্ত হিসাবের অঙ্ক — চূড়ান্ত রিপোর্টের চাবি (অডিট ম১৩/ম১৮, ১০ অক্টোবর ২০২৬); আগে accounts.view-ই যথেষ্ট ছিল
        if (! config('abos.dashboards_v2') || ! auth()->user()?->can('accounts.report.final')) {
            return [];
        }

        $months = $facts->incomeExpenseByMonth(6);

        return [new \App\Core\Engines\Dashboard\Series(
            label: __('accounts::dashboard.income_expense_months'),
            points: array_map(fn (array $m) => [
                'label' => $m['month'],
                'first' => $m['income'],
                'second' => $m['expense'],
                'firstTitle' => Money::format($m['income']),
                'secondTitle' => Money::format($m['expense']),
            ], $months),
            firstLabel: __('accounts::dashboard.income'),
            secondLabel: __('accounts::dashboard.expense'),
            // ⓘ ছয় মাসের প্রথম দিন থেকে আজ — [[AccountsFacts::incomeExpenseByMonth()]]-এর একই শুরু (৫ অক্টোবর ২০২৬)
            range: DateRange::label(\Illuminate\Support\Carbon::today()->startOfMonth()->subMonths(5), \Illuminate\Support\Carbon::today()),
        )];
    }

    /**
     * ⭐ প্রথম চার্ট — মোট আয়, মোট ব্যয় আর নিট লাভ, পাশাপাশি স্তম্ভে (মালিক, ৫ অক্টোবর ২০২৬)।
     *
     * ⓘ হোমে বসলে হোমের বাছা সময় মানে ([[HomePeriod::chosen()]]): "আজ" বাছলে আজকের, "বছর" বাছলে অর্থবছরের শুরু থেকে।
     * ⛔ মালিকের অভিযোগ: তিনি "আজ" বেছেছিলেন অথচ চার্ট মাস দেখাচ্ছিল। মডিউলের নিজের পাতায় (বাছা নেই) এ মাস, আগের মতো।
     * ⓘ সংখ্যা [[AccountsFacts::netOfType()]] থেকে — মাসের বেলায় ঠিক উপরে পড়া দুই সংখ্যাই, আবার পড়া হয় না;
     * নিট লাভ [[AccountsFacts::netProfit()]]। ⓘ স্তম্ভ, ডোনাট নয়: লোকসানের মাসে নিট লাভ ঋণাত্মক, ডোনাট সেটা আঁকতে পারে না।
     * ⓘ চার্টের নিচে কোন তারিখ থেকে কোন তারিখ ([[DateRange::label()]])।
     */
    private static function incomeExpenseProfit(AccountsFacts $facts, string $monthIncome, string $monthExpense): Breakdown
    {
        $period = HomePeriod::chosen() ?? 'month';
        [$from, $to] = HomePeriod::window($period);

        [$income, $expense] = $period === 'month'
            ? [$monthIncome, $monthExpense]
            : [
                $facts->netOfType(Account::INCOME, \Illuminate\Support\Carbon::parse($from), \Illuminate\Support\Carbon::parse($to)),
                $facts->netOfType(Account::EXPENSE, \Illuminate\Support\Carbon::parse($from), \Illuminate\Support\Carbon::parse($to)),
            ];

        return new Breakdown(
            label: __('accounts::dashboard.'.match ($period) {
                'today' => 'today_so_far',
                'year' => 'year_so_far',
                default => 'month_so_far',
            }),
            parts: [
                ['label' => __('accounts::dashboard.total_income'), 'value' => Money::format($income)],
                ['label' => __('accounts::dashboard.total_expense'), 'value' => Money::format($expense)],
                ['label' => __('accounts::dashboard.net_profit'), 'value' => Money::format($facts->netProfit($income, $expense))],
            ],
            hint: __('accounts::dashboard.income_expense_profit_hint'),
            chart: 'columns',
            range: DateRange::label($from, $to),
        );
    }

    /**
     * ⭐ খাতার স্বাস্থ্য এক নজরে — এ মাসের নিট লাভ, আজ কয়টা ভাউচার পোস্ট হলো, এ মাসে কয়টা উল্টানো, কয়টা পিছনের
     * তারিখে, কয়টা ড্রয়ার শূন্যের নিচে, আর কয়টা ভাউচার সইয়ের অপেক্ষায় (মালিকের ড্যাশবোর্ড নকশা, ৫ অক্টোবর ২০২৬)।
     *
     * ⓘ প্রতিটা সংখ্যার সংজ্ঞা [[AccountsFacts]]-এ, এখানে কেবল সাজানো। ⓘ নতুন ড্যাশবোর্ডের অংশ (config
     * abos.dashboards_v2), কেবল accounts.view-এ — পাশের রেওয়ামিলের একই চাবি।
     * ⚠️ শূন্যের বেশি হলে সাবধানের রং: পিছনের তারিখ, শূন্যের নিচের ড্রয়ার আর ঝুলে থাকা সই — তিনটাই দিনশেষে শূন্য
     * থাকার কথা।
     *
     * @return list<Stat>
     */
    private static function bookHealth(AccountsFacts $facts, string $income, string $expense): array
    {
        if (! config('abos.dashboards_v2') || ! auth()->user()?->can('accounts.view')) {
            return [];
        }

        $profit = $facts->netProfit($income, $expense);
        $activity = $facts->ledgerActivity();
        $belowZero = $facts->tillsBelowZero();
        $awaiting = $facts->vouchersAwaitingSignature();

        return [
            new Stat(
                label: __('accounts::dashboard.net_profit_month'),
                value: Money::format($profit),
                hint: __('accounts::dashboard.net_profit_month_hint'),
                href: Route::has('accounts.report.final.profit_loss') ? route('accounts.report.final.profit_loss') : null,
                // ⛔ মাসের নিট লাভ — চূড়ান্ত রিপোর্টের চাবি (অডিট ম১৩/ম১৮)
                permission: 'accounts.report.final',
                tone: bccomp($profit, '0', 4) < 0 ? Stat::BAD : Stat::GOOD,
            ),
            new Stat(
                label: __('accounts::dashboard.posted_today'),
                value: (string) $activity['posted_today'],
                hint: __('accounts::dashboard.posted_today_hint'),
                href: Route::has('accounts.voucher.list') ? route('accounts.voucher.list') : null,
            ),
            new Stat(
                label: __('accounts::dashboard.reversed_this_month'),
                value: (string) $activity['reversed_this_month'],
                hint: __('accounts::dashboard.reversed_this_month_hint'),
            ),
            new Stat(
                label: __('accounts::dashboard.backdated_this_month'),
                value: (string) $activity['backdated_this_month'],
                hint: __('accounts::dashboard.backdated_this_month_hint'),
                tone: $activity['backdated_this_month'] > 0 ? Stat::WARN : Stat::NEUTRAL,
            ),
            new Stat(
                label: __('accounts::dashboard.tills_below_zero'),
                value: (string) $belowZero,
                hint: __('accounts::dashboard.tills_below_zero_hint'),
                href: route('accounts.till.index'),
                tone: $belowZero > 0 ? Stat::BAD : Stat::NEUTRAL,
                permission: 'accounts.till.view',
            ),
            new Stat(
                label: __('accounts::dashboard.awaiting_signature'),
                value: (string) $awaiting,
                hint: __('accounts::dashboard.awaiting_signature_hint'),
                href: Route::has('approval.inbox.index') ? route('approval.inbox.index') : null,
                tone: $awaiting > 0 ? Stat::WARN : Stat::NEUTRAL,
            ),
        ];
    }

    /**
     * ⭐ চলতি সম্পদ, চলতি দায় আর নিট সম্পদ — পাশাপাশি দণ্ডে (মালিকের ড্যাশবোর্ড নকশা, ৫ অক্টোবর ২০২৬)।
     *
     * ⓘ "চলতি" ছকের নিজের দল ধরে (১১০০, ২১০০) — [[AccountsFacts::currentPosition()]]-এ কারণ লেখা। ইঙ্গিতে চলতি মূলধন
     * (চলতি সম্পদ − চলতি দায়): ঋণাত্মক মানে সামনের দেনা মেটানোর মতো চলতি সম্পদ নেই।
     * ⓘ নতুন ড্যাশবোর্ডের অংশ (config abos.dashboards_v2), কেবল accounts.view-এ।
     *
     * @return list<Breakdown>
     */
    private static function currentPosition(AccountsFacts $facts): array
    {
        // ⛔ চূড়ান্ত হিসাবের অঙ্ক — চূড়ান্ত রিপোর্টের চাবি (অডিট ম১৩/ম১৮, ১০ অক্টোবর ২০২৬); আগে accounts.view-ই যথেষ্ট ছিল
        if (! config('abos.dashboards_v2') || ! auth()->user()?->can('accounts.report.final')) {
            return [];
        }

        $position = $facts->currentPosition();

        return [new Breakdown(
            label: __('accounts::dashboard.current_position'),
            parts: [
                ['label' => __('accounts::dashboard.current_assets'), 'value' => Money::format($position['current_assets'])],
                ['label' => __('accounts::dashboard.current_liabilities'), 'value' => Money::format($position['current_liabilities'])],
                ['label' => __('accounts::dashboard.net_assets'), 'value' => Money::format($position['net_assets'])],
            ],
            hint: __('accounts::dashboard.current_position_hint', [
                'working' => Money::format(bcsub($position['current_assets'], $position['current_liabilities'], 4)),
            ]),
            chart: 'hbars',
        )];
    }
}
