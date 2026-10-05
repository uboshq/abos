<?php

declare(strict_types=1);

namespace App\Modules\Finance\Dashboard;

use App\Core\Contracts\ProvidesDashboard;
use App\Core\Dashboard\HomePeriod;
use App\Core\Engines\Dashboard\Breakdown;
use App\Core\Engines\Dashboard\DashboardDefinition;
use App\Core\Engines\Dashboard\DateRange;
use App\Core\Engines\Dashboard\Listing;
use App\Core\Engines\Dashboard\Stat;
use App\Core\Engines\Dashboard\Tile;
use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Modules\Accounts\Models\BankReconciliation;
use App\Modules\Accounts\Models\BankStatementLine;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\AccountsFacts;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\CapitalEntry;
use App\Modules\Finance\Models\Deposit;
use App\Modules\Finance\Models\Withdrawal;
use App\Modules\Finance\Services\BudgetService;
use App\Modules\Finance\Services\HeadTotals;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/**
 * অর্থ মডিউলের ড্যাশবোর্ড।
 *
 * ── কেন মূলধন আর উত্তোলন পাশাপাশি ────────────────────────────────────
 * দুইটা একই প্রশ্নের দুই দিক: **মালিকের টাকা ব্যবসায় কত ঢুকেছে, আর কত
 * বেরিয়েছে।** আলাদা পর্দায় রাখলে কেউ একটা দেখে সিদ্ধান্ত নিতেন, আর
 * সেটা অর্ধেক ছবি।
 */
final class FinanceDashboard implements ProvidesDashboard
{
    public static function dashboard(): DashboardDefinition
    {
        /*
         * ⭐ হোমে বসলে হোমের বাছা সময় — মালিক, ৫ অক্টোবর ২০২৬: তিনি "আজ" বেছেছিলেন অথচ এই চার্ট মাস দেখাচ্ছিল।
         * ⓘ [[HomePeriod::chosen()]] কেবল হোম আঁকার সময় কিছু বলে; অর্থের নিজের পাতায় `null`, তখন এ মাস — আগের মতো।
         */
        $period = HomePeriod::chosen() ?? 'month';
        [$spentFrom, $spentTo] = HomePeriod::window($period);

        $expenseHeads = array_map(
            fn (array $row) => [
                'label' => $row['label'],
                'value' => Money::format($row['amount']),
            ],
            app(HeadTotals::class)->topUnder(
                StandardChart::OPERATING_EXPENSES,
                $spentFrom,
                $spentTo,
            ),
        );

        $facts = app(AccountsFacts::class);
        $money = $facts->moneyPositions();

        /*
         * দরজাগুলো `Route::has()`-এর পিছনে — হিসাব, গ্রাহক বা
         * সরবরাহকারী মডিউল বন্ধ থাকলে অর্থের পাতাটা যেন না মরে।
         */
        $cashBook = Route::has('accounts.report.show')
            ? route('accounts.report.show', ['slug' => 'cash-book']) : null;
        $bankBook = Route::has('accounts.report.show')
            ? route('accounts.report.show', ['slug' => 'bank-book']) : null;
        $custody = Route::has('accounts.custody') ? route('accounts.custody') : null;
        $customerAgeing = Route::has('customer.report.show')
            ? route('customer.report.show', ['slug' => 'ageing']) : null;
        $supplierAgeing = Route::has('supplier.report.show')
            ? route('supplier.report.show', ['slug' => 'ageing']) : null;

        return new DashboardDefinition(
            title: __('finance::dashboard.title'),
            subtitle: __('finance::dashboard.subtitle'),

            /*
             * ⚠️ `array_filter` — কারণ নিচের কয়েকটা টালি **অন্য মডিউলের
             * পর্দায় যায়**, আর কোম্পানি ওই মডিউল বন্ধ রাখতে পারে।
             *
             * সরাসরি `route()` ডাকলে বন্ধ মডিউলে **গোটা অর্থের ড্যাশবোর্ড
             * ৫০০** হত — অর্থাৎ একটা ঐচ্ছিক লিংকের জন্য নিজের পাতাটাই
             * মরত। ⓘ পাহারাটা এটা ধরে
             * ([[EveryModuleDashboardHasItsOwnDoorTest]]), আর ঠিকই ধরে।
             */
            tiles: array_values(array_filter([
                new Tile(label: __('finance::menu.capital'), href: route('finance.capital.index'),
                    permission: 'finance.capital.view', icon: 'cash'),
                new Tile(label: __('finance::menu.withdrawal'), href: route('finance.withdrawal.index'),
                    permission: 'finance.withdrawal.view', icon: 'reports'),

                /*
                 * ── চারটা দরজা, ৪ সেপ্টেম্বর ২০২৬ ───────────────────
                 *
                 * চারটাই এমন জিনিস যার **ইঞ্জিন আগে থেকেই ছিল, কিন্তু
                 * অর্থের পাতা থেকে যাওয়ার পথ ছিল না**। CFO রোজ এই
                 * পাতাটা খোলেন; তাঁকে অনুমোদন দেখতে অনুমোদনের মডিউলে,
                 * খরচের খাত দেখতে খরচের পাতায়, আর পণ্যের খরচ দেখতে
                 * মজুদে — তিন জায়গায় যেতে হত।
                 *
                 * ⓘ নতুন কোনো ইঞ্জিন এখানে বানানো হয়নি, একটাও নয়।
                 */
                Route::has('approval.inbox.index')
                    ? new Tile(label: __('finance::dashboard.pending_approvals'),
                        href: route('approval.inbox.index'),
                        permission: 'approval.decide', icon: 'check-circle')
                    : null,

                /*
                 * ⓘ দরজাটা এখন হিসাবের রিপোর্টে — ১৮ সেপ্টেম্বর ২০২৬।
                 * মালিক খরচের পর্দা অর্থ থেকে তুলে দিতে বলেছেন; টাইলটা
                 * রয়ে গেল, কেবল গন্তব্য বদলাল — প্রশ্নটা তো বদলায়নি।
                 */
                new Tile(label: __('finance::dashboard.expense_heads'),
                    href: route('accounts.report.show', ['slug' => 'expense-by-head']),
                    permission: 'accounts.report', icon: 'reports'),

                /*
                 * ⚠️ পণ্যের খরচ **অনুমতির পিছনে** — `inventory.cost.view`।
                 * এই একই তালা আজ সকালে পণ্যের তালিকা ও চলাচলের পর্দায়
                 * বসানো হয়েছে; দরজাটা খোলা রাখলে ওই দুইটার মানেই থাকত না।
                 */
                Route::has('inventory.stock.movement')
                    ? new Tile(label: __('finance::dashboard.product_costing'),
                        href: route('inventory.stock.movement', ['type' => 'slow']),
                        // ⚠️ 'box' আইকন-সেটে নেই — নাম ভুল হলে পর্দায় **কিচ্ছু
                        // আঁকা হয় না, আর কোনো ত্রুটিও দেখা যায় না** (নীরব
                        // ফাঁকা জায়গা)। দরজাটা মজুদের পর্দায় যায়, তাই এটাই।
                        permission: 'inventory.cost.view', icon: 'inventory')
                    : null,

                Route::has('approval.flow.index')
                    ? new Tile(label: __('finance::dashboard.who_approves_what'),
                        href: route('approval.flow.index'),
                        // 'shield'-ও সেটে ছিল না; দরজাটা অনুমোদনের ছকে যায়
                        permission: 'approval.flow.manage', icon: 'approval')
                    : null,
            ])),

            stats: [
                /*
                 * ── টাকার তিনটা অবস্থান, ৪ সেপ্টেম্বর ২০২৬ ──────────
                 *
                 * ⚠️ **MFS আলাদা, ব্যাংকের সাথে নয়** — বিকাশ ক্যাশ-আউটে
                 * চার্জ কাটে, মিলকরণের কাগজ আলাদা, সেটেলমেন্টের সময়ও।
                 * এক ঘরে দেখালে "ব্যাংকে কত আছে" সংখ্যাটাই মিথ্যা বলত।
                 *
                 * ⭐ আর মেপে একটা জিনিস বেরিয়েছে: `1105-BKASH`-এ
                 * `is_bank`ও নেই, `is_cash`ও নেই — তাই আজ পর্যন্ত
                 * **MFS-এর টাকা কোনো টালিতেই গোনা হত না**, না নগদে,
                 * না ব্যাংকে। এই কোম্পানিতে সেটা ১,২৫০ টাকা।
                 */
                new Stat(
                    label: __('finance::dashboard.cash_position'),
                    value: Money::format($money['cash']),
                    hint: __('finance::dashboard.cash_position_hint'),
                    href: $cashBook,
                    permission: 'accounts.view',
                    tone: Stat::GOOD,
                ),

                new Stat(
                    label: __('finance::dashboard.bank_position'),
                    value: Money::format($money['bank']),
                    hint: __('finance::dashboard.bank_position_hint'),
                    href: $bankBook,
                    permission: 'accounts.view',
                ),

                new Stat(
                    label: __('finance::dashboard.mfs_position'),
                    value: Money::format($money['mfs']),
                    hint: __('finance::dashboard.mfs_position_hint'),
                    href: $custody,
                    permission: 'accounts.view',
                ),
                ...self::fundAndDues($facts, $money, $cashBook, $supplierAgeing),

                /*
                 * ⓘ বয়সের ভাগটা এখানে গোনা হয় না — দরজাটা **যেখানে
                 * ওটা একবার লেখা আছে** সেখানেই যায়। দুই জায়গায় দুইভাবে
                 * গুনলে দুইটা উত্তর তৈরি হত, আর তখন কোনটা সত্যি তা কেউ
                 * বলতে পারত না।
                 */
                new Stat(
                    label: __('finance::dashboard.receivable_overview'),
                    value: Money::format($facts->receivable()),
                    hint: __('finance::dashboard.receivable_overview_hint'),
                    href: $customerAgeing,
                    permission: 'accounts.view',
                ),

                new Stat(
                    label: __('finance::dashboard.payable_overview'),
                    value: Money::format($facts->payable()),
                    hint: __('finance::dashboard.payable_overview_hint'),
                    href: $supplierAgeing,
                    permission: 'accounts.view',
                    tone: Stat::BAD,
                ),

                new Stat(
                    label: __('finance::dashboard.capital_in'),
                    /*
                     * ⛔ আগে এখানে ও `where('entry_type', 'in')` লেখা ছিল, আর
                     * `'in'` বলে কোনো মান **কখনো লেখা হয় না**।
                     *
                     * ⓘ ঘরটা ধরে `contribution` বা `investment`
                     * ([[CapitalEntry::KINDS]]) — যাচাইকরণ সেটাই বাধ্য করে,
                     * [[CapitalFromReceipt]] সেটাই লেখে, আর ফরমের ডিফল্টও
                     * সেটাই।
                     *
                     * ⚠️ তাই ছাঁকনিটা **সবসময় শূন্য সারি** পেত আর টাইলটা
                     * ০.০০ দেখাত, যত টাকাই খাতায় বসুক। ⛔ কোনো ত্রুটি নয়,
                     * কোনো ৫০০ নয় — সংখ্যাটা শুধু চিরকাল শূন্য।
                     *
                     * ⭐ আর লক্ষণটা এত বিভ্রান্তিকর এই কারণে: পাশের টাইলে
                     * **দাতার সংখ্যা** ঠিক দেখায় (ওখানে কোনো ছাঁকনি নেই),
                     * তাই পর্দা বলত *"একজন দাতা, মোট ০.০০"* — পড়তে ডেটা
                     * হারানোর মতো লাগত, অথচ ডেটা অক্ষত।
                     *
                     * ⓘ `KINDS`-এ `PROFIT` **নেই, আর সেটা ইচ্ছাকৃত**: ধরে
                     * রাখা মুনাফা মূলধনের সারিতে বসে, কিন্তু ওটা **বাইরে
                     * থেকে আসা টাকা নয়**। ⚠️ সব সারি যোগ করলে টাইলটা
                     * উল্টো দিকে ভুল বলত, আর **বড় সংখ্যা শূন্যের চেয়ে অনেক
                     * কঠিন ধরা**।
                     *
                     * ⓘ পাহারা: [[TheDashboardLookedForAWordNobodyWritesTest]]।
                     */
                    value: Money::format(
                        CapitalEntry::query()->whereIn('entry_type', CapitalEntry::KINDS)->sum('amount')
                    ),
                    hint: __('finance::dashboard.capital_in_hint'),
                    href: route('finance.capital.index'),
                    tone: Stat::GOOD,
                ),
                new Stat(
                    label: __('finance::dashboard.withdrawn'),
                    value: Money::format(Withdrawal::query()->sum('amount')),
                    hint: __('finance::dashboard.withdrawn_hint'),
                    href: route('finance.withdrawal.index'),
                    tone: Stat::WARN,
                ),

                /*
                 * ⭐ বাজেটের অবস্থা — মানচিত্র §১, ২০ সেপ্টেম্বর ২০২৬।
                 * এ মাসের খরচের বাজেটের কত শতাংশ খরচ হয়েছে; ১০০-র বেশি হলে লাল।
                 * ⓘ বাজেটই না থাকলে কার্ডটা আসে না — "০%" দেখালে মনে হত
                 * কিছুই খরচ হয়নি, অথচ আসলে মাপার কিছু লেখাই হয়নি।
                 */
                ...self::budgetStatus(),
                new Stat(
                    label: __('finance::dashboard.deposits'),
                    value: (string) Deposit::query()->count(),
                    /*
                     * দরজাটা "সব জমা"য় — ৪ সেপ্টেম্বর ২০২৬।
                     *
                     * ⚠️ আগে এখানে কোনো লিংক ছিল না, আর কারণটা ঠিকই
                     * ছিল: `finance.deposit.index` একটা **issuer** চায়,
                     * আর তিনটার একটাকে বেছে নিলে **সংখ্যাটা এক জায়গায়
                     * দেখাত আর ক্লিক করলে অন্য জায়গায় নামত**।
                     *
                     * ⭐ সমাধানটা তাই লিংক যোগ করা নয়, **পাতাটা বানানো**:
                     * `finance.deposit.all` তিন ইস্যুকারীই দেখায়, কোনো
                     * ডিফল্ট ছাঁকনি ছাড়া — তাই সংখ্যাটা হুবহু মেলে।
                     *
                     * ⓘ দরজা না থাকলে মানুষ জানেন কিছু নেই; **ভুল দরজা
                     * থাকলে তাঁরা ভুল সংখ্যাটা বিশ্বাস করেন।**
                     */
                    href: Route::has('finance.deposit.all')
                        ? route('finance.deposit.all')
                        : null,
                    hint: __('finance::dashboard.deposits_hint'),
                ),
                new Stat(
                    label: __('finance::dashboard.contributors'),
                    /*
                     * কতজন টাকা দিয়েছেন — এখন `person_id` গুনে।
                     *
                     * ⛔ আগে ছিল `count('contributor_name')`, অর্থাৎ **আলাদা
                     * বানান গোনা হত, আলাদা মানুষ নয়**। এক মালিক তিন বানানে
                     * লিখলে টালিতে "৩" উঠত, আর মালিক ভাবতেন ব্যবসায় তিনজন
                     * বিনিয়োগকারী আছে। সংখ্যাটা দেখতে নিরীহ, অথচ ভুল।
                     */
                    value: (string) CapitalEntry::query()->distinct()->count('person_id'),
                    hint: __('finance::dashboard.contributors_hint'),
                    href: route('finance.capital.index'),
                ),
            ],

            panels: array_values(array_filter([
                /*
                 * ── এই মাসে টাকা কোন খাতে গেল ───────────────────────
                 *
                 * ⭐ সংখ্যাটা আজ প্রথমবার সত্যিকারের কথা বলে। আগে
                 * পরিবহনের পুরো খরচ **একটা তালে** দেখাত ("জ্বালানি ও
                 * পরিবহন"), তাই *"গাড়ির ভাড়ায় কত গেল"* জিজ্ঞেস করার
                 * উপায়ই ছিল না। আজ খাতটা পাঁচ ভাগে ভাঙা হয়েছে।
                 *
                 * ⓘ ইঞ্জিনটা নতুন নয় — [[HeadTotals]] খরচের পাতায়
                 * আগে থেকেই এটা করত। এখানে কেবল **দরজাটা** বসানো হলো।
                 *
                 * ⚠️ শীর্ষ ছয়টাই, পুরো তালিকা নয়: বিশটা খাতের তালিকা
                 * পড়তে কেউ থামেন না, আর তখন ভাগটা কোনো কাজেই আসত না।
                 */
                /*
                 * ⚠️ খরচ না থাকলে ভাগটাই বসে না — `Breakdown` **খালি
                 * অংশ পেলে ব্যতিক্রম ছোঁড়ে**, আর তাতে গোটা পাতা ৫০০।
                 *
                 * ── কেন এটা প্রায় ফাঁকি দিয়ে বেরিয়ে যাচ্ছিল ─────────
                 * এই মেশিনের ডাটাবেসে চলতি মাসে একটা খরচ বসানো ছিল,
                 * তাই পাতাটা দিব্যি খুলত। **নতুন কোম্পানির প্রথম মাসে
                 * খরচ থাকে না** — সেখানে অর্থের ড্যাশবোর্ড প্রথম দিন
                 * থেকেই ৫০০ দিত, আর কেউ বুঝত না কেন।
                 *
                 * ⓘ ধরেছে [[EveryModuleDashboardHasItsOwnDoorTest]] —
                 * ফেলনা ডাটাবেসে, যেখানে খরচ ছিল না।
                 */
                $expenseHeads === [] ? null : new Breakdown(
                    label: __('finance::dashboard.'.match ($period) {
                        'today' => 'where_money_went_today',
                        'year' => 'where_money_went_year',
                        default => 'where_money_went',
                    }),
                    // ⓘ `forParent()` নিজেই বড় থেকে ছোট সাজিয়ে দেয়, তাই এখানে
                    // আবার সাজানো হয় না — দুইবার সাজালে একদিন দুইটা নিয়ম
                    // আলাদা হয়ে যেত
                    parts: $expenseHeads,
                    hint: __('finance::dashboard.where_money_went_hint'),
                    range: DateRange::label($spentFrom, $spentTo),
                ),

                /*
                 * ⓘ খরচের ভাগ প্রথমে, নগদ প্রবাহ আর ব্যাংক তার পরে (৫ অক্টোবর ২০২৬) — হোম মডিউলের প্রথম চার্টটাই দেখায়
                 * ([[DashboardEngine]]), আর মালিকের হোমের চার্ট "এই মাসে টাকা কোথায় গেল", যেটা হোমের বাছা সময় মানে।
                 */
                ...self::cashFlow($facts),
                ...self::bankWise($facts),
            ])),

            listings: [
                new Listing(
                    label: __('finance::dashboard.recent_capital'),
                    columns: [
                        ['key' => 'no', 'label' => __('finance::dashboard.document'), 'width' => '9rem',
                            'render' => fn ($e) => $e->document_no],
                        ['key' => 'who', 'label' => __('finance::dashboard.contributor'),
                            'render' => fn ($e) => $e->person?->name() ?? '—'],
                        ['key' => 'amount', 'label' => __('finance::dashboard.amount'), 'width' => '9rem',
                            'render' => fn ($e) => Money::format($e->amount)],
                    ],
                    rows: CapitalEntry::query()->with('person')->latest('id')->limit(8)->get(),
                    empty: __('finance::dashboard.no_capital'),
                    href: route('finance.capital.index'),
                ),
            ],
        );
    }

    /**
     * বাজেটের অবস্থার কার্ড — বাজেট না থাকলে ফাঁকা তালিকা।
     *
     * @return list<Stat>
     */
    private static function budgetStatus(): array
    {
        /*
         * ⚠️ টেবিলটা আছে কি না, আগে সেটা — ২০ সেপ্টেম্বর ২০২৬।
         *
         * ⛔ ডিপ্লয়ের মাঝখানে কোড নতুন আর মাইগ্রেশন এখনো চলেনি, এমন একটা
         * মুহূর্ত থাকে। তখন এই একটা কার্ডের জন্য **গোটা ফিন্যান্স
         * ড্যাশবোর্ড** ৫০০ দিত (শেয়ার করা ট্রিতে মেপে দেখা গেছে)।
         * ⓘ একটা কার্ড না দেখানো আর পুরো পাতা না খোলা এক জিনিস নয়।
         */
        if (! Schema::hasTable('fin_budgets')) {
            return [];
        }

        $status = app(BudgetService::class)->monthStatus();

        if ($status === null) {
            return [];
        }

        $over = $status['used_pct'] !== null && bccomp($status['used_pct'], '100', 1) > 0;

        return [...[new Stat(
            label: __('finance::budget.status'),
            value: ($status['used_pct'] ?? '0').'%',
            hint: __('finance::budget.status_hint', [
                'budget' => Money::format($status['budget']),
                'actual' => Money::format($status['actual']),
            ]),
            href: route('finance.budget.actual'),
            tone: $over ? Stat::BAD : Stat::NEUTRAL,
            permission: 'finance.budget.view',
        )], ...self::budgetVariance($status)];
    }

    /**
     * ⭐ বাজেটের পার্থক্য — বাজেট বাদ প্রকৃত, টাকায় (মালিকের ড্যাশবোর্ড নকশা, ৫ অক্টোবর ২০২৬)।
     *
     * ⓘ সংখ্যা দুইটা পাশের অবস্থার কার্ডের একই [[BudgetService::monthStatus()]] থেকে — একবারই পড়া, দুই কার্ডে।
     * ⚠️ চিহ্ন মালিকের নকশা ধরে (বাজেট − প্রকৃত): ধনাত্মক মানে এখনো বাকি, ঋণাত্মক মানে ছাড়িয়ে গেছে।
     * বাজেট-বনাম-প্রকৃত পাতার "ফারাক" কলাম উল্টো দিকে গোনে (প্রকৃত − বাজেট), তাই নাম আলাদা।
     * ⓘ নতুন ড্যাশবোর্ডের অংশ (config abos.dashboards_v2)।
     *
     * @param  array{budget: string, actual: string, used_pct: ?string}  $status
     * @return list<Stat>
     */
    private static function budgetVariance(array $status): array
    {
        if (! config('abos.dashboards_v2')) {
            return [];
        }

        $variance = bcsub($status['budget'], $status['actual'], 4);

        return [new Stat(
            label: __('finance::budget.month_variance'),
            value: Money::format($variance),
            hint: __('finance::budget.month_variance_hint'),
            href: route('finance.budget.actual'),
            tone: bccomp($variance, '0', 4) < 0 ? Stat::BAD : Stat::GOOD,
            permission: 'finance.budget.view',
        )];
    }

    /**
     * ⭐ নগদ প্রবাহ — গত ছয় মাস, এল বনাম গেল (মালিকের ড্যাশবোর্ড নকশা, ৩ অক্টোবর ২০২৬)।
     *
     * ⓘ হিসাব [[AccountsFacts::moneyFlowByMonth()]]-এ, উপরের নগদ/ব্যাংক/MFS ঘরের একই খাত; নিজের মধ্যে স্থানান্তর বাদ।
     * ⛔ ঐ তিন ঘরের একই চাবি (`accounts.view`) — চাবি ছাড়া চার্টই নেই।
     * ⓘ নতুন ড্যাশবোর্ডের অংশ — বাকিগুলোর সাথে একসাথে চালু হবে (config abos.dashboards_v2)।
     *
     * @return list<\App\Core\Engines\Dashboard\Series>
     */
    private static function cashFlow(AccountsFacts $facts): array
    {
        if (! config('abos.dashboards_v2') || ! auth()->user()?->can('accounts.view')) {
            return [];
        }

        return [new \App\Core\Engines\Dashboard\Series(
            label: __('finance::dashboard.cash_flow'),
            points: array_map(fn (array $m) => [
                'label' => $m['month'],
                'first' => $m['in'],
                'second' => $m['out'],
                'firstTitle' => Money::format($m['in']),
                'secondTitle' => Money::format($m['out']),
            ], $facts->moneyFlowByMonth(6)),
            firstLabel: __('finance::dashboard.cash_in'),
            secondLabel: __('finance::dashboard.cash_out'),
            // ⓘ ছয় মাসের প্রথম দিন থেকে আজ — [[AccountsFacts::moneyFlowByMonth()]]-এর একই শুরু (৫ অক্টোবর ২০২৬)
            range: DateRange::label(Carbon::today()->startOfMonth()->subMonths(5), Carbon::today()),
        )];
    }

    /**
     * ⭐ মোট তহবিল, আজকের আদায় ও পরিশোধ, এ মাসের নিট নগদ প্রবাহ, মিলকরণ বাকি, আর সামনের সপ্তাহের ও মেয়াদোত্তীর্ণ দেনা
     * (মালিকের ড্যাশবোর্ড নকশা, ৫ অক্টোবর ২০২৬)।
     *
     * ⓘ একটা সংখ্যারও নতুন সংজ্ঞা নেই: তহবিল = উপরের নগদ + ব্যাংক + MFS ([[AccountsFacts::moneyPositions()]]); আজকের
     * আদায়-পরিশোধ = হিসাবের ড্যাশবোর্ডের একই [[AccountsFacts::today()]]; নিট প্রবাহ = নগদ প্রবাহের চার্টের এ মাসের দণ্ড
     * ([[AccountsFacts::moneyFlowByMonth()]], কেবল এক মাস চেয়ে)।
     * ⛔ নগদ/ব্যাংক/MFS ঘরের একই চাবি (`accounts.view`) — চাবি ছাড়া কিছুই নেই। ⓘ নতুন ড্যাশবোর্ডের অংশ (config
     * abos.dashboards_v2)।
     *
     * @param  array{cash: string, bank: string, mfs: string}  $money
     * @return list<Stat>
     */
    private static function fundAndDues(AccountsFacts $facts, array $money, ?string $cashBook, ?string $supplierAgeing): array
    {
        if (! config('abos.dashboards_v2') || ! auth()->user()?->can('accounts.view')) {
            return [];
        }

        $fund = bcadd(bcadd($money['cash'], $money['bank'], 4), $money['mfs'], 4);
        $today = $facts->today();

        $flow = $facts->moneyFlowByMonth(1);
        $month = $flow[array_key_last($flow)] ?? ['in' => '0', 'out' => '0'];
        $netFlow = bcsub($month['in'], $month['out'], 2);

        /*
         * ⓘ মিলকরণ বাকি = ব্যাংকের বিবরণীর যে সারি এখনো খাতার কোনো সারির সাথে মেলানো হয়নি ([[BankStatementLine::unmatched()]],
         * মিলকরণের পর্দা ঠিক এগুলোই দেখায়)। ⚠️ শাখা ধরে ছাঁকা হয় না: বিবরণীটা কোম্পানির ব্যাংক খাতের, আর বেশিরভাগ সারিতে
         * শাখাই লেখা থাকে না — এক শাখা বাছলে সংখ্যাটা চুপচাপ শূন্য দেখাত, অথচ ব্যাংক যা জানে আমরা জানি না।
         */
        $unmatched = BankStatementLine::query()->unmatched()->count();
        $drafts = BankReconciliation::query()->where('status', BankReconciliation::DRAFT)->count();

        $dues = self::payablesDue();

        return [
            new Stat(
                label: __('finance::dashboard.total_fund'),
                value: Money::format($fund),
                hint: __('finance::dashboard.total_fund_hint'),
                href: $cashBook,
                permission: 'accounts.view',
                tone: Stat::GOOD,
            ),
            new Stat(
                label: __('finance::dashboard.today_collection'),
                value: Money::format($today['collection']),
                hint: __('finance::dashboard.today_collection_hint'),
                href: Route::has('sales.collection.index') ? route('sales.collection.index') : null,
                permission: 'accounts.view',
                tone: Stat::GOOD,
            ),
            new Stat(
                label: __('finance::dashboard.today_payment'),
                value: Money::format($today['payment']),
                hint: __('finance::dashboard.today_payment_hint'),
                href: Route::has('purchase.payment.index') ? route('purchase.payment.index') : null,
                permission: 'accounts.view',
            ),
            new Stat(
                label: __('finance::dashboard.net_cash_flow_month'),
                value: Money::format($netFlow),
                hint: __('finance::dashboard.net_cash_flow_month_hint'),
                href: $cashBook,
                permission: 'accounts.view',
                tone: bccomp($netFlow, '0', 2) < 0 ? Stat::BAD : Stat::GOOD,
            ),
            new Stat(
                label: __('finance::dashboard.reconciliation_pending'),
                value: (string) $unmatched,
                hint: __('finance::dashboard.reconciliation_pending_hint', ['drafts' => $drafts]),
                href: Route::has('accounts.reconciliation.index') ? route('accounts.reconciliation.index') : null,
                permission: 'accounts.view',
                tone: $unmatched > 0 ? Stat::WARN : Stat::NEUTRAL,
            ),
            new Stat(
                label: __('finance::dashboard.payables_next_week'),
                value: Money::format($dues['soon']),
                hint: __('finance::dashboard.payables_next_week_hint', ['count' => $dues['soon_count']]),
                href: $supplierAgeing,
                permission: 'accounts.view',
                tone: $dues['soon_count'] > 0 ? Stat::WARN : Stat::NEUTRAL,
            ),
            new Stat(
                label: __('finance::dashboard.payables_overdue'),
                value: Money::format($dues['overdue']),
                hint: __('finance::dashboard.payables_overdue_hint', ['count' => $dues['overdue_count']]),
                href: $supplierAgeing,
                permission: 'accounts.view',
                tone: $dues['overdue_count'] > 0 ? Stat::BAD : Stat::NEUTRAL,
            ),
        ];
    }

    /**
     * ⭐ সরবরাহকারীর বিল — সামনের ৭ দিনে যার মেয়াদ, আর যার মেয়াদ পেরিয়ে গেছে; কেবল যেখানে টাকা বাকি (৫ অক্টোবর ২০২৬)।
     *
     * ── ⚠️ কেন `DB::table`, মডেল নয় ──────────────────────────────────────
     * অর্থ ক্রয়ের উপর দাঁড়ায় (`module.php`-এর `depends_on`-এ purchase আছে), কিন্তু মালিকের ড্যাশবোর্ড-নকশার নিয়ম:
     * ড্যাশবোর্ড ক্রয়ের ক্লাস import করে না, আর বিলের বাকির জন্য কোরে কোনো চুক্তি নেই। তাই টেবিল থেকে সরাসরি,
     * প্রতিটা কোয়েরিতে কোম্পানির নাম হাতে (`company_id`) — `DB::table` গ্লোবাল স্কোপ মানে না।
     *
     * ── ⛔ বাকির সংজ্ঞা দ্বিতীয়বার লেখা নয়, হুবহু নকল ──────────────────────
     * বাকি = বিলের মোট − পোস্ট হওয়া পরিশোধের সারি − বিলের বিপরীতে পোস্ট হওয়া পরিশোধ ভাউচার, অর্থাৎ
     * [[PurchaseBill::scopeWithPaid()]] আর [[PurchaseBill::dueAmount()]]-এর একই তিন ভাগ, [[CashForecast]] যেভাবে পড়ে।
     * ⚠️ ওখানে শর্ত বদলালে এখানেও বদলাতে হবে — দাবিটা ([[TheFinanceDashboardShowsTheWholeSpecTest]]) পরিশোধ বসিয়ে মাপে।
     * ⓘ মেয়াদ-না-লেখা বিল কোনো ঘরেই নয় — কবে দিতে হবে তা জানা নেই। দেখার শাখা মানে, বিলের তালিকার মতো।
     *
     * @return array{soon: string, soon_count: int, overdue: string, overdue_count: int}
     */
    private static function payablesDue(): array
    {
        $company = CompanyContext::id();
        $today = Carbon::today()->toDateString();
        $weekEnd = Carbon::today()->addDays(7)->toDateString();

        $paidByLines = DB::table('pur_payment_lines')
            ->join('pur_payments', 'pur_payments.id', '=', 'pur_payment_lines.payment_id')
            ->whereColumn('pur_payment_lines.purchase_bill_id', 'pur_bills.id')
            ->whereColumn('pur_payments.company_id', 'pur_bills.company_id')
            ->whereIn('pur_payments.status', DocumentStatus::POSTED)
            ->whereNull('pur_payments.deleted_at')
            ->selectRaw('COALESCE(SUM(pur_payment_lines.amount), 0)');

        $paidByVouchers = DB::table('vouchers')
            ->whereColumn('vouchers.company_id', 'pur_bills.company_id')
            ->where('vouchers.type', Voucher::PAYMENT)
            // ⓘ বিলের উৎস-নাম ([[PurchaseBill::drillSourceType()]]) — ক্লাসটা import না করে, শব্দটাই
            ->where('vouchers.against_type', 'purchase_bill')
            ->whereColumn('vouchers.against_id', 'pur_bills.id')
            ->where('vouchers.status', DocumentStatus::CONFIRMED)
            ->whereNull('vouchers.deleted_at')
            ->selectRaw('COALESCE(SUM(vouchers.amount), 0)');

        $bills = app(DataScope::class)->inView(DB::table('pur_bills')
            ->where('pur_bills.company_id', $company)
            ->whereIn('pur_bills.status', DocumentStatus::POSTED)
            ->whereNull('pur_bills.deleted_at')
            ->whereNotNull('pur_bills.due_on')
            ->where('pur_bills.due_on', '<=', $weekEnd), 'pur_bills.branch_id')
            ->select(['pur_bills.due_on', 'pur_bills.total'])
            ->selectSub($paidByLines, 'paid_lines')
            ->selectSub($paidByVouchers, 'paid_vouchers');

        $left = 'b.total - b.paid_lines - b.paid_vouchers';

        $row = DB::query()->fromSub($bills, 'b')
            ->whereRaw("{$left} > 0")
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN b.due_on < ? THEN {$left} ELSE 0 END), 0) as overdue,"
                ."COALESCE(SUM(CASE WHEN b.due_on < ? THEN 1 ELSE 0 END), 0) as overdue_count,"
                ."COALESCE(SUM(CASE WHEN b.due_on >= ? THEN {$left} ELSE 0 END), 0) as soon,"
                ."COALESCE(SUM(CASE WHEN b.due_on >= ? THEN 1 ELSE 0 END), 0) as soon_count",
                [$today, $today, $today, $today],
            )
            ->first();

        return [
            'soon' => bcadd((string) ($row->soon ?? '0'), '0', 4),
            'soon_count' => (int) ($row->soon_count ?? 0),
            'overdue' => bcadd((string) ($row->overdue ?? '0'), '0', 4),
            'overdue_count' => (int) ($row->overdue_count ?? 0),
        ];
    }

    /**
     * ⭐ ব্যাংক অনুযায়ী জের — প্রতিটা ব্যাংক খাতে আজ কত, আড়াআড়ি দণ্ডে (মালিকের ড্যাশবোর্ড নকশা, ৫ অক্টোবর ২০২৬)।
     *
     * ⓘ হিসাব [[AccountsFacts::bankBalances()]]-এ — ব্যাংক-চিহ্নিত পাতা-খাত (⛔ MFS নয়), প্রতিটার নিজের জের, হেডারে বাছা
     * শাখায়। অর্থের পাতা নিজে খাতা পড়ে না।
     * ⓘ ব্যাংক খাতই না থাকলে ভাগটা বসে না — `Breakdown` খালি অংশ পেলে ব্যতিক্রম ছোঁড়ে।
     * ⓘ নতুন ড্যাশবোর্ডের অংশ (config abos.dashboards_v2), কেবল accounts.view-এ।
     *
     * @return list<Breakdown>
     */
    private static function bankWise(AccountsFacts $facts): array
    {
        if (! config('abos.dashboards_v2') || ! auth()->user()?->can('accounts.view')) {
            return [];
        }

        $banks = $facts->bankBalances();

        if ($banks === []) {
            return [];
        }

        return [new Breakdown(
            label: __('finance::dashboard.bank_wise'),
            parts: array_map(fn (array $bank) => [
                'label' => $bank['name'],
                'value' => Money::format($bank['balance']),
            ], $banks),
            hint: __('finance::dashboard.bank_wise_hint'),
            chart: 'hbars',
        )];
    }
}
