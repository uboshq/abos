<?php

declare(strict_types=1);

namespace App\Modules\Finance\Dashboard;

use App\Core\Contracts\ProvidesDashboard;
use App\Core\Engines\Dashboard\DashboardDefinition;
use App\Core\Engines\Dashboard\Breakdown;
use App\Core\Engines\Dashboard\Listing;
use App\Core\Engines\Dashboard\Stat;
use App\Core\Engines\Dashboard\Tile;
use App\Core\Support\Money;
use Illuminate\Support\Facades\Route;
use App\Modules\Accounts\Services\AccountsFacts;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\CapitalEntry;
use App\Modules\Finance\Models\Deposit;
use App\Modules\Finance\Models\Withdrawal;
use App\Modules\Finance\Services\HeadTotals;

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
        $expenseHeads = array_map(
            fn (array $row) => [
                'label' => $row['label'],
                'value' => Money::format($row['amount']),
            ],
            app(HeadTotals::class)->topUnder(
                StandardChart::OPERATING_EXPENSES,
                now()->startOfMonth()->toDateString(),
                now()->toDateString(),
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

                new Tile(label: __('finance::dashboard.expense_heads'),
                    href: route('finance.expense.index'),
                    permission: 'finance.expense.view', icon: 'reports'),

                /*
                 * ⚠️ পণ্যের খরচ **অনুমতির পিছনে** — `inventory.cost.view`।
                 * এই একই তালা আজ সকালে পণ্যের তালিকা ও চলাচলের পর্দায়
                 * বসানো হয়েছে; দরজাটা খোলা রাখলে ওই দুইটার মানেই থাকত না।
                 */
                Route::has('inventory.stock.movement')
                    ? new Tile(label: __('finance::dashboard.product_costing'),
                        href: route('inventory.stock.movement', ['type' => 'slow']),
                        permission: 'inventory.cost.view', icon: 'box')
                    : null,

                Route::has('approval.flow.index')
                    ? new Tile(label: __('finance::dashboard.who_approves_what'),
                        href: route('approval.flow.index'),
                        permission: 'approval.flow.manage', icon: 'shield')
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
                    value: Money::format(CapitalEntry::query()->where('entry_type', 'in')->sum('amount')),
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
                    value: (string) CapitalEntry::query()->distinct()->count('contributor_name'),
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
                    label: __('finance::dashboard.where_money_went'),
                    // ⓘ `forParent()` নিজেই বড় থেকে ছোট সাজিয়ে দেয়, তাই এখানে
                    // আবার সাজানো হয় না — দুইবার সাজালে একদিন দুইটা নিয়ম
                    // আলাদা হয়ে যেত
                    parts: $expenseHeads,
                    hint: __('finance::dashboard.where_money_went_hint'),
                ),
            ])),

            listings: [
                new Listing(
                    label: __('finance::dashboard.recent_capital'),
                    columns: [
                        ['key' => 'no', 'label' => __('finance::dashboard.document'), 'width' => '9rem',
                            'render' => fn ($e) => $e->document_no],
                        ['key' => 'who', 'label' => __('finance::dashboard.contributor'),
                            'render' => fn ($e) => $e->contributor_name],
                        ['key' => 'amount', 'label' => __('finance::dashboard.amount'), 'width' => '9rem',
                            'render' => fn ($e) => Money::format($e->amount)],
                    ],
                    rows: CapitalEntry::query()->latest('id')->limit(8)->get(),
                    empty: __('finance::dashboard.no_capital'),
                    href: route('finance.capital.index'),
                ),
            ],
        );
    }
}
