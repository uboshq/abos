<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Engines\Approval\DocumentApproval;
use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\FinancialYear;
use App\Models\IssuedNumber;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\ReadsPackedQuantities;
use App\Modules\Sales\Models\PricingRule;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesQuotation;
use App\Modules\Sales\Models\SalesQuotationLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * বিক্রয় উদ্ধৃতি — দর বলা, সই নেওয়া, ডিলারের উত্তর, আর আদেশে রূপান্তর।
 *
 * ── ধাপগুলো ──────────────────────────────────────────────────────────
 *   খসড়া → জমা (সইয়ের অপেক্ষায়) → অনুমোদিত → পাঠানো → গৃহীত → আদেশ
 *                                                     ↘ প্রত্যাখ্যাত
 *   যেকোনো খোলা ধাপ → বাতিল · মেয়াদ পেরোলে "মেয়াদোত্তীর্ণ" (পড়ার সময় গোনা)
 *
 * ── ⓘ যা এখানে নেই, আর ইচ্ছা করেই ──────────────────────────────────────
 * ⛔ **বাকির সীমা দেখা হয় না।** মালিকের সিদ্ধান্ত, ২৬ সেপ্টেম্বর ২০২৬:
 * দেয়ালটা চালানে আর বিলে (*"DO/delivery order theke suro hobe"*)। দর
 * বলা মানে মাল দেওয়া নয় — এখানে আটকালে ডিলারকে দামই বলা যেত না।
 *
 * ⓘ মজুদও ধরা হয় না: আদেশ নিশ্চিত হলে তবেই মাল ধরা পড়ে
 * ([[SalesOrderService::confirm()]]) — রূপান্তরে আদেশ খসড়া হয়েই জন্মায়,
 * আর বাকি সব (মজুদ, অনুমোদন) আদেশের নিজের পথে চলে।
 */
final class SalesQuotationService
{
    use CalculatesSalesLines;
    use ReadsPackedQuantities;

    /** ⓘ ফর্মে তারিখ না দিলে — সেটিং না বসালে পনেরো দিন */
    public const DEFAULT_VALID_DAYS = 15;

    public const VALID_DAYS_SETTING = 'sales.quotation_valid_days';

    public function __construct(
        private readonly NumberSeriesEngine $numbers,
        private readonly DocumentApproval $approvals,
        private readonly SalesOrderService $orders,
        private readonly SettingsService $settings,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $lines
     */
    public function create(array $data, array $lines): SalesQuotation
    {
        $this->assertHasLines($lines);

        return DB::transaction(function () use ($data, $lines) {
            $trxDate = Carbon::parse($data['trx_date'] ?? now());
            $year = $this->resolveFinancialYear($trxDate);

            $documentNo = $this->numbers->next('QTN');

            $quotation = SalesQuotation::create([
                'company_id' => CompanyContext::id(),
                'branch_id' => $data['branch_id'] ?? CompanyContext::branchId(),
                'financial_year_id' => $year->id,
                'document_no' => $documentNo,
                'customer_id' => $data['customer_id'],
                'trx_date' => $trxDate->toDateString(),
                'valid_until' => $this->validUntil($data, $trxDate),
                'price_list_id' => $data['price_list_id'] ?? null,
                'payment_term_id' => $data['payment_term_id'] ?? null,
                'delivery_terms' => $data['delivery_terms'] ?? null,
                'narration' => $data['narration'] ?? null,
                'status' => SalesQuotation::DRAFT,
                'created_by' => auth()->id(),
            ]);

            $this->replaceLines($quotation, $lines, (string) ($data['header_discount'] ?? '0'));

            IssuedNumber::query()
                ->where('document_no', $documentNo)
                ->whereNull('source_id')
                ->update([
                    'source_type' => SalesQuotation::drillSourceType(),
                    'source_id' => $quotation->id,
                ]);

            return $quotation->fresh(['lines']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $lines
     */
    public function update(SalesQuotation $quotation, array $data, array $lines): SalesQuotation
    {
        $this->assertIn($quotation, [SalesQuotation::DRAFT], 'only_draft_edits');
        $this->assertHasLines($lines);

        return DB::transaction(function () use ($quotation, $data, $lines) {
            $trxDate = Carbon::parse($data['trx_date'] ?? $quotation->trx_date);

            $quotation->update([
                'customer_id' => $data['customer_id'] ?? $quotation->customer_id,
                'trx_date' => $trxDate->toDateString(),
                'valid_until' => $this->validUntil($data, $trxDate),
                'price_list_id' => $data['price_list_id'] ?? null,
                'payment_term_id' => $data['payment_term_id'] ?? null,
                'delivery_terms' => $data['delivery_terms'] ?? null,
                'narration' => $data['narration'] ?? null,
                'financial_year_id' => $this->resolveFinancialYear($trxDate)->id,
            ]);

            $this->replaceLines($quotation, $lines, (string) ($data['header_discount'] ?? '0'));

            return $quotation->fresh(['lines']);
        });
    }

    /**
     * জমা — তারপর সইয়ের প্রশ্ন।
     *
     * ⚠️ "জমা" অবস্থাটা আগে বসে, সইয়ের প্রশ্ন পরে — আলাদা করে। ছক বসানো
     * থাকলে [[approve()]] `HeldForApproval` ছোঁড়ে; একই ট্রানজেকশনে থাকলে
     * সেটা "জমা"-টাও ফিরিয়ে নিত, আর কাগজটা খসড়াই থেকে যেত অথচ সইয়ের
     * অনুরোধ চলে যেত।
     */
    public function submit(SalesQuotation $quotation): SalesQuotation
    {
        $this->assertIn($quotation, [SalesQuotation::DRAFT], 'quotation_not_draft');
        $this->assertNotExpired($quotation);

        $quotation->loadMissing('lines');

        if ($quotation->lines->isEmpty()) {
            throw ValidationException::withMessages(['lines' => __('sales::validation.no_lines')]);
        }

        $quotation->update([
            'status' => SalesQuotation::SUBMITTED,
            'submitted_at' => now(),
        ]);

        return $this->approve($quotation->fresh());
    }

    /**
     * অনুমোদন — অনুমোদনের ছক যা বলে।
     *
     * ⭐ মালিকের নিয়ম: *অনুমোদন মানে একজন মানুষ*। ছক বসানো থাকলে সই না
     * আসা পর্যন্ত কাগজটা "জমা"-তেই থাকে, আর সই আসার পর এই বোতামটাই
     * আবার চাপতে হয় ([[DocumentApproval::stopping()]]-এ কারণ লেখা)।
     *
     * ⓘ ছক না থাকলে `assertClear()` চুপচাপ ফেরে — বিক্রয় আদেশের মতোই
     * ([[SalesOrderService::confirm()]]): যে কোম্পানি সই চায় না, তার কাজ
     * থামে না।
     */
    public function approve(SalesQuotation $quotation): SalesQuotation
    {
        $this->assertIn($quotation, [SalesQuotation::SUBMITTED], 'quotation_not_submitted');
        $this->assertNotExpired($quotation);

        // ⚠️ সবসময় একই সম্পর্ক তুলে — ছাপটা ([[DocumentFingerprint]]) তোলা সম্পর্কও ধরে
        $quotation->load('lines');

        $this->approvals->assertClear(
            document: $quotation,
            module: 'sales',
            action: SalesQuotation::APPROVAL_ACTION,
            field: 'status',
            amount: (string) $quotation->total,
            reason: $quotation->narration,
        );

        $quotation->update([
            'status' => SalesQuotation::APPROVED,
            'approved_at' => now(),
        ]);

        return $quotation->fresh(['lines']);
    }

    /** ডিলারের হাতে গেছে — ছাপা বা পাঠানো, যেভাবেই হোক। */
    public function markSent(SalesQuotation $quotation): SalesQuotation
    {
        $this->assertIn($quotation, [SalesQuotation::APPROVED], 'quotation_not_approved');
        $this->assertNotExpired($quotation);

        $quotation->update(['status' => SalesQuotation::SENT, 'sent_at' => now()]);

        return $quotation->fresh(['lines']);
    }

    /**
     * ডিলার রাজি।
     *
     * ⛔ মেয়াদ পেরোনো দরে "রাজি" নেওয়া যায় না — দরটা তখন আর প্রস্তাবই নয়।
     */
    public function accept(SalesQuotation $quotation, ?string $note = null): SalesQuotation
    {
        $this->assertIn($quotation, [SalesQuotation::APPROVED, SalesQuotation::SENT], 'quotation_not_open');
        $this->assertNotExpired($quotation);

        $quotation->update([
            'status' => SalesQuotation::ACCEPTED,
            'answered_at' => now(),
            'answer_note' => $note,
        ]);

        return $quotation->fresh(['lines']);
    }

    /** ডিলার রাজি নন — কারণসহ, কারণ পরের দরটা ওই কারণ থেকেই আসে। */
    public function reject(SalesQuotation $quotation, string $note): SalesQuotation
    {
        $this->assertIn($quotation, [SalesQuotation::APPROVED, SalesQuotation::SENT], 'quotation_not_open');

        $quotation->update([
            'status' => SalesQuotation::REJECTED,
            'answered_at' => now(),
            'answer_note' => $note,
        ]);

        return $quotation->fresh(['lines']);
    }

    /**
     * বদল — ডিলার দেখার আগে "আবার খসড়ায়", দেখার পরে নতুন সংস্করণ।
     *
     * ── ⓘ ডিলার দেখার আগে (জমা, অনুমোদিত) ──────────────────────────────
     * কাগজটা এখনো ঘরের ভিতরে, তাই একই নম্বরে খসড়ায় ফেরে। ⚠️ আগের সই আর খাটে না: আবার জমা দিলে
     * `submitted_at` বদলায়, ছাপ বদলায়, আর নতুন সই লাগে।
     *
     * ── ⭐ ডিলার দেখার পরে (পাঠানো, গৃহীত, প্রত্যাখ্যাত) — নতুন সংস্করণ ─────
     * মালিক, ৪ অক্টোবর ২০২৬ (*"অবশ্যই ইন্টারন্যাশনাল স্ট্যান্ডার্ড"*): ডিলারের হাতে যাওয়া দর মোছা হয় না।
     * একই মূল নম্বরে শেষে `-R১, -R২ …` দিয়ে নতুন খসড়া জন্মায় — সারি, দর, ছাড়, শর্ত হুবহু; আজকের তারিখ আর
     * কোম্পানির সাধারণ মেয়াদ ([[defaultValidDays()]])। পুরনোটা "নতুন সংস্করণে বদলেছে" — কেবল পড়ার জন্য।
     *
     * ⭐ মেয়াদ পেরোনো উদ্ধৃতিকে আদেশে নেওয়ার একমাত্র পথ এটাই: নতুন সংস্করণ, নতুন মেয়াদ, নতুন করে জমা।
     *
     * ── ⛔ দুইবার নয় ─────────────────────────────────────────────────────
     * সারিটা তালায় পড়া হয়, অবস্থা তালার **ভিতরে** দেখা — দুই ক্লিকের দ্বিতীয়টা পুরনো সংস্করণ পায় আর থামে;
     * (মূল, সংস্করণ) ইউনিক বলে ডাটাবেসও আটকায়। আদেশ হয়ে যাওয়া উদ্ধৃতি বদলায় না — আদেশটা তখনো বেঁচে।
     *
     * @return SalesQuotation যে কাগজে এখন কাজ চলবে — একই, বা নতুন সংস্করণ
     */
    public function revise(SalesQuotation $quotation): SalesQuotation
    {
        return DB::transaction(function () use ($quotation) {
            /** @var SalesQuotation $locked */
            $locked = SalesQuotation::query()->whereKey($quotation->getKey())->lockForUpdate()->firstOrFail();

            $this->assertIn($locked, [
                SalesQuotation::SUBMITTED, SalesQuotation::APPROVED,
                SalesQuotation::SENT, SalesQuotation::ACCEPTED, SalesQuotation::REJECTED,
            ], 'quotation_not_revisable');

            if ($locked->revisesInPlace()) {
                $locked->update([
                    'status' => SalesQuotation::DRAFT,
                    'submitted_at' => null,
                    'approved_at' => null,
                    'sent_at' => null,
                    'answered_at' => null,
                    'answer_note' => null,
                ]);

                return $locked->fresh(['lines']);
            }

            return $this->newRevision($locked);
        });
    }

    /**
     * পুরনো সংস্করণ থেকে নতুনটা — একই সারি ও দর, নতুন তারিখ ও মেয়াদ।
     *
     * ⓘ সারিগুলো হুবহু নকল (`replicate`), দামের নীতি আবার চালানো হয় না: নতুন সংস্করণ মানে "আগেরটা থেকে
     * শুরু", আর বদলানো দর সম্পাদনায় ([[update()]]) বসার সময় নীতি দেখা হয়ই। ⚠️ মোটও হুবহু — নাহলে বদলের আগেই
     * তুলনার পর্দা "মোট বদলেছে" বলত।
     */
    private function newRevision(SalesQuotation $old): SalesQuotation
    {
        $old->load('lines');

        $rootId = $old->rootId();
        $root = $rootId === (int) $old->getKey() ? $old : SalesQuotation::query()->findOrFail($rootId);

        $next = (int) SalesQuotation::query()
            ->where(fn ($q) => $q->whereKey($rootId)->orWhere('root_quotation_id', $rootId))
            ->max('revision_no') + 1;

        $today = Carbon::today();

        $revision = SalesQuotation::create([
            'company_id' => $old->company_id,
            'branch_id' => $old->branch_id,
            'financial_year_id' => $this->resolveFinancialYear($today)->id,
            'document_no' => $root->document_no.'-R'.$next,
            'root_quotation_id' => $rootId,
            'revision_no' => $next,
            'revised_from_id' => $old->id,
            'customer_id' => $old->customer_id,
            'trx_date' => $today->toDateString(),
            'valid_until' => $today->copy()->addDays($this->defaultValidDays())->toDateString(),
            'price_list_id' => $old->price_list_id,
            'payment_term_id' => $old->payment_term_id,
            'delivery_terms' => $old->delivery_terms,
            'subtotal' => $old->subtotal,
            'discount' => $old->discount,
            'header_discount' => $old->header_discount,
            'tax' => $old->tax,
            'total' => $old->total,
            'narration' => $old->narration,
            'status' => SalesQuotation::DRAFT,
            'created_by' => auth()->id(),
        ]);

        foreach ($old->lines as $line) {
            $copy = $line->replicate(['public_id']);
            $copy->sales_quotation_id = $revision->id;
            $copy->save();
        }

        $old->update([
            'status' => SalesQuotation::REVISED,
            'superseded_at' => now(),
            'superseded_by' => auth()->id(),
        ]);

        return $revision->fresh(['lines']);
    }

    /**
     * বাতিল — আদেশ হয়ে যাওয়া উদ্ধৃতি বাদে।
     *
     * ⛔ রূপান্তরিত উদ্ধৃতি বাতিল করা যায় না: আদেশটা তখনো বেঁচে, আর
     * বাতিল করতে হলে আদেশটাই বাতিল করতে হয়।
     */
    public function cancel(SalesQuotation $quotation, string $reason): SalesQuotation
    {
        if ($quotation->status === SalesQuotation::CANCELLED) {
            throw ValidationException::withMessages([
                'status' => __('sales::validation.already_cancelled', ['no' => $quotation->document_no]),
            ]);
        }

        $this->assertIn($quotation, [...SalesQuotation::EXPIRABLE, SalesQuotation::REJECTED], 'quotation_converted');

        $quotation->update([
            'status' => SalesQuotation::CANCELLED,
            'cancelled_by' => auth()->id(),
            'cancelled_at' => now(),
            'cancel_reason' => $reason,
        ]);

        return $quotation->fresh(['lines']);
    }

    /**
     * গৃহীত উদ্ধৃতি → বিক্রয় আদেশ, একই সারি ও একই দরে।
     *
     * ── ⛔ দুইবার নয় ───────────────────────────────────────────────────
     * দুইটা ক্লিক (বা দুইটা ট্যাব) একসাথে এলে দুইটা আদেশ হত, আর মাল দুইবার
     * যেত। তাই তিনটা পাহারা: সারিটা তালায় পড়া হয় (`lockForUpdate`), অবস্থা
     * তালার **ভিতরে** দেখা হয়, আর `sal_orders.sales_quotation_id` ইউনিক —
     * শেষেরটা ডাটাবেসের নিজের।
     *
     * ── ⭐ মোট মিলতেই হবে ──────────────────────────────────────────────
     * আদেশ নিজের নিয়মে ([[CalculatesSalesLines]]) আবার গোনে। মিল না হলে
     * (যেমন মাঝে পণ্যের ভ্যাটের হার বদলেছে) রূপান্তর **থামে** — ডিলারকে এক
     * দর বলে আদেশে আরেক দর বসানো মানে দরকষাকষির পুরো কাজটাই নষ্ট।
     */
    public function convert(SalesQuotation $quotation): SalesOrder
    {
        return DB::transaction(function () use ($quotation) {
            /** @var SalesQuotation $locked */
            $locked = SalesQuotation::query()->whereKey($quotation->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === SalesQuotation::CONVERTED || $locked->sales_order_id !== null) {
                throw ValidationException::withMessages([
                    'status' => __('sales::quotation.error.already_converted', ['no' => $locked->document_no]),
                ]);
            }

            $this->assertIn($locked, [SalesQuotation::ACCEPTED], 'quotation_not_accepted');
            $this->assertNotExpired($locked);

            $locked->load('lines.product');

            $order = $this->orders->create(
                [
                    'customer_id' => $locked->customer_id,
                    'trx_date' => now()->toDateString(),
                    'narration' => $locked->narration,
                ],
                $locked->lines->map(fn (SalesQuotationLine $line) => [
                    'product_id' => $line->product_id,
                    'ordered_qty' => (string) $line->qty,
                    'rate' => (string) $line->rate,
                    'discount' => $line->fullDiscount(),
                    'tax' => $this->taxToCarry($line),
                    'narration' => $line->narration,
                ])->values()->all(),
            );

            if (bccomp((string) $order->total, (string) $locked->total, 4) !== 0) {
                throw ValidationException::withMessages([
                    'status' => __('sales::quotation.error.total_moved', [
                        'quoted' => (string) $locked->total,
                        'now' => (string) $order->total,
                    ]),
                ]);
            }

            /*
             * ⓘ প্যাকের লেখাটা আদেশেও যায় — "২ বাক্স" "২৪ পিস" হয়ে না যায়।
             * আদেশের সেবা পণ্যের এককে গোনে ([[ReadsPackedQuantities]]),
             * তাই ঘর দুইটা পরে বসানো হয়; অঙ্কে কিছু বদলায় না।
             */
            $byLine = $locked->lines->keyBy('line_no');

            foreach ($order->lines as $orderLine) {
                $source = $byLine->get($orderLine->line_no);

                if ($source !== null && $source->entered_unit_id !== null) {
                    $orderLine->update([
                        'entered_qty' => $source->entered_qty,
                        'entered_unit_id' => $source->entered_unit_id,
                    ]);
                }
            }

            // ⓘ `forceFill` — ঘরটা আদেশের fillable-এ নেই, আর থাকা উচিতও নয়:
            // ফর্ম থেকে কেউ আদেশকে অন্য উদ্ধৃতির সাথে জুড়তে পারবেন না
            $order->forceFill(['sales_quotation_id' => $locked->id])->save();

            $locked->update([
                'status' => SalesQuotation::CONVERTED,
                'sales_order_id' => $order->id,
                'converted_at' => now(),
                'converted_by' => auth()->id(),
            ]);

            return $order->fresh(['lines']);
        });
    }

    /**
     * আদেশে ভ্যাটের অঙ্কটা কীভাবে যাবে।
     *
     * ⚠️ দামের **ভেতরের** ভ্যাট হাতে পাঠালে [[CalculatesSalesLines::lineFigures()]]
     * সেটাকে বাইরের ধরে মোটে আবার যোগ করত — দুইবার ভ্যাট। তাই ভেতরের
     * ভ্যাটের সারিতে ঘরটা খালি যায়, আর আদেশ পণ্যের হার থেকে একই অঙ্ক গোনে।
     * ⓘ ভেতরের কি না বোঝা যায় সারি থেকেই: মোট = ছাড়ের পরের টাকা, অথচ ভ্যাট আছে।
     */
    private function taxToCarry(SalesQuotationLine $line): ?string
    {
        $net = bcsub(bcmul((string) $line->qty, (string) $line->rate, 4), $line->fullDiscount(), 4);

        $inclusive = bccomp((string) $line->tax, '0', 4) > 0
            && bccomp((string) $line->amount, $net, 4) === 0;

        return $inclusive ? null : (string) $line->tax;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function replaceLines(SalesQuotation $quotation, array $lines, string $headerDiscount): void
    {
        $quotation->lines()->delete();

        $headerDiscount = $this->money($headerDiscount);

        /*
         * দামের নীতি — বিলের **একই** নিয়ম ([[SalesInvoiceService]])।
         *
         * ⛔ এখানে না দেখলে ডিলারকে এমন দর বলা যেত যা বিলের দিন আটকে যেত
         * — প্রতিশ্রুতি দিয়ে তারপর "দেওয়া যাবে না" বলা।
         */
        $pricing = PricingRule::current();

        // ── প্রথম পাক: সারিগুলো পড়া, আর ছাড়ের আগের টাকা ─────────────────
        $rows = [];
        $netSum = '0';

        foreach ($lines as $line) {
            $productId = (int) ($line['product_id'] ?? 0);
            $qty = $this->positive($line['qty'] ?? null, 'qty');
            $rate = $this->money($line['rate'] ?? null);

            $product = Product::query()->find($productId);

            if ($productId <= 0 || $product === null) {
                throw ValidationException::withMessages(['lines' => __('sales::validation.unknown_product')]);
            }

            $pack = $this->packed($product, $qty, $line['unit_id'] ?? null, $rate);

            if ($pricing->verdictOn($pack['rate'], (string) ($product->sale_price ?? '0')) === PricingRule::BLOCK) {
                throw ValidationException::withMessages([
                    'lines' => __('sales::validation.price_out_of_range', [
                        'product' => $product->name(),
                        'tolerance' => rtrim(rtrim(bcadd((string) $pricing->tolerance, '0', 4), '0'), '.'), // ⓘ আগে দশমিক বসিয়ে কাটা: "0" কাটলে কিছুই থাকত না, "%" একা থাকত (মালিকের ছবি, ৩ অক্টোবর ২০২৬); float নয়
                    ]),
                ]);
            }

            $discount = $this->money($line['discount'] ?? '0');
            $base = bcmul($pack['qty'], $pack['rate'], 4);

            if (bccomp($discount, $base, 4) > 0) {
                throw ValidationException::withMessages(['lines' => __('sales::validation.discount_over_line')]);
            }

            $net = bcsub($base, $discount, 4);
            $netSum = bcadd($netSum, $net, 4);

            $rows[] = compact('product', 'pack', 'discount', 'net') + ['line' => $line];
        }

        // ── পুরো কাগজের ছাড় — সারির টাকার অনুপাতে ভাগ ─────────────────────
        if (bccomp($headerDiscount, $netSum, 4) > 0) {
            throw ValidationException::withMessages([
                'header_discount' => __('sales::quotation.error.header_discount_over_total'),
            ]);
        }

        $shares = $this->shareOut($headerDiscount, array_column($rows, 'net'));

        // ── দ্বিতীয় পাক: লেখা ─────────────────────────────────────────────
        $totals = ['subtotal' => '0', 'discount' => '0', 'tax' => '0', 'total' => '0'];
        $lineNo = 0;

        foreach ($rows as $i => $row) {
            $line = $row['line'];

            $figures = $this->lineFigures(
                $row['pack']['qty'],
                $row['pack']['rate'],
                bcadd($row['discount'], $shares[$i], 4),
                $line['tax'] ?? null,
                $row['product']->tax,
            );

            SalesQuotationLine::create([
                'sales_quotation_id' => $quotation->id,
                'product_id' => $row['product']->id,
                'qty' => $row['pack']['qty'],
                'entered_qty' => $row['pack']['entered_qty'],
                'entered_unit_id' => $row['pack']['entered_unit_id'],
                'rate' => $row['pack']['rate'],
                'discount' => $row['discount'],
                'header_share' => $shares[$i],
                'tax' => $figures['tax'],
                'tax_variance' => $figures['tax_variance'],
                'amount' => $figures['amount'],
                'line_no' => ++$lineNo,
                'narration' => $line['narration'] ?? null,
            ]);

            $totals = $this->addToTotals($totals, $figures);
        }

        $quotation->update($totals + ['header_discount' => $headerDiscount]);
    }

    /**
     * একটা অঙ্ককে কয়েকটা ভাগে — অনুপাতে, পয়সা না হারিয়ে।
     *
     * ⓘ প্রতিটা ভাগ নিচে কাটা হয় (bcdiv), আর যা বাকি থাকে তা সবচেয়ে বড়
     * সারিতে যায়। ⚠️ শেষ সারিতে দিলে ছোট একটা শেষ সারির ছাড় তার নিজের
     * টাকা ছাড়িয়ে যেতে পারত; বড় সারিতে সেই ভয় নেই।
     *
     * @param  list<string>  $weights
     * @return list<string>
     */
    private function shareOut(string $amount, array $weights): array
    {
        $total = array_reduce($weights, fn (string $c, string $w) => bcadd($c, $w, 4), '0');

        if (bccomp($amount, '0', 4) === 0 || bccomp($total, '0', 4) === 0) {
            return array_fill(0, count($weights), '0.0000');
        }

        $shares = [];
        $given = '0';
        $largest = 0;

        foreach ($weights as $i => $weight) {
            $shares[$i] = bcdiv(bcmul($amount, $weight, 8), $total, 4);
            $given = bcadd($given, $shares[$i], 4);

            if (bccomp($weight, $weights[$largest], 4) > 0) {
                $largest = $i;
            }
        }

        $shares[$largest] = bcadd($shares[$largest], bcsub($amount, $given, 4), 4);

        return $shares;
    }

    /**
     * মেয়াদ — না দিলে কোম্পানির সাধারণ দিনসংখ্যা।
     *
     * @param  array<string, mixed>  $data
     */
    private function validUntil(array $data, Carbon $trxDate): string
    {
        if (filled($data['valid_until'] ?? null)) {
            $until = Carbon::parse($data['valid_until']);

            if ($until->lt($trxDate)) {
                throw ValidationException::withMessages([
                    'valid_until' => __('sales::quotation.error.valid_before_date'),
                ]);
            }

            return $until->toDateString();
        }

        return $trxDate->copy()->addDays($this->defaultValidDays())->toDateString();
    }

    /**
     * কত দিনের মেয়াদ সাধারণত — প্রতিটা কোম্পানি নিজে বসায়।
     *
     * ⓘ এক ডিপোর দর সপ্তাহে বদলায়, আরেকটার মাসে — তাই সংখ্যাটা সেটিংসে।
     */
    public function defaultValidDays(): int
    {
        $days = (int) $this->settings->get(self::VALID_DAYS_SETTING, self::DEFAULT_VALID_DAYS);

        return $days > 0 ? $days : self::DEFAULT_VALID_DAYS;
    }

    /**
     * @param  list<string>  $allowed
     */
    private function assertIn(SalesQuotation $quotation, array $allowed, string $message): void
    {
        // ⛔ পুরনো সংস্করণ কেবল পড়ার জন্য — বার্তাটা বলে কেন, আর কাজ কোন কাগজে চলবে
        if ($quotation->isSuperseded() && ! in_array(SalesQuotation::REVISED, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => __('sales::quotation.error.superseded', ['no' => $quotation->document_no]),
            ]);
        }

        if (! in_array($quotation->status, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => __('sales::quotation.error.'.$message, ['no' => $quotation->document_no]),
            ]);
        }
    }

    private function assertNotExpired(SalesQuotation $quotation): void
    {
        if ($quotation->isExpired()) {
            throw ValidationException::withMessages([
                'status' => __('sales::quotation.error.expired', [
                    'no' => $quotation->document_no,
                    'date' => $quotation->valid_until?->toDateString(),
                ]),
            ]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function assertHasLines(array $lines): void
    {
        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => __('sales::validation.no_lines')]);
        }
    }

    private function resolveFinancialYear(Carbon $date): FinancialYear
    {
        $year = FinancialYear::query()
            ->whereDate('starts_on', '<=', $date->toDateString())
            ->whereDate('ends_on', '>=', $date->toDateString())
            ->first();

        if ($year === null) {
            throw ValidationException::withMessages([
                'trx_date' => __('sales::validation.no_financial_year', ['date' => $date->toDateString()]),
            ]);
        }

        return $year;
    }
}
