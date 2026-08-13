<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Dashboard;

use App\Core\Contracts\DashboardWidgets;
use App\Core\Dashboard\Widget;
use App\Core\Support\Money;
use App\Models\LedgerEntry;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\CashTill;
use App\Modules\Accounts\Models\MoneyTransfer;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;

/**
 * টাকার সংখ্যাগুলো হোম পর্দায়।
 *
 * ── কেন এগুলো খতিয়ান থেকে ───────────────────────────────────────────
 * বকেয়া মানে "গ্রাহকদের কাছে কত পাওনা" — সেটা বিলের যোগফল নয়, বিল
 * বিয়োগ আদায় বিয়োগ ফেরত বিয়োগ ছাড়। ওই হিসাবটা খতিয়ানেই আছে, আর
 * সেখান থেকে না নিলে একদিন দুইটা সংখ্যা তৈরি হত — একটা হোম পর্দায়,
 * একটা রেওয়ামিলে।
 */
final class AccountsWidgets implements DashboardWidgets
{
    /** @return list<Widget> */
    public static function widgets(): array
    {
        return [
            new Widget(
                group: 'today',
                label: __('accounts::dashboard.cash_in_hand'),
                value: Money::format(self::cashInHand()),
                href: route('accounts.till.index'),
                permission: 'accounts.till.view',
                tone: 'money',
                sort: 30,
            ),

            new Widget(
                group: 'today',
                label: __('accounts::dashboard.bank_balance'),
                value: Money::format(self::bankBalance()),
                href: route('accounts.dashboard'),
                permission: 'accounts.view',
                tone: 'money',
                sort: 40,
            ),

            new Widget(
                group: 'month',
                label: __('core.accounting.receivable'),
                value: Money::format(self::balanceOf(StandardChart::RECEIVABLE)),
                href: route('accounts.coa.index'),
                permission: 'accounts.view',
                tone: 'money',
                sort: 30,
            ),

            new Widget(
                group: 'month',
                label: __('core.accounting.payable'),
                value: Money::format(self::balanceOf(StandardChart::PAYABLE)),
                href: route('accounts.coa.index'),
                permission: 'accounts.view',
                tone: 'money',
                sort: 40,
            ),

            /*
             * খসড়া ভাউচার কোনো হিসাবে নেই।
             *
             * লেখা হয়েছে, পোস্ট হয়নি — অর্থাৎ টাকাটা খাতায় ওঠেনি। মাস
             * শেষে রেওয়ামিল না মেলার সবচেয়ে সাধারণ কারণ এটাই।
             */
            new Widget(
                group: 'todo',
                label: __('accounts::dashboard.draft_vouchers'),
                value: (string) Voucher::query()->draft()->count(),
                href: route('accounts.dashboard'),
                permission: 'accounts.view',
                tone: 'warn',
                sort: 40,
            ),

            /*
             * অপেক্ষমাণ হস্তান্তর — টাকা এখনো দাতার হাতে।
             *
             * যতক্ষণ গ্রহীতা নিশ্চিত করেননি ততক্ষণ দায়টা যিনি দিয়েছেন
             * তাঁরই। ভুলে গেলে দুইজনের কেউই জানে না টাকাটা কার কাছে।
             */
            new Widget(
                group: 'todo',
                label: __('accounts::dashboard.pending_transfers'),
                value: (string) MoneyTransfer::query()->pending()->count(),
                href: route('accounts.transfer.index'),
                permission: 'accounts.transfer.create',
                tone: 'warn',
                sort: 50,
            ),
        ];
    }

    private static function cashInHand(): string
    {
        return self::sumOf(CashTill::query()->active()->pluck('account_id')->all());
    }

    private static function bankBalance(): string
    {
        return self::sumOf(Account::query()->where('is_bank', true)->postable()->pluck('id')->all());
    }

    private static function balanceOf(string $code): string
    {
        return StandardChart::find($code)?->balanceOn() ?? '0';
    }

    /**
     * কয়েকটা খাতের মোট ব্যালেন্স — এক কোয়েরিতে, প্রারম্ভিক সহ।
     *
     * @param  list<int>  $accountIds
     */
    private static function sumOf(array $accountIds): string
    {
        if ($accountIds === []) {
            return '0';
        }

        $row = LedgerEntry::query()
            ->whereIn('account_id', $accountIds)
            ->selectRaw('COALESCE(SUM(debit), 0) as d, COALESCE(SUM(credit), 0) as c')
            ->first();

        $opening = Account::query()->whereIn('id', $accountIds)->sum('opening_balance');

        return bcadd((string) $opening, bcsub((string) ($row->d ?? 0), (string) ($row->c ?? 0), 4), 4);
    }
}
