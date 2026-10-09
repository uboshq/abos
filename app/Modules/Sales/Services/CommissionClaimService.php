<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Engines\Posting\PostingEngine;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Models\CommissionClaim;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Supplier\Models\Supplier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ডিলারের কমিশন — ডিপো আগে দেয়, কোম্পানির কাছে পরে দাবি করে।
 *
 * ── তিনটা ঘটনা, তিনটা দাখিলা ────────────────────────────────────────
 *
 *   দেওয়া   Dr কমিশনের দাবি (১১৫০)   Cr ডিলার (প্রাপ্য)
 *   মিটল    Dr সরবরাহকারী (প্রদেয়)    Cr কমিশনের দাবি
 *   নামঞ্জুর Dr প্রত্যাখ্যাত কমিশন (৫২১৫) Cr কমিশনের দাবি
 *
 * প্রথমটায় ডিলারের দেনা কমে আর ডিপোর হাতে একটা পাওনা জন্মায়। দ্বিতীয়টায়
 * সেই পাওনা কোম্পানির দেনার সাথে কাটাকাটি হয় — কোনো টাকা নড়ে না, দুইটা
 * খাতা নড়ে। তৃতীয়টায় পাওনাটা খরচ হয়ে যায়।
 *
 * ── কেন কোথাও "ছাড়" শব্দটা নেই ─────────────────────────────────────
 * ছাড় দিলে বিক্রয় কমে, আর ৪% মার্জিনের ডিপোতে ৫% কমিশন মানে খাতা বলত
 * লোকসানে বেচছি। এখানে বিক্রয় পুরোটাই থাকে, মার্জিনও অটুট, আর
 * কোম্পানির কাছে পাওনাটা বয়স ধরে দেখা যায়।
 */
final class CommissionClaimService
{
    public function __construct(
        private readonly PostingEngine $posting,
        private readonly NumberSeriesEngine $numbers,
        private readonly SettingsService $settings,
    ) {}

    /**
     * একটা কমিশন দেওয়া হলো।
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): CommissionClaim
    {
        $customer = Customer::query()->findOrFail((int) ($data['customer_id'] ?? 0));
        $supplier = Supplier::query()->findOrFail((int) ($data['supplier_id'] ?? 0));

        $invoice = filled($data['sales_invoice_id'] ?? null)
            ? SalesInvoice::query()->find((int) $data['sales_invoice_id'])
            : null;

        $this->assertTheBillIsTheirs($invoice, $customer);

        $base = $this->baseFor($data, $invoice);
        $amount = $this->amountFor($data, $base);

        $this->assertWithinLimits($amount, $base);

        return DB::transaction(function () use ($data, $customer, $supplier, $invoice, $base, $amount) {
            // ⛔ বিলের সারিতে তালা দিয়ে আবার — দুইজন একসাথে একই বিলে দাবি লিখলে দুইজনেই "নেই" দেখতেন
            if ($invoice !== null) {
                SalesInvoice::query()->whereKey($invoice->id)->lockForUpdate()->first();
                $this->assertNoClaimOnTheBill($invoice);
            }

            $claim = CommissionClaim::query()->create([
                'company_id' => CompanyContext::id(),
                'branch_id' => CompanyContext::branchId(),
                'document_no' => $this->numbers->next('CMC'),
                'trx_date' => $data['trx_date'] ?? now()->toDateString(),
                'customer_id' => $customer->id,
                'supplier_id' => $supplier->id,
                'sales_invoice_id' => $invoice?->id,
                'base_amount' => $base,
                'rate_percent' => filled($data['rate_percent'] ?? null) ? (string) $data['rate_percent'] : null,
                'rate_amount' => filled($data['rate_amount'] ?? null) ? (string) $data['rate_amount'] : null,
                'amount' => $amount,
                'status' => CommissionClaim::PENDING,
                'narration' => $data['narration'] ?? null,
                'created_by' => auth()->id(),
            ]);

            $this->posting->post(
                sourceType: CommissionClaim::STOCK_SOURCE,
                sourceId: $claim->id,
                trxDate: $claim->trx_date,
                documentNo: $claim->document_no,
                lines: [
                    /*
                     * দাবির লাইনে কোনো পক্ষ বসে না — ইচ্ছাকৃত।
                     *
                     * ── কী ভাঙত ─────────────────────────────────────
                     * প্রথমে এখানে সরবরাহকারীর নাম বসানো ছিল, আর তাতে
                     * দুইটা জিনিস ভাঙত। এক, প্রদেয়ের তালিকা পক্ষ ধরে
                     * গোনে, খাত ধরে নয় — তাই দাবিটা (ডেবিট) কোম্পানির
                     * পাওনা **কমিয়ে** দেখাত, অথচ আমরা ওদের ততটাই দেনা।
                     * দুই, সমন্বয়ের সময় দুইটা লাইনই একই পক্ষের হওয়ায়
                     * পরস্পরকে কেটে দিত, আর দেনা এক পয়সাও কমত না।
                     *
                     * কোন কোম্পানির কাছে দাবি সেটা জানা যায়
                     * `sal_commission_claims`-এর নিজের ঘর থেকে — পক্ষ
                     * ধরে খতিয়ানে বসানোর দরকার নেই।
                     */
                    [
                        'account_id' => StandardChart::find(StandardChart::COMMISSION_CLAIM)->id,
                        'debit' => $amount,
                        'narration' => __('sales::message.commission_claim_on', ['name' => $customer->name()]),
                    ],
                    [
                        'account_id' => StandardChart::find(StandardChart::RECEIVABLE)->id,
                        'party_type' => Customer::drillSourceType(),
                        'party_id' => $customer->id,
                        'credit' => $amount,
                        'narration' => $claim->narration,
                    ],
                ],
            );

            return $claim->fresh();
        });
    }

    /**
     * কোম্পানি দাবিটা মেনেছে — তাদের দেনার সাথে কাটাকাটি।
     *
     * কোনো টাকা নড়ে না; সরবরাহকারীর কাছে ডিপোর দেনা কমে, আর দাবির
     * খাত খালি হয়।
     */
    public function settle(CommissionClaim $claim, Carbon|string|null $onDate = null): CommissionClaim
    {
        $this->assertPending($claim);

        $date = $onDate ?? now()->toDateString();

        return DB::transaction(function () use ($claim, $date) {
            // ⛔ একই মুহূর্তে আরেকজন — তালার ভিতরে অবস্থা আবার ([[lockPending()]])
            $this->lockPending($claim);

            $this->posting->post(
                sourceType: CommissionClaim::STOCK_SOURCE.':settled',
                sourceId: $claim->id,
                trxDate: $date,
                documentNo: $claim->document_no,
                lines: [
                    [
                        'account_id' => StandardChart::find(StandardChart::PAYABLE)->id,
                        'party_type' => Supplier::drillSourceType(),
                        'party_id' => $claim->supplier_id,
                        'debit' => (string) $claim->amount,
                    ],
                    [
                        'account_id' => StandardChart::find(StandardChart::COMMISSION_CLAIM)->id,
                        'credit' => (string) $claim->amount,
                    ],
                ],
            );

            $claim->update([
                'status' => CommissionClaim::SETTLED,
                'decided_on' => $date,
                'decided_by' => auth()->id(),
            ]);

            return $claim->fresh();
        });
    }

    /**
     * কোম্পানি মানল না — দাবিটা এখন ডিপোর নিজের খরচ।
     *
     * ── কেন কারণ বাধ্যতামূলক ────────────────────────────────────────
     * একটা পাওনা খরচ হয়ে যাওয়া মানে টাকাটা আর কোনোদিন আসবে না। ছয় মাস
     * পরে "এই ৮,৪০০ টাকা কেন খরচ হলো" প্রশ্নের উত্তর কেবল এখানেই
     * থাকে — অডিটে কে লেখা থাকে, কেন থাকে না।
     */
    public function reject(CommissionClaim $claim, string $reason, Carbon|string|null $onDate = null): CommissionClaim
    {
        $this->assertPending($claim);

        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'decision_reason' => __('sales::validation.rejection_needs_reason'),
            ]);
        }

        $date = $onDate ?? now()->toDateString();

        return DB::transaction(function () use ($claim, $reason, $date) {
            // ⛔ একই মুহূর্তে আরেকজন — তালার ভিতরে অবস্থা আবার ([[lockPending()]])
            $this->lockPending($claim);

            $this->posting->post(
                sourceType: CommissionClaim::STOCK_SOURCE.':rejected',
                sourceId: $claim->id,
                trxDate: $date,
                documentNo: $claim->document_no,
                lines: [
                    [
                        'account_id' => StandardChart::find(StandardChart::COMMISSION_WRITTEN_OFF)->id,
                        'debit' => (string) $claim->amount,
                        'narration' => $reason,
                    ],
                    [
                        'account_id' => StandardChart::find(StandardChart::COMMISSION_CLAIM)->id,
                        'credit' => (string) $claim->amount,
                    ],
                ],
            );

            $claim->update([
                'status' => CommissionClaim::REJECTED,
                'decision_reason' => $reason,
                'decided_on' => $date,
                'decided_by' => auth()->id(),
            ]);

            return $claim->fresh();
        });
    }

    /**
     * ⛔ বিলটা এই ডিলারেরই, পাকা, আর তাতে আগে কোনো দাবি নেই — পুরো-ERP পুনঃঅডিট, ৯ অক্টোবর ২০২৬ (প্রমোশন ১৯;
     * [[ACommissionIsClaimedOnceOnTheRightBillTest]])।
     *
     * ⓘ বিল খোঁজা হত কেবল নম্বরে: অন্য গ্রাহকের বিল দেখিয়ে এই ডিলারের কমিশন লেখা যেত (ভিত্তি অঙ্কও সেই বিলের), আর একই বিলে বারবার
     * দাবি — প্রতিবার ডিলারের পাওনা কমত আর কোম্পানির কাছে আরেকটা দাবি জন্মাত। দাবি বিল ধরে, সারি ধরে নয়; তাই এক বিলে একটাই।
     * নামঞ্জুর দাবিও গোনা হয় — খরচ একবার বসে গেছে, একই বিলে আবার দাবি মানে আবার খরচ।
     */
    private function assertTheBillIsTheirs(?SalesInvoice $invoice, Customer $customer): void
    {
        if ($invoice === null) {
            return;
        }

        if ((int) $invoice->customer_id !== (int) $customer->id || ! in_array($invoice->status, DocumentStatus::POSTED, true)) {
            throw ValidationException::withMessages([
                'sales_invoice_id' => __('sales::validation.commission_bill_not_theirs', ['no' => $invoice->document_no]),
            ]);
        }

        $this->assertNoClaimOnTheBill($invoice);
    }

    private function assertNoClaimOnTheBill(SalesInvoice $invoice): void
    {
        $taken = CommissionClaim::query()->where('sales_invoice_id', $invoice->id)->value('document_no');

        if ($taken !== null) {
            throw ValidationException::withMessages([
                'sales_invoice_id' => __('sales::validation.commission_bill_claimed', ['no' => $invoice->document_no, 'claim' => $taken]),
            ]);
        }
    }

    /**
     * শতাংশ কিসের উপর বসবে।
     *
     * বিলের সাথে জোড়া থাকলে বিলের অঙ্কই ভিত্তি; নগদে দিলে ব্যবহারকারী
     * নিজে বলে দেন। ভিত্তিটা জমা থাকে, কারণ ছয় মাস পরে কেউ যেন বলতে
     * পারে অঙ্কটা কীভাবে এসেছিল।
     *
     * @param  array<string, mixed>  $data
     */
    private function baseFor(array $data, ?SalesInvoice $invoice): string
    {
        if ($invoice !== null) {
            return (string) $invoice->total;
        }

        return Money::round($data['base_amount'] ?? '0', 4);
    }

    /**
     * টাকার অঙ্কটা — হার থেকে, নয়তো সরাসরি।
     *
     * দুইটাই দিলে **থোক অঙ্কটাই চলে**: মানুষ যখন একটা নির্দিষ্ট টাকা
     * লেখেন তখন সেটাই তাঁর সিদ্ধান্ত, আর শতাংশটা কেবল কীভাবে হিসাব
     * হয়েছিল তার স্মৃতি।
     *
     * @param  array<string, mixed>  $data
     */
    private function amountFor(array $data, string $base): string
    {
        $flat = $data['rate_amount'] ?? null;

        if (filled($flat) && bccomp(Money::round($flat, 4), '0', 4) > 0) {
            return Money::round($flat, 4);
        }

        $percent = Money::round($data['rate_percent'] ?? '0', 4);

        if (bccomp($percent, '0', 4) <= 0 || bccomp($base, '0', 4) <= 0) {
            throw ValidationException::withMessages([
                'amount' => __('sales::validation.commission_needs_a_figure'),
            ]);
        }

        return Money::round(bcdiv(bcmul($base, $percent, 6), '100', 6), 4);
    }

    /**
     * দুইটা সীমা, আর দুইটাই লাগে।
     *
     * ── কেন শতাংশের সীমা একা যথেষ্ট নয় ─────────────────────────────
     * শতাংশ মাত্র ২% হলেও অঙ্কটা ৫ লাখ হতে পারে — তখন শতাংশের সীমা
     * কিছুই ধরত না। আবার একটা ৫০০ টাকার বিলে ৫০% কমিশন অঙ্কে ছোট,
     * কিন্তু হারটাই প্রশ্ন তোলে।
     *
     * ── সীমা মানে নিষেধ নয় ─────────────────────────────────────────
     * ৫০% কমিশনও বৈধ — শুধু কাউকে দেখে সই করতে হবে। তাই সীমা ছাড়ালে
     * এখানে **আটকায়**, আর অনুমোদন নিয়ে আবার চেষ্টা করতে হয়। শূন্য
     * সীমা মানে "সবসময় অনুমোদন" — আলাদা সুইচ লাগে না, শূন্যই সেটা
     * বলে।
     */
    private function assertWithinLimits(string $amount, string $base): void
    {
        if (auth()->user()?->can('sales.commission.override') === true) {
            return;
        }

        $maxAmount = (string) ($this->settings->get('sales.commission_max_amount') ?? '0');

        if (bccomp($maxAmount, '0', 4) > 0 && bccomp($amount, $maxAmount, 4) > 0) {
            throw ValidationException::withMessages([
                'amount' => __('sales::validation.commission_over_amount', [
                    'limit' => Money::format($maxAmount),
                ]),
            ]);
        }

        $maxPercent = (string) ($this->settings->get('sales.commission_max_percent') ?? '0');

        if (bccomp($maxPercent, '0', 4) <= 0 || bccomp($base, '0', 4) <= 0) {
            return;
        }

        $percent = bcdiv(bcmul($amount, '100', 6), $base, 6);

        if (bccomp($percent, $maxPercent, 4) > 0) {
            throw ValidationException::withMessages([
                'amount' => __('sales::validation.commission_over_percent', [
                    'limit' => rtrim(rtrim($maxPercent, '0'), '.'),
                ]),
            ]);
        }
    }

    /**
     * ⛔ একই দাবি একসাথে মানা আর নাকচ — চূড়ান্ত অডিট (abos-8f-এর পড়া), ৩০ সেপ্টেম্বর ২০২৬।
     *
     * ⓘ "অপেক্ষমাণ কি না" দেখা হত লেনদেনের বাইরে, আর দুই পথের খাতার চাবি আলাদা (`:settled` / `:rejected`) —
     * তাই পোস্টিং ইঞ্জিনও ধরত না: দুইটাই বসত, দাবির খাতে দ্বিগুণ ক্রেডিট। ⭐ এখন লেনদেনের প্রথম কাজ সারিতে
     * তালা আর অবস্থা আবার ([[DepositClaimService::lockPending()]]-এর ছাঁচ)।
     */
    private function lockPending(CommissionClaim $claim): void
    {
        $fresh = CommissionClaim::query()
            ->withoutGlobalScopes()
            ->whereKey($claim->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        $claim->setRawAttributes($fresh->getAttributes(), true);

        $this->assertPending($claim);
    }

    private function assertPending(CommissionClaim $claim): void
    {
        if (! $claim->isPending()) {
            throw ValidationException::withMessages([
                'status' => __('sales::validation.commission_already_decided', [
                    'no' => $claim->document_no,
                ]),
            ]);
        }
    }
}
