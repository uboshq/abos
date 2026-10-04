<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Engines\Overview\ConfirmOverview;
use App\Core\Support\DateFormat;
use App\Core\Support\Money;
use App\Models\Approval;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Models\Collection;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesReturn;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ রাখা চালান আর বিলের সারাংশ — "নিশ্চিত করুন" চাপলে আগে এটা (মালিক, ৪ অক্টোবর ২০২৬: *"sob kichutei"*; [[ConfirmOverview]])।
 *
 * ⓘ সরাসরি বিক্রয়/ক্রয়ের সারাংশ পর্দার ঘর থেকে কষে; এখানে কাগজটা আগেই খসড়া হয়ে বসে আছে, তাই সারাংশ কাগজের নিজের
 * সারি আর মোট পড়ে — নতুন অঙ্ক কষে না।
 * ⓘ "নিশ্চিত হবে না" (`stop`) সেই দেয়ালগুলো থেকেই যা নিশ্চিতের দরজা ডাকে, আর যা কিছু লেখে না:
 *   · সীমা — [[CreditExposure::assertRoom()]], দরজার হুবহু যুক্তি (চালানে [[CreditExposure::billedAs()]], বিলে মোট);
 *   · ছাড়ের সই — অঙ্ক [[SalesInvoiceService::discountAwaitingSignature()]], সিদ্ধান্ত [[ApprovalEngine::latestFor()]]।
 * ⓘ চালানের সই-ছক ([[DocumentApproval::assertClear()]], `sales.challan`) কেবল জানানো হয় — থামায় না, খসড়া রেখে সইয়ে পাঠায়।
 * ⛔ কেবল দেখায়: আসল পাহারা নিশ্চিতের দরজাতেই।
 */
final class SalesPaperOverview
{
    public function __construct(
        private readonly CreditExposure $credit,
        private readonly ApprovalEngine $approvals,
        private readonly SalesInvoiceService $invoices,
        private readonly CollectionService $collections,
        private readonly SalesReturnService $returns,
    ) {}

    public function challan(DeliveryChallan $challan): ConfirmOverview
    {
        $challan->loadMissing(['customer', 'warehouse', 'lines.product', 'lines.batch']);

        $o = ConfirmOverview::titled(__('sales::overview_confirm.challan_title', ['no' => $challan->document_no]))
            ->head(__('sales::field.customer'), $challan->customer?->name())
            ->head(__('sales::field.date'), DateFormat::format($challan->trx_date))
            ->head(__('sales::field.warehouse'), $challan->warehouse?->name());

        foreach ($challan->lines as $line) {
            $free = (string) ($line->free_qty ?? '0');
            $o->line((string) $line->product?->name(), [
                $line->batch !== null ? __('sales::overview_confirm.lot', ['lot' => $line->batch->batch_no]) : null,
                Money::quantity((string) $line->delivered_qty).' × '.Money::format((string) $line->rate),
                bccomp($free, '0', 4) > 0 ? __('sales::overview_confirm.free', ['qty' => Money::quantity($free)]) : null,
            ], Money::format((string) $line->amount));
        }

        $o->total(__('sales::overview_confirm.challan_total'), Money::format((string) $challan->total), strong: true);

        if ($challan->customer !== null) {
            $this->standing($o, $challan->customer, fn () => $this->credit->assertRoom(
                customer: $challan->customer,
                adding: $this->credit->billedAs($challan),
                payingNow: '0',
                exceptChallanId: (int) $challan->id,
            ));
        }

        if ($this->approvals->requires('sales', 'challan', (string) $challan->total, class_basename(DeliveryChallan::class))) {
            $o->note(__('sales::overview_confirm.challan_signature'), 'warn');
        }

        return $o;
    }

    public function invoice(SalesInvoice $invoice): ConfirmOverview
    {
        $invoice->loadMissing(['customer', 'warehouse', 'lines.product']);

        $o = ConfirmOverview::titled(__('sales::overview_confirm.invoice_title', ['no' => $invoice->document_no]))
            ->head(__('sales::field.customer'), $invoice->customer?->name())
            ->head(__('sales::field.date'), DateFormat::format($invoice->trx_date))
            ->head(__('sales::field.warehouse'), $invoice->warehouse?->name());

        foreach ($invoice->lines as $line) {
            $discount = (string) ($line->discount ?? '0');
            $tax = (string) ($line->tax ?? '0');
            $o->line((string) $line->product?->name(), [
                Money::quantity((string) $line->qty).' × '.Money::format((string) $line->rate),
                bccomp($discount, '0', 4) > 0 ? __('sales::overview_confirm.discount_amount', ['amount' => Money::format($discount)]) : null,
                bccomp($tax, '0', 4) > 0 ? __('sales::overview_confirm.vat', ['amount' => Money::format($tax)]) : null,
            ], Money::format((string) $line->amount));
        }

        $o->total(__('sales::overview_confirm.lines_total'), Money::format((string) $invoice->subtotal));
        foreach ([
            'line_discounts' => (string) ($invoice->discount ?? '0'),
            'bill_discount' => (string) ($invoice->bill_discount ?? '0'),
            'vat_total' => (string) ($invoice->tax ?? '0'),
        ] as $key => $amount) {
            if (bccomp($amount, '0', 4) > 0) {
                $o->total(__('sales::overview_confirm.'.$key), Money::format($amount));
            }
        }
        $rounding = (string) ($invoice->rounding_amount ?? '0');
        if (bccomp($rounding, '0', 4) !== 0) {
            $o->total(__('sales::overview_confirm.rounding'), Money::format($rounding));
        }
        $o->total(__('sales::overview_confirm.net'), Money::format((string) $invoice->total), strong: true);

        // ⓘ কাউন্টারে রাখা বিক্রি অন্য পথে শেষ হয় ([[DirectSaleService::finishHeld()]]) — জমার টাকা সেখানে গোনা হয়,
        // তাই এখানে সীমার "না" বলা হয় না; কেবল বকেয়ার ছবি
        $waits = $invoice->waitsAtTheCounter();

        if ($invoice->customer !== null) {
            $this->standing($o, $invoice->customer, $waits ? null : fn () => $this->credit->assertRoom(
                customer: $invoice->customer,
                adding: (string) $invoice->total,
                payingNow: '0',
                exceptInvoiceId: (int) $invoice->id,
            ));
        }

        $this->discountSignature($o, $invoice);

        return $o;
    }

    /**
     * ⭐ টাকা আদায় — কার কাছ থেকে, কোন খাতে, কোন বিলে কত বসবে, আর আদায়ের পরে বকেয়া কত।
     * ⓘ "নিশ্চিত হবে না" দরজার নিজের পাহারা থেকে ([[CollectionService::whatWouldStopTheConfirm()]]): চেক এই পথে নয়,
     * আর বিলের বাকির বেশি বসানো যায় না।
     */
    public function collection(Collection $collection): ConfirmOverview
    {
        $collection->loadMissing(['customer', 'account', 'lines.invoice']);

        $o = ConfirmOverview::titled(__('sales::overview_confirm.collection_title', ['no' => $collection->document_no]))
            ->head(__('sales::field.customer'), $collection->customer?->name())
            ->head(__('sales::field.date'), DateFormat::format($collection->trx_date))
            ->head(__('sales::overview_confirm.into_account'), $collection->account?->name())
            ->head(__('sales::overview_confirm.instrument'), filled($collection->instrument)
                ? trim($collection->instrument.' '.$collection->instrument_no) : null);

        foreach ($collection->lines as $line) {
            if ($line->invoice === null) {
                continue;
            }
            $o->line(__('sales::overview_confirm.against_bill', ['no' => $line->invoice->document_no]), [
                __('sales::overview_confirm.bill_due_now', ['amount' => Money::format($line->invoice->dueAmount())]),
            ], Money::format((string) $line->amount));
        }

        $amount = (string) $collection->amount;
        $o->total(__('sales::overview_confirm.collection_total'), Money::format($amount), strong: true);

        if ($collection->customer !== null) {
            $before = (string) $collection->customer->outstanding();
            $after = bcsub($before, $amount, 4);
            $o->money(__('sales::overview_confirm.old_due'), Money::format($before));
            $o->money(bccomp($after, '0', 4) < 0 ? __('sales::overview_confirm.advance_after') : __('sales::overview_confirm.due_after'),
                Money::format(bccomp($after, '0', 4) < 0 ? bcmul($after, '-1', 4) : $after),
                bccomp($after, '0', 4) < 0 ? 'good' : 'plain');
        }

        foreach ($this->collections->whatWouldStopTheConfirm($collection) as $message) {
            $o->note($message, 'stop');
        }

        if ($this->approvals->requires('sales', 'collection', $amount, class_basename(Collection::class))) {
            $o->note(__('sales::overview_confirm.collection_signature'), 'warn');
        }

        return $o;
    }

    /**
     * ⭐ বিক্রি ফেরত — কোন বিলের, কোন মাল কত, কেন; ফেরতের মোট আর ক্রেতার বকেয়া ফেরতের পরে।
     * ⓘ "নিশ্চিত হবে না" দরজার নিজের পাহারা থেকে ([[SalesReturnService::whatWouldStopTheConfirm()]]): কারণ, বিল, আর বেচার
     * বেশি ফেরত নয়।
     */
    public function salesReturn(SalesReturn $return): ConfirmOverview
    {
        $return->loadMissing(['customer', 'warehouse', 'invoice', 'reasonCode', 'lines.product', 'lines.batch', 'lines.reasonCode']);

        $o = ConfirmOverview::titled(__('sales::overview_confirm.return_title', ['no' => $return->document_no]))
            ->head(__('sales::field.customer'), $return->customer?->name())
            ->head(__('sales::field.date'), DateFormat::format($return->trx_date))
            ->head(__('sales::overview_confirm.return_of_bill'), $return->invoice?->document_no)
            ->head(__('sales::field.warehouse'), $return->warehouse?->name())
            ->head(__('sales::overview_confirm.return_reason'), $return->reasonCode?->name());

        foreach ($return->lines as $line) {
            $o->line((string) $line->product?->name(), [
                $line->batch !== null ? __('sales::overview_confirm.lot', ['lot' => $line->batch->batch_no]) : null,
                Money::quantity((string) $line->qty).' × '.Money::format((string) $line->rate),
                $line->reasonCode !== null ? __('sales::overview_confirm.return_line_reason', ['reason' => $line->reasonCode->name()]) : null,
            ], Money::format((string) $line->amount));
        }

        $total = (string) $return->total;
        $o->total(__('sales::overview_confirm.return_total'), Money::format($total), strong: true);

        if ($return->customer !== null) {
            $before = (string) $return->customer->outstanding();
            $after = bcsub($before, $total, 4);
            $o->money(__('sales::overview_confirm.old_due'), Money::format($before));
            $o->money(bccomp($after, '0', 4) < 0 ? __('sales::overview_confirm.advance_after_return') : __('sales::overview_confirm.due_after_return'),
                Money::format(bccomp($after, '0', 4) < 0 ? bcmul($after, '-1', 4) : $after));
        }

        foreach ($this->returns->whatWouldStopTheConfirm($return) as $message) {
            $o->note($message, 'stop');
        }

        if ($this->approvals->requires('sales', 'return', $total, class_basename(SalesReturn::class))) {
            $o->note(__('sales::overview_confirm.return_signature'), 'warn');
        }

        return $o;
    }

    /** বকেয়ার ছবি আর সীমার দেয়াল — দেয়াল ছুঁড়লে তার নিজের কথাটাই "নিশ্চিত হবে না" */
    private function standing(ConfirmOverview $o, Customer $customer, ?\Closure $wall): void
    {
        $ledger = (string) $customer->outstanding();
        if (bccomp($ledger, '0', 4) > 0) {
            $o->money(__('sales::overview_confirm.old_due'), Money::format($ledger));
        } elseif (bccomp($ledger, '0', 4) < 0) {
            $o->money(__('sales::overview_confirm.advance'), Money::format(bcmul($ledger, '-1', 4)), 'good');
        }

        if (! $this->credit->isOn()) {
            return;
        }

        $o->money(__('sales::overview_confirm.limit'), Money::format((string) $customer->credit_limit));

        if ($wall === null) {
            return;
        }

        try {
            $wall();
        } catch (ValidationException $e) {
            foreach ($e->validator->errors()->all() as $message) {
                $o->note($message, 'stop');
            }
        }
    }

    private function discountSignature(ConfirmOverview $o, SalesInvoice $invoice): void
    {
        $discount = $this->invoices->discountAwaitingSignature($invoice);

        if (bccomp($discount, '0', 4) <= 0) {
            return;
        }

        $decided = $this->approvals->latestFor($invoice, 'discount');

        if ($decided?->status === Approval::APPROVED) {
            $o->note(__('sales::overview_confirm.discount_signed'), 'info');

            return;
        }

        if ($decided?->status === Approval::REJECTED) {
            $o->note(__('sales::validation.discount_rejected'), 'stop');

            return;
        }

        if ($this->approvals->requires('sales', 'discount', $discount, class_basename(SalesInvoice::class))) {
            $o->note(__('sales::overview_confirm.discount_signature'), 'warn');
        }
    }
}
