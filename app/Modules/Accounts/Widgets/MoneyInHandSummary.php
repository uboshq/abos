<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Widgets;

use App\Core\Widgets\WidgetSummary;
use App\Models\User;
use App\Modules\Accounts\Dashboard\AccountsWidgets;

/**
 * ⭐ ২ · টাকা হাতে — নগদ বাক্স, ব্যাংক, MFS আর পথে থাকা টাকা (উইজেটের নকশা ১৯ অক্টোবর, পাতা ১; মালিকের আদেশ, ২০ অক্টোবর ২০২৬;
 * cb)।
 *
 * ⓘ ওয়েবের হোমের "হাতে ও ব্যাংকে মোট" আর ফোনের আজকের পাতার একই উৎস ([[AccountsWidgets]]; [[DashboardTodayController]]-এর
 * `money`)। মোট = নগদ + MFS + ব্যাংক, ওয়েবের মতো; পথে আলাদা। চাবি `accounts.till.view` — আজকের পাতার একই।
 */
final class MoneyInHandSummary implements WidgetSummary
{
    public function key(): string
    {
        return 'money_in_hand';
    }

    public function module(): string
    {
        return 'accounts';
    }

    public function permission(): string
    {
        return 'accounts.till.view';
    }

    public function data(User $user): array
    {
        $cash = self::money(AccountsWidgets::cashInHand());
        $mfs = self::money(AccountsWidgets::mfsBalance());
        $bank = self::money(AccountsWidgets::bankBalance());

        return [
            'cash' => $cash,
            'mfs' => $mfs,
            'bank' => $bank,
            'transit' => self::money(AccountsWidgets::inTransit()),
            'total' => bcadd(bcadd($cash, $mfs, 4), $bank, 4),
            'link' => '/accounts/cash-tills',
        ];
    }

    private static function money(string $value): string
    {
        return bcadd($value === '' ? '0' : $value, '0', 4);
    }
}
