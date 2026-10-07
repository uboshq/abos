<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Contracts\ApprovalBundles;
use App\Core\Engines\Attachment\AttachmentEngine;
use App\Core\Engines\Drill\DrillResolver;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Models\Approval;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\MasterData\Models\PaymentMethod;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Support\SalesSigningSheet;

/**
 * সইয়ে আটকে থাকা কাউন্টারের বিক্রি — এক পাতায়, এক ক্লিকে (২৮ সেপ্টেম্বর ২০২৬)।
 *
 * ⓘ মালিক: *"এক নজরে সব দেখে এক ক্লিকে সব অনুমোদন … এটা কনফার্মেশন"*। বিক্রির
 * কাগজ তিন রকম — চালান, বিল, আর প্রতিটা জমার ভাউচার — আর প্রতিটা নিজের অনুরোধ।
 * ⭐ কোনগুলো এক বিক্রির, সেটা [[HeldCounterSaleFinisher]] আগেই জানে (শেষ সইয়ে
 * বিক্রিটা শেষ করে সে-ই); এখানে একই উত্তর — দুই জায়গায় দুই হিসাব হলে পাতা যা
 * দেখাত আর শেষ সই যা শেষ করত, তা একদিন আলাদা হত।
 *
 * ⚠️ পাতা কেবল দেখায়; কে কোনটায় সই দিতে পারেন তা অনুমোদনের ইঞ্জিনই ঠিক করে
 * ([[ApprovalEngine::canDecide()]]) — এখানে কোনো ছাড় নেই।
 */
final class CounterSaleBundle implements ApprovalBundles
{
    public function __construct(
        private readonly HeldCounterSaleFinisher $finisher,
        private readonly AttachmentEngine $attachments,
        private readonly DrillResolver $drill,
    ) {}

    public function bundleFor(Approval $approval): ?array
    {
        $invoice = $this->finisher->invoiceFor($approval);

        // ⓘ কেবল কাউন্টারের আটকে থাকা বিক্রি — অফিসের বিলের নিজের অনুরোধ আগের মতো একা
        if ($invoice === null || $invoice->status !== 'draft') {
            return null;
        }

        $approvals = $this->finisher->latestApprovals($invoice);

        if ($approvals === []) {
            return null;
        }

        $invoice->loadMissing(['lines.product', 'lines.challanLine', 'customer', 'warehouse']);
        $sheet = SalesSigningSheet::ofInvoice($invoice);

        $challanId = (int) ($invoice->lines->first()?->challanLine?->delivery_challan_id ?? 0);
        $challan = $challanId > 0 ? DeliveryChallan::acrossBranches()->find($challanId) : null;

        $deposits = $this->deposits($invoice);
        $paid = array_reduce($deposits, fn (string $sum, array $d) => bcadd($sum, $d['raw'], 4), '0');
        $due = bcsub((string) $invoice->total, $paid, 4);

        $facts = array_filter([
            __('approval::field.bill_no') => (string) $invoice->document_no,
            __('approval::field.challan_no') => $challan?->document_no,
            __('approval::field.transport') => $challan === null ? null : $this->transport($challan),
            __('sales::field.driver_name') => $challan?->driver_name,
            __('approval::field.driver_phone') => $challan?->driver_phone,
            __('sales::field.transport_cost') => $challan !== null && bccomp((string) $challan->transport_cost, '0', 4) > 0
                ? Money::format($challan->transport_cost) : null,
            __('approval::field.deposited') => Money::format($paid),
            __('approval::field.due_left') => Money::format(bccomp($due, '0', 4) > 0 ? $due : '0'),
        ], fn ($v) => filled($v));

        return [
            'facts' => [
                ...$sheet['facts'],
                ...array_map(
                    fn ($label, $value) => ['label' => (string) $label, 'value' => (string) $value],
                    array_keys($facts),
                    $facts,
                ),
            ],
            'columns' => $sheet['columns'],
            'rows' => $sheet['rows'],
            'totals' => $sheet['totals'],
            'deposits' => array_map(fn (array $d) => array_diff_key($d, ['raw' => true]), $deposits),
            'approvals' => $approvals,
            'party' => $sheet['party'] ?? null,
        ];
    }

    /**
     * পরিবহন এক লাইনে — ক্রেতার নিজের, নয় বাহক · গাড়ি।
     */
    private function transport(DeliveryChallan $challan): ?string
    {
        if ($challan->own_transport) {
            return __('approval::field.buyers_own_transport');
        }

        $parts = array_filter([$challan->carrier_name, $challan->vehiclePlate()], fn ($v) => filled($v));

        return $parts === [] ? null : implode(' · ', $parts);
    }

    /**
     * প্রতিটা জমা — কোন পথে, কোন খাতে, কত, কোন নম্বরে, আর তার স্লিপ।
     *
     * @return list<array{method: string, account: string, amount: string, reference: string, slips: list<array{name: string, url: string}>, raw: string}>
     */
    private function deposits(SalesInvoice $invoice): array
    {
        $vouchers = Voucher::acrossBranches()
            ->with('lines.account')
            ->where('origin', Voucher::ORIGIN_COUNTER)
            ->where('against_type', SalesInvoice::drillSourceType())
            ->where('against_id', $invoice->id)
            ->where('status', '<>', DocumentStatus::CANCELLED)
            ->orderBy('id')
            ->get();

        $module = $this->drill->moduleFor(Voucher::drillSourceType()) ?? '';

        return $vouchers->map(function (Voucher $voucher) use ($module) {
            // ⓘ টাকা যেখানে ঢুকল — খসড়ায় `money_account_id` থাকে না, তাই ডেবিটের সারি থেকে
            $into = $voucher->money_account_id !== null
                ? Account::query()->postable()->find($voucher->money_account_id)
                : $voucher->lines->first(fn ($l) => bccomp((string) $l->debit, '0', 4) > 0)?->account;

            // ⓘ কাউন্টার এই ঘরে পদ্ধতির কোড বসায় (`BKASH`) — নাম মাস্টার ডাটা থেকে
            $method = PaymentMethod::query()->where('code', (string) $voucher->instrument)->first()?->name()
                ?? (string) $voucher->instrument;

            $amount = (string) $voucher->totals()['debit'];

            return [
                'method' => $method,
                'account' => (string) ($into?->label() ?? '—'),
                'amount' => Money::format($amount),
                'reference' => (string) ($voucher->instrument_no ?? ''),
                'slips' => $this->attachments
                    ->listFor($module, Voucher::drillSourceType(), (int) $voucher->id)
                    ->map(fn ($paper) => [
                        'name' => (string) $paper->original_name,
                        'url' => route('attachment.download', $paper),
                    ])->values()->all(),
                'raw' => $amount,
            ];
        })->values()->all();
    }
}
