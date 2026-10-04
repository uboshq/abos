<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Support\CompanyContext;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\CashTill;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\FreeRatio;
use App\Modules\MasterData\Models\PaymentMethod;
use App\Modules\MasterData\Models\PaymentTerm;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Supplier\Models\Supplier;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ কাউন্টারের বাছাইয়ের তালিকা — লট, টাকার খাত, শর্ত; ওয়েবের কাউন্টার আর ফোনের কাউন্টার একই জায়গা থেকে পড়ে
 * (৪ অক্টোবর ২০২৬, মালিক: *"direct sales er counter banaw app e"*)।
 *
 * ⓘ দেহগুলো [[DirectSaleController]] থেকে হুবহু কেটে আনা — হাতে লেখা নয়। ⛔ দুই কাউন্টারে দুই তালিকা হলে একদিন
 * ফোন এমন লট দেখাত যা সেবা নিত না, বা অন্যের নগদ বাক্স।
 */
final class DirectSaleOptions
{
    /**
     * টাকার খাত — নগদ, ব্যাংক, মোবাইল মানি; অন্য শাখার টিল আর অন্যের নগদ বাক্স বাদ।
     *
     * @return list<array{id: string, label: string, parent: string}>
     */
    public function moneyAccounts(): array
    {
        return Account::query()->notAnotherBranchsTill()
            ->where('is_group', false)
            ->whereIn('parent_id', Account::query()
                ->whereIn('code', StandardChart::MONEY_PARENTS)->select('id'))
            ->orderBy('code')
            ->with('parent:id,code')
            ->get(['id', 'parent_id', 'code', 'name_en', 'name_bn', 'money_kind'])
                /*
                 * ⛔ অন্যের নগদ বাক্স তালিকায় নয় — মালিকের নির্দেশ, ২৮ সেপ্টেম্বর ২০২৬: *"ekjoner
                 * cash account e r ekjon taka nite parbe na r ta onno joner idte show korbe na"*।
                 * ⓘ রসিদ ভাউচারের একই নিয়ম ([[CashTill::mayUse()]], [[DepositFormOptions]]); পোস্টের
                 * সময় সার্ভারও আটকায় ([[VoucherService::assertCashLandsInOwnTill()]])।
                 */
            ->filter(fn (Account $a) => ! $a->isCash() || CashTill::mayUse(auth()->id(), (int) $a->id))
            ->map(fn (Account $a): array => [
                'id' => (string) $a->id,
                'label' => $a->code.' · '.$a->name(),
                /* কোন মায়ের সন্তান — ছাঁকনিটা এটাই দেখে।
                       ১১০১ নগদ · ১১০২ ব্যাংক · ১১০৫ মোবাইল মানি */
                'parent' => (string) ($a->parent?->code ?? ''),
            ])
            ->values()
            ->all();
    }

    public function lots(?Warehouse $warehouse): array
    {
        if ($warehouse === null) {
            return [];
        }

        $balances = DB::table('inv_stock_movements')
            ->selectRaw('batch_id, COALESCE(SUM(floor_change), 0) as qty')
            ->where('company_id', CompanyContext::id())
            ->where('warehouse_id', $warehouse->id)
            ->whereNotNull('batch_id')
            ->groupBy('batch_id')
            ->pluck('qty', 'batch_id');

        $batches = Batch::query()
            ->whereIn('product_id', Product::query()->active()->where('track_batch', true)->select('id'))
            ->unexpired()
            ->fefo()
            ->get();

        // ⭐ লটের ফ্রি অনুপাত — লটের ঘরেই দেখায় (মালিক, ৪ অক্টোবর ২০২৬); সব লট একটাই প্রশ্নে
        $came = app(FreeRatio::class)->arrivedInMany($batches->pluck('id')->all());

        return $batches
            ->map(fn (Batch $b) => [
                'id' => (string) $b->id,
                'productId' => (string) $b->product_id,
                'no' => (string) $b->batch_no,
                'expiry' => $b->expiry_date?->toDateString() ?? '',
                'qty' => (string) ($balances[$b->id] ?? '0'),
                'paid' => $came[(string) $b->id]['paid'] ?? '0',
                'free' => $came[(string) $b->id]['free'] ?? '0',
            ])
            /*
             * ⛔ যে লটে কিছু নেই সে বাছাইয়ের তালিকায় আসে না। ⓘ ওটা বেছে
             * ফেললে সারিটা কার্টে উঠত আর সংরক্ষণের সময় ভেঙে পড়ত —
             * অর্থাৎ ভুলটা ধরা পড়ত ত্রিশটা সারি তোলার পরে।
             */
            ->filter(fn (array $lot) => bccomp($lot['qty'], '0', 4) > 0)
            ->groupBy('productId')
            ->map(fn ($rows) => $rows->values()->all())
            ->all();
    }

    public function paymentTerms(): array
    {
        $terms = [
            ['value' => 'cash', 'label' => __('sales::field.term_cash')],

            /*
             * ⭐ COD এখানে সত্যিই আলাদা কিছু বোঝায়, আর ওটাই ক্রয়ের
             * সাথে পার্থক্য।
             *
             * নগদ  → টাকা ড্রয়ারে, জমার ঘরে বসে
             * COD  → মাল ভ্যানে গেল, টাকা ফিরবে ডেলিভারিম্যানের সাথে
             *
             * ⚠️ দুইটার **তারিখ একই দিন**, তবু একটা আদায় হয়ে গেছে আর
             * আরেকটা পাওনা — খাতায় দুইটা সম্পূর্ণ আলাদা অবস্থা।
             */
            ['value' => 'cod', 'label' => __('sales::field.term_cod')],
        ];

        $rows = PaymentTerm::query()
            ->where('is_active', true)
            ->orderBy('days')
            ->get(['id', 'code', 'name_en', 'name_bn', 'days']);

        foreach ($rows as $row) {
            if ((int) $row->days <= 0) {
                continue;
            }

            $terms[] = [
                'value' => 'credit:'.(int) $row->days,
                'label' => __('sales::field.term_credit', ['count' => (int) $row->days]),
            ];
        }

        $terms[] = ['value' => 'month_end', 'label' => __('sales::field.term_month_end')];
        $terms[] = ['value' => 'fixed', 'label' => __('sales::field.term_fixed')];

        return $terms;
    }

    /**
     * কাউন্টারে টাকা নেওয়ার পদ্ধতি — চেক বাদ (চেক কেবল চেকের খাতা দিয়ে)।
     * ⓘ [[DirectSaleController::create()]] থেকে হুবহু তোলা (৪ অক্টোবর ২০২৬) — ফোনের কাউন্টারও এই তালিকা পড়ে।
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    public function depositMethods(): \Illuminate\Support\Collection
    {
        return PaymentMethod::query()
            ->active()

            /*
             * ⛔ চেক কাউন্টারে নেই — মালিকের নির্দেশ, ২৬ সেপ্টেম্বর ২০২৬।
             * ⓘ চেক নেয় কেবল হিসাব বিভাগ; সেবাতেও একই বাধা
             * ([[DirectSaleService::assertNoChequeAtTheCounter()]])।
             * ⚠️ `kind` খালি থাকলে উপায়টা থাকে — ধরনহীন পুরনো সারি চেক নয়।
             */
            ->where(fn ($q) => $q->whereNull('kind')->orWhere('kind', '!=', 'cheque'))
            ->orderBy('code')
            ->get()
            ->map(fn (PaymentMethod $m): array => [
                'id' => (string) $m->id,
                'label' => $m->name(),
                'accountId' => $m->account_id === null ? '' : (string) $m->account_id,
                'needsReference' => (bool) $m->needs_reference,
                /*
                 * ⚠️ ধরনটা এখনো নাও থাকতে পারে, আর সেটা ইচ্ছাকৃত।
                 *
                 * `kind` কলামটা যোগ হচ্ছে (নগদ · ব্যাংক · MFS · চেক), আর
                 * ওটাই ঠিক করে দেবে খাতের তালিকায় কোনগুলো দেখা যাবে।
                 * Eloquent অনুপস্থিত কলামে `null` ফেরায়, ব্যতিক্রম নয় —
                 * তাই কলামটা আসার আগেও পর্দা ভাঙে না, কেবল ছাঁকনিটা
                 * চুপ করে থাকে (সব খাত দেখায়)।
                 *
                 * ⓘ **এটা "method না বাছা"র চেয়ে আলাদা অবস্থা** — তখন
                 * একটাও খাত দেখা যায় না, মালিকের নির্দেশমতো।
                 */
                'kind' => $m->kind,
            ])
            ->values();
    }

    /**
     * বাহক — পরিবহনকারী পক্ষ, বাছা শাখার। ⓘ [[DirectSaleController::create()]] থেকে হুবহু তোলা (৪ অক্টোবর ২০২৬)।
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    public function carriers(): \Illuminate\Support\Collection
    {
        return Supplier::query()->inViewedBranch()
            ->active()
            // RENTAL পক্ষের ধরনটা বাদ (৪ সেপ্টেম্বর, মালিকের চূড়ান্ত তালিকা) —
            // ভাড়ার গাড়িও পরিবহনকারী, তাই আলাদা ধরন নয়। এখন শুধু TRANSPORT।
            ->whereHas('partyType', fn ($q) => $q->whereIn('code', ['TRANSPORT']))
            ->orderBy('name_en')
            ->get(['id', 'code', 'name_en', 'name_bn', 'phone', 'contact_phone'])
            ->map(fn (Supplier $s): array => [
                'id' => (string) $s->id,
                'label' => $s->name(),
                // ⓘ বাহকের নম্বর পক্ষের খাতা থেকে — মালিকের ছবি, ২৭ সেপ্টেম্বর ২০২৬ (রাত)
                'phone' => (string) ($s->phone ?: $s->contact_phone ?: ''),
            ])
            ->values();
    }

    /**
     * খসড়ার নিজের সারি থেকে কাউন্টারের পর্দা — ছবি ছাড়া রাখা খসড়ার জন্য ([[resumeFrom()]])।
     *
     * ⓘ আকার কাউন্টারের নিজের ছবির মতোই (`screen.lines[]`): পণ্য, একক, পরিমাণ, ফ্রি, দর, ছাড়ের %, লট।
     *
     * @return array<string, mixed>
     *
     * ⓘ [[DirectSaleController]] থেকে হুবহু তোলা (৪ অক্টোবর ২০২৬) — ফোনের কাউন্টারও রাখা খসড়া খোলে ([[DirectSaleApiController::draft()]])।
     */
    public function screenFromDraft(SalesInvoice $draft): array
    {
        $draft->loadMissing(['lines.product.unit', 'lines.challanLine.batch']);

        if ($draft->lines->isEmpty()) {
            return [];
        }

        $lines = $draft->lines->sortBy('line_no')->values()->map(function ($line, int $i) {
            $cl = $line->challanLine;
            $qty = (string) ($cl?->delivered_qty ?? $line->qty);
            $gross = bcmul($qty, (string) $line->rate, 4);
            $pct = $cl?->discount_percent !== null
                ? (string) $cl->discount_percent
                : (bccomp($gross, '0', 4) > 0 ? bcmul(bcdiv((string) $line->discount, $gross, 8), '100', 4) : '0');

            return [
                'key' => $i + 1,
                'id' => (int) $line->product_id,
                'name' => (string) ($line->product?->name() ?? ''),
                'unit' => (string) ($line->product?->unit?->name() ?? ''),
                'vatRate' => 0,
                'vatInclusive' => false,
                'qty' => $qty,
                'freeQty' => (string) ($cl?->free_qty ?? '0'),
                'rate' => (string) $line->rate,
                'discountPercent' => $pct,
                'unitId' => '',
                'gifts' => [],
                'batchId' => $cl?->batch_id ? (string) $cl->batch_id : '',
                'batchNo' => (string) ($cl?->batch?->batch_no ?? ''),
            ];
        })->all();

        return [
            'screen' => [
                'customerId' => (string) $draft->customer_id,
                'creditTerm' => $draft->due_on ? 'credit' : 'cash',
                'dueOn' => $draft->due_on?->toDateString() ?? '',
                'lines' => $lines,
            ],
            'fields' => [],
        ];
    }
}
