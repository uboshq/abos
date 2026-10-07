<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Contracts\RecipeBook;
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
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Supplier\Models\Supplier;
use Illuminate\Support\Collection;
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

    /**
     * কাউন্টারের পণ্য-তালিকা — দর, একক, বিক্রয়যোগ্য মজুদ।
     *
     * ⓘ [[DirectSaleController]] থেকে হুবহু তোলা (৪ অক্টোবর ২০২৬), তিনটা বদল নাম ধরে: সীমা ডাকনেওয়ালার, একটা পণ্যে
     * ছাঁকা যায় (ফোনের "দাম দেখুন" — [[DirectSaleApiController::price()]]), আর রেসিপির খাতা `app()` দিয়ে।
     */
    public function catalogue(?Warehouse $warehouse, int $limit, ?int $productId = null, ?Customer $customer = null): Collection
    {
        $sum = fn (string $column) => DB::table('inv_stock_movements')
            ->selectRaw("COALESCE(SUM({$column}), 0)")
            ->whereColumn('product_id', 'inv_products.id')
            ->where('company_id', CompanyContext::id())
            ->when($warehouse, fn ($q) => $q->where('warehouse_id', $warehouse->id));

        // ⓘ ক্রয়মূল্যের চাবি একবারই দেখা — নিচের প্রতিটা সারির জন্য নয়
        $seesCost = (bool) auth()->user()?->can('sales.cost.view');

        return Product::query()->soldInViewedBranch()
            ->active()
            ->with(['unit', 'tax'])
            ->select('inv_products.*')
            ->selectSub($sum('floor_change'), 'floor_total')
            ->selectSub($sum('reserved_change'), 'reserved_total')
            ->selectSub($sum('hold_change'), 'hold_total')
            ->selectSub($sum('free_change'), 'free_total')
            ->selectSub($sum('free_reserved_change'), 'free_reserved_total')
            ->orderBy('name_en')
            ->when($productId !== null, fn ($q) => $q->whereKey($productId))
            ->limit($limit)
            ->get()
            // ⭐ দর এই গ্রাহকের দর তালিকা থেকে, নাহলে পণ্যের দাম ([[SalesPrice]], ৫ অক্টোবর ২০২৬) — সব পণ্য দুইটা কোয়েরিতে
            ->tap(function (Collection $rows) use (&$prices, $customer) {
                $prices = app(SalesPrice::class)->forMany($customer, $rows);
            })
            ->map(function (Product $p) use ($warehouse, $seesCost, &$prices) {
                /*
                 * খাবারের উত্তরটা আলাদা — কারণটা
                 * [[RecipeService::sellableQty()]]-এ। POS-ও ঠিক এই
                 * ডাকটাই করে, তাই দুই কাউন্টারে একই খাবারের পাশে
                 * দুইটা সংখ্যা বসে না।
                 */
                $available = app(RecipeBook::class)->sellableQty((int) $p->id, bcsub(
                    bcsub((string) $p->floor_total, (string) $p->reserved_total, 4),
                    (string) $p->hold_total,
                    4,
                ), $warehouse?->id);

                return (object) [
                    'id' => $p->id,
                    'code' => $p->code,
                    'name' => $p->name(),

                    // ⛔ খোঁজার জন্য দুইটা নামই — গ্রাহকের ঘরের একই কারণে
                    'name_en' => (string) $p->name_en,
                    'name_bn' => (string) $p->name_bn,

                    'unit' => $p->unit?->name() ?? '',
                    'rate' => $prices[(int) $p->id]->price,
                    // ⓘ দরটা কোথা থেকে — পর্দা "গ্রাহকের দাম" লেখে
                    'priceSource' => $prices[(int) $p->id]->source,
                    'priceLabel' => $prices[(int) $p->id]->label(),
                    'barcode' => (string) $p->barcode,

                    /*
                     * ভ্যাটের হার পণ্যের নিজের কর থেকে।
                     *
                     * পর্দায় হার বসিয়ে দিলে পণ্যভেদে আলাদা হার আর মানা হত
                     * না — ওষুধে শূন্য, বিস্কুটে সাড়ে সাত।
                     */
                    'vatRate' => (float) ($p->tax?->rate ?? 0),

                    /*
                     * দামের ভেতরের ভ্যাট — পর্দাকে বলে দিতে হয়।
                     *
                     * সার্ভার ভেতরের ভ্যাটে মোট বাড়ায় না (দরেই ওটা আছে),
                     * কিন্তু পর্দা না জানলে সে যোগ করে দিত — আর তখন
                     * বিক্রেতার চোখের সামনের সংখ্যা আর বিলের সংখ্যা
                     * আলাদা হত। ঠিক এই দূরত্বটাই ৩১ আগস্টে ধরা পড়েছে।
                     */
                    'vatInclusive' => (bool) ($p->tax?->is_inclusive ?? false),

                    /*
                     * ক্রয়মূল্য — ভেতরের কথা, তাই পর্দায় বোতামের পেছনে।
                     *
                     * ⛔ কেবল `sales.cost.view`-ওয়ালার জন্য — ২৭ সেপ্টেম্বর ২০২৬।
                     * ⚠️ আগে চালান বানাতে পারেন এমন যে কারও পাতার উৎসে প্রতিটা
                     * পণ্যের ক্রয়মূল্য যেত; বোতাম লুকানো ছিল না, আর লুকালেও
                     * সংখ্যাটা HTML-এ থাকত। ⓘ চাবি না থাকলে ঘরটাই **নেই** (০ নয়)।
                     */
                    ...($seesCost ? ['cost' => (float) $p->purchase_price] : []),

                    // নমুনার লাইভ স্টক প্যানেল — ছয়টাই
                    'main' => (string) $p->floor_total,
                    'reserved' => (string) $p->reserved_total,
                    'hold' => (string) $p->hold_total,
                    'available' => $available,
                    'free' => (string) $p->free_total,

                    /*
                     * ⭐ পুনঃক্রয়ের সীমা — খোঁজার তালিকায় "কম মজুদ" লাল
                     * দেখানোর জন্য (মালিকের নির্দেশ, ৬ সেপ্টেম্বর ২০২৬)।
                     *
                     * ⚠️ সীমাটা **পণ্যের নিজের কলাম**, কোনো ধ্রুবক নয়।
                     * ⓘ "কত হলে কম" প্রশ্নের উত্তর পণ্যভেদে আলাদা — চালের
                     * বস্তা আর ওষুধের পাতা এক মাপে কম হয় না — আর
                     * গ্রাহকভেদেও আলাদা। ⛔ কোডে একটা সংখ্যা বসালে সেটা
                     * প্রতিটা কোম্পানির জন্য ভুল হত।
                     *
                     * ⓘ ডিফল্ট ০, অর্থাৎ যিনি সীমা বসাননি তাঁর কিছুই লাল
                     * হয় না — নীরবে সবকিছু লাল দেখানোর চেয়ে সেটা ভালো।
                     */
                    'reorder' => (string) $p->reorder_level,
                    'free_available' => bcsub((string) $p->free_total, (string) $p->free_reserved_total, 4),

                    /*
                     * ⭐ লট ধরা পণ্য কি না — মালিকের নির্দেশ, ২৫ সেপ্টেম্বর ২০২৬।
                     *
                     * ⓘ পর্দাটা এটা দেখেই ঠিক করে লট বাছাইয়ের ঘরটা দেখাবে
                     * কি না। ⚠️ ডিপোর চাল-ডাল-সাবানে ঘরটা আসেই না — ⛔ প্রতিটা
                     * সারিতে একটা বাড়তি বাছাই কেবল টাইপিং বাড়াত।
                     */
                    'trackBatch' => (bool) $p->track_batch,
                ];
            })
            /*
             * ⛔ মজুদ শূন্য হলে খোঁজার তালিকায় আসে না — মালিকের নির্দেশ,
             * ২১ সেপ্টেম্বর ২০২৬: *"0 stock products ekhane asbe na"*।
             *
             * ⓘ কাউন্টারে তালিকাটা বেচার জন্য, দেখার জন্য নয়। যে মাল নেই
             * তার সারি বিক্রেতাকে কেবল পেরোতে হয় — আর ডিপোতে শূন্য মজুদের
             * পণ্যই বেশি, তাই শুরুর তিরিশটা সারির পুরোটাই শূন্য দিয়ে ভরা থাকত।
             *
             * ⚠️ ছাঁকনিটা মানচিত্রের **পরে**, SQL-এ নয়। ⓘ কারণ অর্ডারে-রান্না
             * খাবারের নিজের মজুদ শূন্যই থাকে, অথচ উপকরণ দিয়ে চল্লিশ প্লেট
             * হয় ([[RecipeService::sellableQty()]])। ⛔ SQL-এ ছাঁকলে বিরিয়ানি তালিকা
             * থেকেই হারাত, আর সেটা শূন্য দেখানোর চেয়েও খারাপ।
             */
            ->filter(fn (object $p) => bccomp((string) $p->available, '0', 4) > 0
                || bccomp((string) $p->free_available, '0', 4) > 0)
            ->values();
    }
}
