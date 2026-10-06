<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Services\Ownership;
use App\Core\Services\OpenPeriod;
use App\Core\Support\DocumentStatus;
use App\Models\Approval;
use App\Models\User;
use App\Modules\Accounts\Models\Note;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\DeliveryChallanLine;
use App\Modules\Sales\Models\GatePass;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesInvoiceCancellation;
use App\Modules\Sales\Models\SalesReturn;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * বাতিল-ইনভয়েস (Cancellation Invoice) — মালিক, ৪ অক্টোবর ২০২৬: আন্তর্জাতিক মান।
 *
 * ── কী হয় ──────────────────────────────────────────────────────────────────
 * ভুল পাকা ইনভয়েসের পুরো উল্টো কাগজ, নিজের নম্বরে (CXL-xxxx)। আসল ইনভয়েস মোছে না ([[SalesInvoiceService::assertNotPosted()]] —
 * ২ অক্টোবরের নিয়ম), "বাতিল" হয়ে থাকে, পাশে এই কাগজের সূত্র। পাকা হওয়ার মুহূর্তে:
 *   · চালানের মাল গুদামে ফেরে, চালান বাতিল ([[DeliveryChallanService::reverseForCancellation()]]);
 *   · বিলের খাতা, চালান ছাড়া বেরোনো মাল আর খরচের স্তর উল্টায় ([[SalesInvoiceService::reverseForCancellation()]]);
 *   · উল্টো সারিগুলো এই কাগজের নম্বরে — খতিয়ানে দেখা যায় কোন কাগজ বিলটা উল্টাল।
 * আদায় হয়ে থাকলে টাকাটা খাতায় থাকে — গ্রাহকের অগ্রিম হয়ে যায় (কাগজে লেখা থাকে)।
 *
 * ── ⛔ কখন নয় ───────────────────────────────────────────────────────────────
 *   · গেট পাস হয়ে মাল বেরিয়ে গেছে → ফেরত (SRT), এই কাগজ নয়;
 *   · ফেরত বা ক্রেডিট নোট আছে → তারা আগে উল্টাক;
 *   · চালানে অন্য বিলের মালও আছে → চালান উল্টানো যায় না, ফেরত;
 *   · বিলের মাস বন্ধ → মাসের তালা ([[OpenPeriod::lockOn()]]); অনুমতিপ্রাপ্ত কেউ কারণসহ মাস খুললে তবেই।
 *
 * ── কে দেন ──────────────────────────────────────────────────────────────────
 * চাবি `sales.invoice.cancellation`। সইয়ের ধারা (`sales.cancellation`) প্রতিষ্ঠান নিজে ঠিক করে — মালিকের সংস্করণ ২:
 * "অনুমোদনের ধারা প্রতিষ্ঠান নিজে ঠিক করে, বাধ্যতামূলক কিছু নয়"। ছক থাকলে সইয়ের অপেক্ষা, শেষ সইয়ে নিজে পাকা
 * ([[FinishTheCancellationOnTheLastSignature]]); ছক না থাকলে চাবিওয়ালার হাতেই পাকা। মালিক (সুপার অ্যাডমিন) নিজে দিলে
 * সেটাই সই। ⓘ অডিটে বিলের "বাতিল-ইনভয়েস" কাজ, আর এই কাগজের নিজের ইতিহাস ([[IsAudited]])।
 */
final class SalesInvoiceCancellationService
{
    public const APPROVAL_ACTION = 'cancellation';

    public const DOC_TYPE = 'CXL';

    public function __construct(
        private readonly SalesInvoiceService $invoices,
        private readonly DeliveryChallanService $challans,
        private readonly NumberSeriesEngine $numbers,
        private readonly ApprovalEngine $approvals,
        private readonly Ownership $ownership,
    ) {}

    /**
     * বাতিল-ইনভয়েস চাওয়া — মালিক দিলে সাথে সাথে পাকা, নইলে সইয়ের অপেক্ষা।
     */
    public function request(SalesInvoice $invoice, User $by, string $reason): SalesInvoiceCancellation
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => __('sales::cancellation.reason_required')]);
        }

        $this->assertCancellable($invoice);

        $existing = SalesInvoiceCancellation::query()->where('sales_invoice_id', $invoice->id)->first();

        if ($existing !== null) {
            throw ValidationException::withMessages(['reason' => __('sales::cancellation.already', [
                'no' => $invoice->document_no, 'cxl' => $existing->document_no,
            ])]);
        }

        // ⓘ এক লেনদেনে — মালিকের হাতে পাকা হতে না পারলে (মাস বন্ধ ইত্যাদি) কাগজ আর নম্বর দুইটাই ফেরে
        return DB::transaction(function () use ($invoice, $by, $reason) {
            $paper = SalesInvoiceCancellation::query()->create([
                'company_id' => $invoice->company_id,
                'branch_id' => $invoice->branch_id,
                'sales_invoice_id' => $invoice->id,
                'customer_id' => $invoice->customer_id,
                'document_no' => $this->numbers->next(self::DOC_TYPE, $invoice->branch_id),
                'trx_date' => Carbon::today()->toDateString(),
                'reason' => $reason,
                'subtotal' => bcsub((string) $invoice->total, (string) ($invoice->tax ?? '0'), 4),
                'tax' => (string) ($invoice->tax ?? '0'),
                'total' => (string) $invoice->total,
                'status' => DocumentStatus::DRAFT,
                'created_by' => $by->id,
            ]);

            // ⭐ মালিক নিজে — তাঁর হাতই সই
            if ($this->ownership->isOwnerIn($by, (int) $invoice->company_id)) {
                return $this->confirm($paper, $by);
            }

            $approval = $this->approvals->request(
                document: $paper,
                module: 'sales',
                action: self::APPROVAL_ACTION,
                amount: (string) $paper->total,
                reason: $reason,
                userId: $by->id,
            );

            // ⓘ প্রতিষ্ঠানের সইয়ের ছক নেই — সই বাধ্যতামূলক নয় (মালিকের সংস্করণ ২): চাবিওয়ালার হাতেই পাকা
            if ($approval === null) {
                return $this->confirm($paper, $by);
            }

            $paper->update(['status' => SalesInvoiceCancellation::AWAITING]);

            return $paper->fresh();
        });
    }

    /**
     * পাকা করা — খাতা আর মজুদ উল্টায়, বিল আর চালান বাতিল। মালিকের হাতে, বা শেষ সইয়ের পরে।
     */
    public function confirm(SalesInvoiceCancellation $cancellation, ?User $by): SalesInvoiceCancellation
    {
        if ($cancellation->status === DocumentStatus::CONFIRMED) {
            return $cancellation;
        }

        $invoice = SalesInvoice::query()->findOrFail($cancellation->sales_invoice_id);
        $by ??= $cancellation->creator;

        if (! $by instanceof User) {
            throw ValidationException::withMessages(['reason' => __('sales::cancellation.awaiting_signature', ['cxl' => $cancellation->document_no])]);
        }

        $reason = (string) __('sales::cancellation.narration', ['cxl' => $cancellation->document_no, 'reason' => $cancellation->reason]);

        /*
         * ⓘ নিজের লেনদেন আর তালা — বিলের সারি তালাবদ্ধ করে তাজা পড়া, তারপর সব পাহারা আবার (মালিকের সংস্করণ ২: পাকা কাগজ আর
         * সম্পাদনা হয় না, তাই সংশোধনের পথ নয়)। ⛔ দুই চাপের মাঝে গেট পাস, ফেরত, মাসের তালা — এখানেই থামে।
         */
        DB::transaction(function () use ($cancellation, $invoice, $by, $reason): void {
            $locked = SalesInvoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $paper = SalesInvoiceCancellation::query()->lockForUpdate()->findOrFail($cancellation->id);

            if ($paper->status === DocumentStatus::CONFIRMED) {
                return;
            }

            $signed = $this->approvals->latestFor($paper, self::APPROVAL_ACTION)?->status === Approval::APPROVED;
            $flowless = $paper->status === DocumentStatus::DRAFT;

            if (! $signed && ! $flowless && ! $this->ownership->isOwnerIn($by, (int) $locked->company_id)) {
                throw ValidationException::withMessages(['reason' => __('sales::cancellation.awaiting_signature', ['cxl' => $paper->document_no])]);
            }

            // ⛔ বিলের মাস বন্ধ — ছাপা হয়ে যাওয়া হিসাব; আগে অনুমতিপ্রাপ্ত কেউ কারণসহ মাস খুলুন
            if (app(OpenPeriod::class)->lockOn($locked->trx_date->format('Y-m-d')) !== null) {
                throw ValidationException::withMessages(['reason' => __('sales::cancellation.month_closed', [
                    'no' => $locked->document_no, 'month' => $locked->trx_date->format('m/Y'),
                ])]);
            }

            $challans = $this->assertCancellable($locked);
            $date = Carbon::today();

            foreach ($challans as $challan) {
                $this->challans->reverseForCancellation(
                    DeliveryChallan::query()->lockForUpdate()->findOrFail($challan->id), $date, $reason, $paper->document_no, $by->id,
                );
            }

            $this->invoices->reverseForCancellation($locked, $date, $reason, $paper->document_no, $by->id);

            $paper->update([
                'status' => DocumentStatus::CONFIRMED,
                'trx_date' => $date->toDateString(),
                'confirmed_by' => $by->id,
                'confirmed_at' => now(),
            ]);

            $locked->auditAction('cancelled_by_cxl', $paper->document_no.' — '.$paper->reason);
        });

        return $cancellation->fresh();
    }

    /** সই প্রত্যাখ্যাত — কাগজ বাতিল, বিল যেমন ছিল ([[FinishTheCancellationOnTheLastSignature]])। */
    public function reject(SalesInvoiceCancellation $cancellation): void
    {
        if ($cancellation->status === SalesInvoiceCancellation::AWAITING) {
            $cancellation->update(['status' => DocumentStatus::CANCELLED]);
        }
    }

    /**
     * এই বিল বাতিল-ইনভয়েসে উল্টানো যায় কি না — আর যায় তো কোন চালানগুলো সাথে উল্টাবে।
     *
     * @return Collection<int, DeliveryChallan>
     */
    public function assertCancellable(SalesInvoice $invoice): Collection
    {
        $no = ['no' => $invoice->document_no];

        if ($invoice->status !== DocumentStatus::CONFIRMED) {
            throw ValidationException::withMessages(['reason' => __('sales::cancellation.not_confirmed', $no)]);
        }

        $lineIds = $invoice->lines()->whereNotNull('delivery_challan_line_id')->pluck('delivery_challan_line_id');
        $challanIds = DeliveryChallanLine::query()->whereIn('id', $lineIds)->distinct()->pluck('delivery_challan_id');
        $challans = DeliveryChallan::query()->whereIn('id', $challanIds)->get();

        // ⛔ মাল বেরিয়ে গেছে — ফেরত, বাতিল-ইনভয়েস নয়
        if (GatePass::query()->whereIn('delivery_challan_id', $challanIds)->where('status', '<>', GatePass::CANCELLED)->exists()) {
            throw ValidationException::withMessages(['reason' => __('sales::cancellation.after_gate_pass', $no)]);
        }

        $returned = SalesReturn::query()->where('sales_invoice_id', $invoice->id)->where('status', '<>', DocumentStatus::CANCELLED)->exists();
        $noted = Note::query()->where('direction', Note::CREDIT)->where('against_no', $invoice->document_no)
            ->where('status', '<>', DocumentStatus::CANCELLED)->exists();

        if ($returned || $noted) {
            throw ValidationException::withMessages(['reason' => __('sales::cancellation.after_return', $no)]);
        }

        // ⛔ চালানে অন্য বিলের মালও — চালানটা গোটা উল্টানো যেত না
        foreach ($challans as $challan) {
            $own = DeliveryChallanLine::query()->where('delivery_challan_id', $challan->id)->whereIn('id', $lineIds)->count();
            $all = DeliveryChallanLine::query()->where('delivery_challan_id', $challan->id)->count();

            /*
             * ⭐ একই চালান-সারি দুই বিলে ভাগ — পুরো ERP অডিট ⛔৩, ৬ অক্টোবর ২০২৬।
             * ⛔ আগে কেবল সারি গোনা হত: চালানের এক সারির ১০-এর ৬ বিল A-তে, ৪ বিল B-তে হলে দুই বিলই ঐ একটা সারি দেখায়, তাই
             * "নিজের = সব" মিলত — A বাতিলে গোটা চালান (১০) ফিরত, অথচ B-র আয় আর খরচ খাতায় থেকে যেত। ⓘ এখন চালানের কোনো
             * সারিতে অন্য কোনো চালু (বাতিল নয়) বিলের সারি থাকলে চালানটা ভাগের — ফেরত দিন।
             */
            $sharedWithAnotherBill = \App\Modules\Sales\Models\SalesInvoiceLine::query()
                ->whereIn('delivery_challan_line_id', DeliveryChallanLine::query()->where('delivery_challan_id', $challan->id)->select('id'))
                ->where('sales_invoice_id', '<>', $invoice->id)
                ->whereHas('invoice', fn ($q) => $q->where('status', '<>', DocumentStatus::CANCELLED))
                ->exists();

            if ($own !== $all || $sharedWithAnotherBill || $challan->status !== DocumentStatus::CONFIRMED) {
                throw ValidationException::withMessages(['reason' => __('sales::cancellation.shared_challan', [...$no, 'challan' => $challan->document_no])]);
            }
        }

        return $challans;
    }
}
