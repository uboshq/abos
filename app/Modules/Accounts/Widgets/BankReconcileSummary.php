<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Widgets;

use App\Core\Services\DataScope;
use App\Core\Support\DocumentStatus;
use App\Core\Widgets\WidgetSummary;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\BankReconciliation;
use App\Modules\Accounts\Models\VoucherLine;

/**
 * ⭐ ২০ · ব্যাংক মেলানো বাকি — ব্যাংকের খাতের কতগুলো সারি এখনো কোনো পাকা মেলানোয় ওঠেনি, আর শেষ পাকা মেলানো কবেকার
 * (উইজেটের নকশা ১৯ অক্টোবর, পাতা ২; মালিকের আদেশ, ২০ অক্টোবর ২০২৬; cb)।
 *
 * ⓘ "সারি" মেলানোর পাতার প্রার্থীর একই সংজ্ঞা ([[BankReconciliationService::candidates()]]): ব্যাংক-খাতে ভাউচারের সারি, পাকা
 * ভাউচার — আজ পর্যন্ত। ⓘ খসড়া মেলানোয় টিক পাওয়া সারি তবু "বাকি": খসড়া পাকা না হওয়া পর্যন্ত কিছুই মেলানো নয়।
 * ⓘ দেখার শাখা ভাউচারের শাখা ধরে ([[DataScope::inView()]], হোমের নিয়ম); শেষ মেলানো মডেলের নিজের শাখার দেয়ালে।
 * চাবি মেলানোর পাতার একই (`accounts.reconciliation.view`)।
 */
final class BankReconcileSummary implements WidgetSummary
{
    public function key(): string
    {
        return 'bank_reconcile';
    }

    public function module(): string
    {
        return 'accounts';
    }

    public function permission(): string
    {
        return 'accounts.reconciliation.view';
    }

    public function data(User $user): array
    {
        $banks = Account::query()->where('money_kind', Account::BANK)->pluck('id')->all();
        $confirmed = BankReconciliation::query()->where('status', BankReconciliation::CONFIRMED);

        $open = VoucherLine::query()
            ->whereIn('account_id', $banks ?: [0])
            ->whereHas('voucher', fn ($q) => app(DataScope::class)
                ->inView($q->whereIn('status', DocumentStatus::POSTED), 'vouchers.branch_id'))
            ->where(fn ($q) => $q->whereNull('reconciliation_id')
                ->orWhereNotIn('reconciliation_id', (clone $confirmed)->select('id')))
            ->count();

        return [
            'open' => $open,
            'lastOn' => (clone $confirmed)->max('statement_date'),
            'link' => '/accounts/reconciliations',
        ];
    }
}
