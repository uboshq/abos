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
use App\Modules\Accounts\Models\MoneyTransfer;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\AccountsFacts;

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
