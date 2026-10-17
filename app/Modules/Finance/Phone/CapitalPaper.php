<?php

declare(strict_types=1);

namespace App\Modules\Finance\Phone;

use App\Core\Phone\PaperSheet;
use App\Core\Phone\PhonePaper;
use App\Core\Support\DateFormat;
use App\Core\Support\Money;
use App\Models\User;
use App\Modules\Finance\Models\CapitalEntry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * মূলধন — ওয়েবের `/finance/capital` ([[PhonePaper]])। ⓘ কেবল দেখা: টাকা আসে গ্রহণ ভাউচারে (Dr টাকার খাত / Cr ৩১০০), সেটা ভাউচারের
 * পথে; খসড়া = টাকা আসেনি, বসেছে = খাতায় ([[CapitalEntry::settleWith()]])।
 */
final class CapitalPaper extends PhonePaper
{
    public function key(): string
    {
        return 'finance_capital';
    }

    public function module(): string
    {
        return 'finance';
    }

    public function permission(): string
    {
        return 'finance.capital.view';
    }

    public function webRoute(): array
    {
        return ['finance.capital.index', []];
    }

    /** ⓘ দেখার শাখা মডেলের নিজের স্কোপে ([[ListedInViewedBranch]]), ওয়েবের তালিকার মতো */
    public function query(): Builder
    {
        return CapitalEntry::query()->inViewedBranch();
    }

    public function statuses(): array
    {
        return ['draft' => __('finance::state.draft'), 'posted' => __('finance::state.posted')];
    }

    /** ⓘ বাতিল নেই — না বাছলে সব */
    public function applyStatus(Builder $query, ?string $status): void
    {
        if ($status !== null) {
            $query->where('acc_capital_entries.status', $status);
        }
    }

    public function searchable(): bool
    {
        return false;
    }

    public function sumColumn(User $user): ?string
    {
        return 'acc_capital_entries.amount';
    }

    public function with(): array
    {
        return ['person'];
    }

    public function row(Model $paper, User $user): array
    {
        /** @var CapitalEntry $paper */
        return [
            'party' => self::nameOf($paper->person),
            'note' => __('finance::who.'.$paper->contributor_type).' · '.__('finance::kind.'.$paper->entry_type),
            'total' => (string) $paper->amount,
        ];
    }

    public function detail(Model $paper, User $user): array
    {
        /** @var CapitalEntry $paper */
        $paper->loadMissing(['person', 'voucher', 'account', 'creator']);

        return PaperSheet::make(facts: [
            __('finance::field.who') => self::nameOf($paper->person),
            __('finance::field.as') => __('finance::who.'.$paper->contributor_type),
            __('finance::field.kind') => __('finance::kind.'.$paper->entry_type),
            __('finance::field.in_kind') => __('finance::field.in_kind_'.$paper->in_kind),
            __('finance::field.date') => DateFormat::format($paper->trx_date),
            __('finance::field.amount') => Money::format((string) $paper->amount),
            __('finance::field.share') => $paper->share_percent === null ? null : rtrim(rtrim((string) $paper->share_percent, '0'), '.').'%',
            __('finance::field.received_into') => $paper->account?->label(),
            __('finance::field.state') => __('finance::state.'.$paper->status).($paper->voucher?->document_no ? ' · '.$paper->voucher->document_no : ''),
            __('finance::field.narration') => $paper->narration,
        ]);
    }
}
