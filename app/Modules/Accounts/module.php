<?php

declare(strict_types=1);
use App\Modules\Accounts\Dashboard\AccountsWidgets;
use App\Modules\Accounts\Integrity\AccountsChecks;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\CashCount;
use App\Modules\Accounts\Models\CashTill;
use App\Modules\Accounts\Models\Loan;
use App\Modules\Accounts\Models\LoanInstalment;
use App\Modules\Accounts\Models\LoanMovement;
use App\Modules\Accounts\Models\MoneyTransfer;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Reports\CoreReports;
use App\Modules\Accounts\Services\StandardChart;

/**
 * Accounts & Finance — প্ল্যান সেকশন ১৯.২।
 *
 * সবচেয়ে ভারী মডিউল (Phase 4, ১৩–১৫ দিন) এবং সবার নিচের স্তর: বিক্রয়, ক্রয়,
 * বেতন — সবকিছুর হিসাব শেষমেশ এখানকার ledger_entries-এ এসে বসে। তাই এর
 * depends_on ফাঁকা; বাকি সবাই এর উপর দাঁড়ায়।
 */

return [
    'code' => 'accounts',

    'name' => [
        'en' => 'Accounts & Finance',
        'bn' => 'হিসাব ও অর্থ',
    ],

    'version' => '1.0.0',

    'depends_on' => [],

    'menu' => [
        'dashboard' => [
            ['label' => 'accounts::menu.dashboard', 'route' => 'accounts.dashboard', 'permission' => 'accounts.view'],
        ],
        'master' => [
            ['label' => 'accounts::menu.chart_of_accounts', 'route' => 'accounts.coa.index', 'permission' => 'accounts.coa.view'],
            ['label' => 'accounts::menu.cash_tills', 'route' => 'accounts.till.index', 'permission' => 'accounts.till.view'],

            /*
             * ঋণ মাস্টারে, লেনদেনে নয়।
             *
             * একটা ঋণ একটা চুক্তি — বছরে একবার বসে, তারপর বছরের পর বছর
             * থাকে। রোজকার লেনদেন তার কিস্তিগুলো, আর সেগুলো ঋণের নিজের
             * পাতা থেকেই দেওয়া হয়।
             */
            ['label' => 'accounts::menu.loans', 'route' => 'accounts.loan.index', 'permission' => 'accounts.loan.view'],
        ],
        /*
         * পাঁচটা সারিই `accounts.report` চায়, `accounts.voucher.create` নয়।
         *
         * ── কী ভুল ছিল ──────────────────────────────────────────────
         * সারিগুলো চাইত `accounts.voucher.create`, অথচ রুটটা প্রয়োগ করে
         * `accounts.report` (VoucherController::middleware, index ও show)।
         * ফলে যে ক্যাশিয়ারের ভাউচার লেখার চাবি আছে কিন্তু রিপোর্টের নেই,
         * তিনি মেনুতে পাঁচটা সারি দেখতেন আর ক্লিক করলেই ৪০৩ পেতেন —
         * নিজের রোজকার কাজের পর্দায়।
         *
         * ── কেন মেনু বদলালো, রুট নয় ─────────────────────────────────
         * দুই দিকের যেকোনোটা বদলালেই কারও না কারও প্রবেশাধিকার বদলায়।
         * রুট বদলালে যাঁদের কেবল `accounts.report` আছে (হিসাবরক্ষক, যিনি
         * দেখেন কিন্তু লেখেন না) তাঁরা ভাউচারের তালিকা হারাতেন — আর সেটা
         * একটা ব্যবসায়িক সিদ্ধান্ত, বাগ সারানো নয়।
         *
         * মেনু বদলালে কেউ কিছু হারায় না: যিনি আগে পাতাটা খুলতে পারতেন
         * তিনি এখনো পারেন, শুধু যে সারিটা কোনোদিন কাজ করত না সেটা আর
         * দেখা যায় না।
         *
         * আলাদা `accounts.voucher.view` চাবি বসানোই সবচেয়ে পরিষ্কার হত,
         * কিন্তু তাতে পুরনো রোলগুলোয় নতুন চাবি বিলি করতে হত — সেটা
         * আলাদা কাজ, আর মালিকের সিদ্ধান্ত।
         */
        'transactions' => [
            ['label' => 'accounts::menu.receipt', 'route' => 'accounts.voucher.index', 'route_params' => ['type' => 'receipt'], 'permission' => 'accounts.report'],
            ['label' => 'accounts::menu.payment', 'route' => 'accounts.voucher.index', 'route_params' => ['type' => 'payment'], 'permission' => 'accounts.report'],
            ['label' => 'accounts::menu.expense', 'route' => 'accounts.voucher.index', 'route_params' => ['type' => 'expense'], 'permission' => 'accounts.report'],
            ['label' => 'accounts::menu.journal', 'route' => 'accounts.voucher.index', 'route_params' => ['type' => 'journal'], 'permission' => 'accounts.report'],
            ['label' => 'accounts::menu.contra', 'route' => 'accounts.voucher.index', 'route_params' => ['type' => 'contra'], 'permission' => 'accounts.report'],
            ['label' => 'accounts::menu.money_custody', 'route' => 'accounts.custody', 'permission' => 'accounts.till.view'],
            ['label' => 'accounts::menu.money_transfer', 'route' => 'accounts.transfer.index', 'permission' => 'accounts.transfer.create'],
            ['label' => 'accounts::menu.cash_count', 'route' => 'accounts.count.index', 'permission' => 'accounts.count.create'],
        ],
        'reports' => [
            ['label' => 'accounts::menu.day_book', 'route' => 'accounts.report.show', 'route_params' => ['slug' => 'day-book'], 'permission' => 'accounts.report'],
            ['label' => 'accounts::menu.cash_book', 'route' => 'accounts.report.show', 'route_params' => ['slug' => 'cash-book'], 'permission' => 'accounts.report'],
            ['label' => 'accounts::menu.bank_book', 'route' => 'accounts.report.show', 'route_params' => ['slug' => 'bank-book'], 'permission' => 'accounts.report'],

            // "আজ কত টাকা ঢুকল" — নগদ বই বলে কোন ড্রয়ারে, এটা বলে কোন কাগজে
            ['label' => 'accounts::menu.inflow', 'route' => 'accounts.report.show', 'route_params' => ['slug' => 'inflow'], 'permission' => 'accounts.report'],
            ['label' => 'accounts::menu.ledger', 'route' => 'accounts.report.show', 'route_params' => ['slug' => 'ledger'], 'permission' => 'accounts.report'],
            ['label' => 'accounts::menu.trial_balance', 'route' => 'accounts.report.show', 'route_params' => ['slug' => 'trial-balance'], 'permission' => 'accounts.report'],

            /*
             * খাতা নিজেই মেলে কি না — রেওয়ামিলের ঠিক নিচে।
             *
             * ক্রমটা ইচ্ছাকৃত: রেওয়ামিল দেখে সন্দেহ হলে পরের সারিটাই
             * বলে দেয় কোথায় ভেঙেছে। উপরে বসালে রোজকার রিপোর্টগুলোর
             * আগে একটা পর্দা পড়ত যেটা বছরে কয়েকবার লাগে।
             */
            ['label' => 'accounts::menu.books_check', 'route' => 'accounts.integrity', 'permission' => 'accounts.report'],
            ['label' => 'accounts::menu.profit_loss', 'route' => 'accounts.report.show', 'route_params' => ['slug' => 'profit-loss'], 'permission' => 'accounts.report.final'],
            ['label' => 'accounts::menu.balance_sheet', 'route' => 'accounts.report.show', 'route_params' => ['slug' => 'balance-sheet'], 'permission' => 'accounts.report.final'],
            ['label' => 'accounts::menu.cash_flow', 'route' => 'accounts.report.show', 'route_params' => ['slug' => 'cash-flow'], 'permission' => 'accounts.report.final'],
        ],
        'settings' => [
            // বছর সমাপনী রোজকার কাজ নয়, তাই সেটিংসের সাথে — আর অনুমতিও
            // চূড়ান্ত হিসাবের, কারণ এটা বছরের ফল চূড়ান্ত করে দেয়
            ['label' => 'accounts::menu.year_end', 'route' => 'accounts.year_end.index', 'permission' => 'accounts.report.final'],
            ['label' => 'accounts::menu.settings', 'route' => 'accounts.settings', 'permission' => 'accounts.manage'],
        ],
    ],

    'permissions' => [
        'accounts.view',
        'accounts.manage',
        'accounts.coa.view',
        'accounts.coa.manage',
        'accounts.voucher.create',
        'accounts.voucher.update',
        'accounts.voucher.delete',
        'accounts.voucher.approve',
        'accounts.voucher.backdate',
        'accounts.report',
        'accounts.report.final',
        'accounts.till.view',

        /*
         * ঋণের নিজের চাবি।
         *
         * একটা ঋণ বসানো মানে দায় জন্মানো আর টাকা ঢোকা — দুইটাই
         * প্রতিষ্ঠানের সবচেয়ে বড় সিদ্ধান্তের একটা। যিনি রোজ ভাউচার
         * লেখেন তাঁর হাতে এটা থাকা উচিত নয়।
         */
        'accounts.loan.view',
        'accounts.loan.manage',
        'accounts.till.manage',
        'accounts.transfer.create',
        'accounts.transfer.confirm',
        'accounts.count.create',
        'accounts.count.approve',
    ],

    'doc_types' => [
        'RV' => 'accounts::doc.receipt_voucher',
        'PV' => 'accounts::doc.payment_voucher',
        'EV' => 'accounts::doc.expense_voucher',
        'JV' => 'accounts::doc.journal_voucher',
        'CV' => 'accounts::doc.contra_voucher',
        'MT' => 'accounts::doc.money_transfer',
        'CC' => 'accounts::doc.cash_count',

        /*
         * ক্যাশ টিলের কোড — মালিকের নির্দেশ (২০২৬-০৭ তারিখ ৭): কোড অটো।
         *
         * টিল একটা জায়গা, একটা ড্রয়ার — তার কোডে কোনো অর্থ নেই, শুধু
         * পরিচয়। খাতের কোড (১১০১ = হাতে নগদ) এই তালিকায় নেই: ওখানে
         * সংখ্যাটাই হিসাবের কাঠামো।
         */
        'TIL' => 'accounts::doc.till_code',

        // ঋণ — LN-2026-2027-0001
        'LN' => 'accounts::doc.loan',
    ],

    /*
     * Drill-down মানচিত্র — নিয়ম ১।
     *
     * পাঁচটা ভাউচারের ধরন আলাদা করে লেখা, একটা 'voucher' নয়: লেজারে
     * প্রতিটা ধরন নিজের নামে বসে (receipt_voucher, contra_voucher…),
     * আর রিপোর্টে ওই নামটাই দেখা যায়। একটা সাধারণ নাম দিলে ডে বুকে
     * প্রতিটা সারি শুধু "ভাউচার" বলত, কোন ধরনের তা নয়।
     *
     * বিপরীত এন্ট্রিগুলো "receipt_voucher:reversal" নামে বসে; সেগুলো
     * এখানে লেখার দরকার নেই, DrillResolver উপসর্গটা ছেঁটে নেয়।
     */
    'drill_sources' => [
        'account' => Account::class,
        'cash_till' => CashTill::class,
        'receipt_voucher' => Voucher::class,
        'payment_voucher' => Voucher::class,
        'expense_voucher' => Voucher::class,
        'journal_voucher' => Voucher::class,
        'contra_voucher' => Voucher::class,
        'money_transfer' => MoneyTransfer::class,
        'cash_count' => CashCount::class,

        /*
         * ঋণ নিজে খতিয়ানে বসে না — তার নড়াচড়া আর কিস্তিগুলো বসে।
         *
         * ঋণটা তবু এখানে আছে, কারণ রিপোর্টে "কোন ঋণ" বলতে ওটাকেই
         * দেখাতে হয়; কিন্তু ledger_entries.source_type-এ কখনো 'loan'
         * বসে না। কারণটা LoanMovement-এ লেখা।
         */
        'loan' => Loan::class,
        'loan_movement' => LoanMovement::class,
        'loan_instalment' => LoanInstalment::class,
    ],

    /*
     * নতুন কোম্পানি হলে প্রমিত হিসাব-ছকটা এখান থেকে বসে।
     *
     * আগে CompanyProvisioner নিজেই StandardChart-এর নাম জানত, অর্থাৎ
     * কোর একটা মডিউলের ভেতরের সার্ভিস চিনত (§১৯.৭)। এখন ঘোষণাটা
     * মডিউলের নিজের, আর ক্রমটা depends_on থেকেই আসে — accounts কারও
     * উপর নির্ভর করে না, তাই সে সবার আগে চলে।
     */
    'provisions' => [
        StandardChart::class,
    ],

    // রিপোর্ট সরবরাহকারী — কোর নিজে থেকে ডেকে নেবে (সেকশন ১৯.৩)।
    // কোর ফাইলে মডিউলের নাম লিখতে হয় না।
    'reports' => [
        CoreReports::class,
    ],

    // হোম পর্দার টাকার সংখ্যাগুলো
    'widgets' => [
        AccountsWidgets::class,
    ],

    /*
     * খাতা নিজেই মেলে কি না — চালিয়ে দেখার যাচাই।
     *
     * পরীক্ষা বলে কোডটা ঠিক; এটা বলে **এই কোম্পানির আজকের খাতাটা**
     * ঠিক। হাতে চালানো SQL, আধেক লেখা ট্রানজেকশন, বা সারানোর আগেই
     * কিছু সারি লিখে ফেলা একটা বাগ — কোড সারালে পুরনো সারিগুলো নিজে
     * থেকে ঠিক হয় না।
     */
    'integrity' => [
        AccountsChecks::class,
    ],

    'settings' => [
        [
            'key' => 'accounts.backdate_days',
            'label' => 'accounts::settings.backdate_days',
            'type' => 'integer',
            'default' => 7,
            'group' => 'entry',
        ],
        [
            'key' => 'accounts.cash_ceiling_enabled',
            'label' => 'accounts::settings.cash_ceiling_enabled',
            'type' => 'boolean',
            'default' => true,
            'group' => 'entry',
        ],
        [
            'key' => 'accounts.cash_ceiling_blocks',
            'label' => 'accounts::settings.cash_ceiling_blocks',
            'type' => 'boolean',
            'default' => false,
            'group' => 'entry',
        ],
        [
            'key' => 'accounts.require_narration',
            'label' => 'accounts::settings.require_narration',
            'type' => 'boolean',
            'default' => true,
            'group' => 'entry',
        ],
        [
            'key' => 'accounts.print_signature_lines',
            'label' => 'accounts::settings.print_signature_lines',
            'type' => 'boolean',
            'default' => true,
            'group' => 'print',
        ],
    ],
];
