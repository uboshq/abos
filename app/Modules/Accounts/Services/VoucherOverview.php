<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use App\Core\Engines\Overview\ConfirmOverview;
use App\Core\Services\PartyRegistry;
use App\Core\Support\DateFormat;
use App\Core\Support\Money;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Models\VoucherLine;

/**
 * ⭐ ভাউচারের "পোস্ট করুন"-এর আগের সারাংশ (মালিক, ৪ অক্টোবর ২০২৬: *"sob kichutei"*; [[ConfirmOverview]])।
 *
 * ⓘ ভাউচারটা আগেই খসড়া — সারাংশ তার নিজের সারি পড়ে: কোন খাত, ডেবিট না ক্রেডিট, কোন পক্ষ, কী বাবদ; নিচে দুই
 * পাশের মোট আর মিলল কি না।
 * ⓘ সই — [[VoucherApproval::waitFor()]], পোস্টের দরজার একই ধাপ, কিছু না লিখে: সই লাগে না / হয়ে আছে / অপেক্ষায় /
 * ফেরানো ("পোস্ট হবে না")।
 * ⛔ কেবল দেখায়: খাতের মিল, নিজের বাক্স, ব্যাংক রেফারেন্স, বাক্সে টাকা — এসব পাহারা পোস্টের দরজাতেই।
 */
final class VoucherOverview
{
    public function __construct(
        private readonly VoucherApproval $approvals,
        private readonly PartyRegistry $parties,
    ) {}

    public function build(Voucher $voucher): ConfirmOverview
    {
        $voucher->loadMissing('lines.account');

        $names = $this->parties->labelsOf(
            $voucher->lines
                ->filter(fn (VoucherLine $l) => $l->party_type !== null && $l->party_id !== null)
                ->map(fn (VoucherLine $l) => [(string) $l->party_type, (int) $l->party_id])
                ->push([(string) $voucher->party_type, (int) $voucher->party_id]),
        );

        $o = ConfirmOverview::titled(__('accounts::overview_confirm.title', ['type' => $voucher->typeLabel(), 'no' => $voucher->document_no]))
            ->head(__('accounts::overview_confirm.date'), DateFormat::format($voucher->trx_date))
            ->head(__('accounts::overview_confirm.party'), $names[$voucher->party_type.':'.$voucher->party_id] ?? null)
            ->head(__('accounts::overview_confirm.narration'), filled($voucher->narration) ? (string) $voucher->narration : null);

        foreach ($voucher->lines as $line) {
            $debit = (string) ($line->debit ?? '0');
            $side = bccomp($debit, '0', 4) > 0 ? 'debit' : 'credit';
            $o->line((string) $line->account?->label(), [
                __('accounts::overview_confirm.'.$side),
                $names[$line->party_type.':'.$line->party_id] ?? null,
                filled($line->narration) ? (string) $line->narration : null,
            ], Money::format($side === 'debit' ? $debit : (string) $line->credit));
        }

        $totals = $voucher->totals();
        $o->total(__('accounts::overview_confirm.debit_total'), Money::format($totals['debit']), strong: true);
        $o->total(__('accounts::overview_confirm.credit_total'), Money::format($totals['credit']), strong: true);

        if (bccomp($totals['debit'], $totals['credit'], 4) !== 0) {
            $o->note(__('accounts::overview_confirm.unbalanced'), 'stop');
        }

        match ($this->approvals->waitFor($voucher)) {
            'signed' => $o->note(__('accounts::overview_confirm.signed'), 'info'),
            'rejected' => $o->note(__('accounts::overview_confirm.rejected'), 'stop'),
            'awaiting' => $o->note(__('accounts::overview_confirm.awaiting'), 'warn'),
            default => null,
        };

        return $o;
    }
}
