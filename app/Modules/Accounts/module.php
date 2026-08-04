<?php

declare(strict_types=1);
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\CashTill;
use App\Modules\Accounts\Reports\CoreReports;

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
            ['label' => 'accounts::menu.dashboard', 'route' => 'accounts.dashboard', 'permission' => 'accounts.view', 'planned' => true],
        ],
        'master' => [
            ['label' => 'accounts::menu.chart_of_accounts', 'route' => 'accounts.coa.index', 'permission' => 'accounts.coa.view'],
            ['label' => 'accounts::menu.cash_tills', 'route' => 'accounts.till.index', 'permission' => 'accounts.till.view'],
        ],
        'transactions' => [
            ['label' => 'accounts::menu.receipt', 'route' => 'accounts.voucher.receipt', 'permission' => 'accounts.voucher.create', 'planned' => true],
            ['label' => 'accounts::menu.payment', 'route' => 'accounts.voucher.payment', 'permission' => 'accounts.voucher.create', 'planned' => true],
            ['label' => 'accounts::menu.expense', 'route' => 'accounts.voucher.expense', 'permission' => 'accounts.voucher.create', 'planned' => true],
            ['label' => 'accounts::menu.journal', 'route' => 'accounts.voucher.journal', 'permission' => 'accounts.voucher.create', 'planned' => true],
            ['label' => 'accounts::menu.contra', 'route' => 'accounts.voucher.contra', 'permission' => 'accounts.voucher.create', 'planned' => true],
            ['label' => 'accounts::menu.money_transfer', 'route' => 'accounts.transfer.index', 'permission' => 'accounts.transfer.create', 'planned' => true],
            ['label' => 'accounts::menu.cash_count', 'route' => 'accounts.count.index', 'permission' => 'accounts.count.create', 'planned' => true],
        ],
        'reports' => [
            ['label' => 'accounts::menu.day_book', 'route' => 'accounts.report.daybook', 'permission' => 'accounts.report', 'planned' => true],
            ['label' => 'accounts::menu.cash_book', 'route' => 'accounts.report.cashbook', 'permission' => 'accounts.report', 'planned' => true],
            ['label' => 'accounts::menu.bank_book', 'route' => 'accounts.report.bankbook', 'permission' => 'accounts.report', 'planned' => true],
            ['label' => 'accounts::menu.ledger', 'route' => 'accounts.report.ledger', 'permission' => 'accounts.report', 'planned' => true],
            ['label' => 'accounts::menu.trial_balance', 'route' => 'accounts.report.trial', 'permission' => 'accounts.report', 'planned' => true],
            ['label' => 'accounts::menu.profit_loss', 'route' => 'accounts.report.pl', 'permission' => 'accounts.report.final', 'planned' => true],
            ['label' => 'accounts::menu.balance_sheet', 'route' => 'accounts.report.bs', 'permission' => 'accounts.report.final', 'planned' => true],
            ['label' => 'accounts::menu.cash_flow', 'route' => 'accounts.report.cashflow', 'permission' => 'accounts.report.final', 'planned' => true],
        ],
        'settings' => [
            ['label' => 'accounts::menu.settings', 'route' => 'accounts.settings', 'permission' => 'accounts.manage', 'planned' => true],
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
    ],

    'drill_sources' => [
        'account' => Account::class,
        'cash_till' => CashTill::class,
    ],

    // রিপোর্ট সরবরাহকারী — কোর নিজে থেকে ডেকে নেবে (সেকশন ১৯.৩)।
    // কোর ফাইলে মডিউলের নাম লিখতে হয় না।
    'reports' => [
        CoreReports::class,
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
