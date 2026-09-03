<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Dashboard;

use App\Core\Contracts\ProvidesDashboard;
use App\Core\Engines\Dashboard\Breakdown;
use App\Core\Engines\Dashboard\DashboardDefinition;
use App\Core\Engines\Dashboard\Listing;
use App\Core\Engines\Dashboard\Stat;
use App\Core\Engines\Dashboard\Tile;
use App\Core\Support\Money;
use Illuminate\Support\Facades\Route;
use App\Modules\Accounts\Models\MoneyTransfer;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\AccountsFacts;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Supplier\Models\Supplier;

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

        /*
         * সবচেয়ে বেশি বকেয়া যে দশজনের — নাম সহ।
         *
         * ⚠️ খতিয়ান নাম রাখে না, কেবল `party_type` ও `party_id`। তাই
         * নামগুলো **একবারেই** আনা হয়, প্রতি সারিতে নয় — দশ সারিতে দশটা
         * কোয়েরি হলে ঠিক সেই ধীরতা জন্মাত যেটা এড়ানোর জন্য
         * [[AccountsFacts::today()]] একটাই কোয়েরিতে তিনটা সংখ্যা দেয়।
         */
        $topDue = $facts->topDue('customer', StandardChart::RECEIVABLE);
        $names = Customer::query()
            ->whereIn('id', array_column($topDue, 'party_id'))
            ->get()
            ->keyBy('id');

        $dueRows = collect($topDue)
            ->map(fn (array $row) => (object) [
                'id' => $row['party_id'],
                'name' => $names[$row['party_id']]?->name() ?? '—',
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
        $supplierNames = Supplier::query()
            ->whereIn('id', array_column($topOwed, 'party_id'))
            ->get()
            ->keyBy('id');

        $owedRows = collect($topOwed)
            ->map(fn (array $row) => (object) [
                'id' => $row['party_id'],
                'name' => $supplierNames[$row['party_id']]?->name() ?? '—',
                'amount' => $row['amount'],
            ])
            ->values();

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
                    href: route('finance.expense.index', [
                        'from' => now()->toDateString(),
                        'to' => now()->toDateString(),
                    ]),
                    permission: 'finance.expense.view',
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
            ],

            panels: [
                /*
                 * ── কেন আয় ও ব্যয় একটা ভাগে, দুইটা সংখ্যায় নয় ────────
                 * দুইটা আলাদা কার্ডে দেখালে চোখ দুইটা বড় সংখ্যা পড়ত আর
                 * **পার্থক্যটা নিজে বিয়োগ করতে হত**। পাশাপাশি রাখলে
                 * মাসটা লাভে না লোকসানে, সেটা তাকানোর সাথে সাথেই বোঝা যায়।
                 */
                new Breakdown(
                    label: __('accounts::dashboard.month_so_far'),
                    parts: [
                        ['label' => __('accounts::dashboard.income'),
                            'value' => Money::format($facts->incomeThisMonth())],
                        ['label' => __('accounts::dashboard.expense'),
                            'value' => Money::format($facts->expenseThisMonth())],
                    ],
                    hint: __('accounts::dashboard.month_so_far_hint'),
                ),
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
                        ['key' => 'no', 'label' => __('accounts::dashboard.document'), 'width' => '11rem',
                            'render' => fn ($v) => $v->document_no],
                        ['key' => 'type', 'label' => __('accounts::dashboard.type'), 'width' => '8rem',
                            'render' => fn ($v) => $v->type],
                        ['key' => 'amount', 'label' => __('accounts::dashboard.amount'), 'width' => '10rem',
                            'render' => fn ($v) => Money::format($v->amount)],
                    ],
                    rows: Voucher::query()->draft()->latest('id')->limit(8)->get(),
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
        );
    }
}
