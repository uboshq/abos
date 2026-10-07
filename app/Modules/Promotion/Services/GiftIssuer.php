<?php

declare(strict_types=1);

namespace App\Modules\Promotion\Services;

use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Engines\Posting\PostingEngine;
use App\Core\Support\CompanyContext;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\SerialNumber;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Services\CostLayerService;
use App\Modules\Inventory\Services\SerialNumberService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Promotion\Models\PromotionApplication;
use App\Modules\Promotion\Models\PromotionGiftIssue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * উপহার দেওয়া — আর মজুদ সত্যিই কমে।
 *
 * ── ⭐ মালিকের স্পেক, §৮ ─────────────────────────────────────────────
 * *"Gift কোনো imaginary item হবে না — Gift product অবশ্যই Inventory-এর
 * actual product হতে হবে। Gift issue হলে Inventory Stock কমবে।"*
 *
 * ── ⚠️ এই সেবাটা না থাকলে যে ভুলটা হত, আর কেন সেটা নীরব ─────────────
 * ⓘ উপহারটা বিলে ছাপা হত — *"Product B · 5 Carton · FREE"* — আর মজুদ
 * অক্ষত থাকত। ⛔ তখন খাতা বলত পাঁচ কার্টন আছে, গুদামে থাকত না।
 *
 * ⚠️ ধরা পড়ত অনেক পরে: পরের বিক্রয়ে *"আছে"* দেখে চালান কাটা হত, আর
 * মাল দিতে গিয়ে পাওয়া যেত না। ⓘ কোথাও কোনো লাল নেই — ঠিক সেই আকারের
 * ভুল যা এই রিপোতে বারবার ধরা পড়েছে।
 *
 * ── ⓘ কেন এই সেবাটা মজুদের নিয়ম নিজে লেখে না ────────────────────────
 * ⛔ FEFO, লট, বাকেট — সব [[StockService]]-এর কাজ। ⚠️ এখানে দ্বিতীয়বার
 * লিখলে একদিন একটায় নিয়ম বদলাত আর অন্যটায় নয়, আর তখন উপহারের মাল
 * বিক্রির মালের চেয়ে আলাদা নিয়মে বেরোত।
 */
final class GiftIssuer
{
    private const SERIES = 'GIFT';

    /** খাতায় উপহারের খরচ এই নামে; ফেরত এর `:reversal` নয়, নিজের নামে ([[PromotionReversal]]) */
    public const LEDGER_SOURCE = 'promotion_gift';


    public function __construct(
        private readonly StockService $stock,
        /*
         * ⚠️ `NumberSeriesEngine`, `NumberSeries` নয় — প্রথম খসড়ায় নামটা
         * ধরে নেওয়া হয়েছিল, আর `php -l` ওটা ধরে না: ক্লাসের নাম ভুল হলেও
         * বাক্য বৈধ। ⛔ ভাঙত কেবল প্রথম উপহার দেওয়ার দিন, কনটেইনার
         * ক্লাসটা বানাতে গিয়ে।
         */
        private readonly NumberSeriesEngine $numbers,
        private readonly SerialNumberService $serialNumbers,
    ) {}

    /**
     * ⭐ উপহারটা বের করে দেওয়া।
     *
     * ── ⚠️ পুরোটা একটা লেনদেনে, আর সেটা বাধ্যতামূলক ─────────────────
     * ⓘ দুইটা জিনিস ঘটে: মজুদ কমে, আর কাগজটা লেখা হয়। ⛔ মাঝখানে কিছু
     * ভাঙলে একটা ঘটত আর অন্যটা নয় — হয় মাল বেরিয়ে যেত কাগজ ছাড়া, নয়
     * কাগজ থাকত মাল ছাড়া। ⚠️ দুইটাই খাতা মিলতে দিত না।
     */
    public function issue(
        PromotionApplication $application,
        Product $product,
        Warehouse $warehouse,
        string $qty,
        ?Batch $batch = null,
        ?Carbon $date = null,
        array $serials = [],
    ): PromotionGiftIssue {
        $this->assertGiftIsReal($product, $qty, $batch);
        $this->assertSerialsFit($product, $warehouse, $qty, $serials);
        $this->assertShelfHolds($product, $warehouse, $qty);

        return DB::transaction(function () use ($application, $product, $warehouse, $qty, $batch, $date, $serials) {
            $this->assertStillOwed($application, $qty);

            /*
             * ⚠️ `forceCreate`, `create` নয় — আর কারণটা মেপে শেখা।
             *
             * ⓘ `code` ইচ্ছাকৃতভাবে `fillable`-এর বাইরে: নম্বরটা সিরিজ
             * থেকে আসে, ফর্ম থেকে নয়। ⛔ `create()` দিলে ঘরটা **চুপচাপ
             * ফেলে দেওয়া** হত, আর `NOT NULL` কলামে ইনসার্ট ভাঙত — ঠিক
             * যা নোটিশের প্রথম দিনে হয়েছিল।
             */
            $issue = PromotionGiftIssue::query()->forceCreate([
                'company_id' => CompanyContext::id(),

                /* ⓘ `public_id` এখানে নেই — [[HasPublicId]] নিজেই বসায়, এক জায়গায় */
                'code' => $this->numbers->next(self::SERIES),
                'promotion_application_id' => $application->id,
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
                'batch_id' => $batch?->id,
                'qty' => $qty,
                'unit_id' => $product->unit_id,

                /*
                 * ⭐ খরচটা **এখনই** জমে যায়, পরে গোনা হয় না।
                 *
                 * ⓘ পণ্যের ক্রয়মূল্য কাল বদলাতে পারে। ⛔ প্রতিবেদন
                 * বানানোর দিন গুনলে গত ঈদের উপহারের খরচ আজকের দামে
                 * দেখাত — আর সংখ্যাটা প্রতি মাসে বদলাত।
                 */
                'unit_cost' => (string) ($product->purchase_price ?? '0'),

                'issued_by' => auth()->id(),
                'issued_at' => $date ?? Carbon::now(),
            ]);

            /*
             * ⓘ মজুদ কমানোর কাজটা মজুদেরই — `sourceType` বলে কেন কমল।
             *
             * ⚠️ `promotion:gift` নামটা ইচ্ছাকৃতভাবে আলাদা: ⓘ ছয় মাস পরে
             * *"এই মালগুলো কোথায় গেল"* খুঁজলে উপহারের সারিগুলো বিক্রির
             * সারি থেকে আলাদা করে দেখা যায়, আর §১৭-এর *"Gift Stock
             * Consumption"* প্রতিবেদনটা ওখান থেকেই আসে।
             */
            $this->stock->issue(
                product: $product,
                warehouse: $warehouse,
                sourceType: 'promotion:gift',
                sourceId: $issue->id,
                qty: $qty,
                date: $date,
                documentNo: $issue->code,
                narration: $application->promotion?->name(),

                /*
                 * ⛔ বাছা লটটাই মজুদের সেবাকে বলা — নাহলে সে নিজে লট বাছে।
                 *
                 * ⓘ পর্যালোচনায় ধরা (২৭ সেপ্টেম্বর): এই ঘর ছাড়া মাল বেরোত
                 * আগে-মেয়াদোত্তীর্ণ লট থেকে, অথচ কাগজে লেখা থাকত মানুষের বাছা
                 * লট — আর বাতিলে মাল ফিরত কাগজের লটে। ⚠️ এক লট ফুলত, আরেকটা
                 * খালি থাকত, আর দুইটার মোট ঠিক দেখাত বলে কিছুই ভাঙত না।
                 */
                batch: $batch,
            );

            if ($product->track_serial) {
                $this->serialNumbers->issue($serials, [
                    'source_type' => 'promotion:gift',
                    'source_id' => $issue->id,
                    'issued_on' => ($date ?? Carbon::now())->toDateString(),
                ]);
            }

            $this->bookTheCost($issue, $product, $qty, $batch, $date ?? Carbon::now());

            return $issue;
        });
    }

    /**
     * ⛔ উপহারটা সত্যিই দেওয়া যায় কি না — মজুদ ছোঁয়ার আগেই।
     *
     * ── ⚠️ কেন যাচাইটা আগে, ভিতরে নয় ───────────────────────────────
     * ⓘ [[StockService]] নিজেই ঘাটতি ধরে। ⛔ কিন্তু তার বার্তাটা মজুদের
     * ভাষায় — *"floor short by 3"* — আর বিক্রয়কর্মী ওটা পড়ে বুঝতে
     * পারেন না কোন উপহারের কথা বলা হচ্ছে।
     *
     * ⭐ এখানে থামালে বার্তাটা মানুষের ভাষায় দেওয়া যায়, আর কোন পণ্য
     * কোন অফারে আটকেছে সেটা নাম ধরে বলা যায়।
     */
    /**
     * ⛔ উপহারটা এখনো পাওনা কি না — লেনদেনের ভিতরে, সারিতে তালা দিয়ে।
     *
     * ── ⚠️ কী ঘটত এটা ছাড়া ─────────────────────────────────────────
     * ⓘ পাঁচ কার্টন পাওনা, আর গুদামের দুইজন একই তালিকা দেখছেন। ⛔ দুইজনই
     * "দিন" চাপলে দশ কার্টন বেরোত — খাতা মিলত (দুইটা কাগজ, দুইটা
     * মজুদ-সারি), অথচ বিলে ছিল পাঁচ। ⚠️ কোথাও কিছু লাল হত না।
     *
     * ⓘ আর বাতিল বিলের উপহার: বিলটা আর নেই, অথচ মাল বেরোত।
     *
     * ⭐ তালা `lockForUpdate` — দুইটা অনুরোধ একসাথে এলে দ্বিতীয়টা প্রথমটার
     * লেখা দেখে তবেই গোনে।
     */
    private function assertStillOwed(PromotionApplication $application, string $qty): void
    {
        $fresh = PromotionApplication::query()->whereKey($application->id)->lockForUpdate()->firstOrFail();

        if ($fresh->reversed_at !== null) {
            throw ValidationException::withMessages([
                'qty' => __('promotion::validation.gift_for_cancelled_bill'),
            ]);
        }

        $issued = (string) PromotionGiftIssue::query()
            ->where('promotion_application_id', $fresh->id)
            ->sum('qty');
        $left = bcsub((string) $fresh->benefit_amount, $issued, 4);

        if (bccomp($qty, $left, 4) > 0) {
            throw ValidationException::withMessages([
                'qty' => __('promotion::validation.gift_over_owed', [
                    'owed' => $fresh->benefit_amount,
                    'issued' => $issued,
                    'left' => $left,
                ]),
            ]);
        }
    }

    private function assertGiftIsReal(Product $product, string $qty, ?Batch $batch): void
    {
        if (bccomp($qty, '0', 4) <= 0) {
            throw ValidationException::withMessages([
                'qty' => __('promotion::validation.gift_needs_quantity'),
            ]);
        }

        /*
         * ⛔ যে পণ্য লট রাখে, তার উপহারে লট বাছা **বাধ্যতামূলক**।
         *
         * ── ⚠️ কেন ডাটাবেজ এটা পাহারা দিতে পারে না ──────────────────
         * ⓘ `batch_id` কলামটা `nullable`, কারণ সব পণ্য লট রাখে না।
         * ⛔ ডাটাবেজ জানে না কোন পণ্য রাখে — সেটা `track_batch`-এ লেখা,
         * আর ওটা অন্য টেবিলের সারি।
         *
         * ⚠️ লট ছাড়া বেরোলে মালটা মেঝে থেকে কমত, অথচ লটের হিসাব অক্ষত
         * থাকত — লট বলত পঞ্চাশ, মেঝে বলত পঁয়তাল্লিশ। ⓘ পরের বিক্রয়
         * লট দেখে *"আছে"* পেত, চালান ছাপা হত, মাল পাওয়া যেত না।
         */
        if ($product->track_batch && $batch === null) {
            throw ValidationException::withMessages([
                'batch_id' => __('promotion::validation.gift_needs_lot', ['product' => $product->name()]),
            ]);
        }

        /*
         * ⛔ আর লটটা যদি অন্য পণ্যের হয়।
         *
         * ⚠️ ছাড়া এটা একটা নীরব বিনিময় হত: A পণ্যের উপহার B পণ্যের লট
         * থেকে কমত। ⓘ দুইটার মোটই ভুল হত, আর কোনোটাই শূন্যের নিচে যেত
         * না বলে কোথাও কিছু ভাঙত না।
         */
        if ($batch !== null && (int) $batch->product_id !== (int) $product->id) {
            throw ValidationException::withMessages([
                'batch_id' => __('promotion::validation.gift_lot_is_another_products'),
            ]);
        }
    }

    /**
     * ⛔ তাকে কি সত্যিই এতটা **দেওয়ার মতো** মাল আছে — স্পেক §২০।
     *
     * ── ⚠️ কেন `availableQty`, `floorQty` নয় ─────────────────────────
     * ⓘ [[StockService]] নিজে কেবল তাক দেখে (`assertEnoughOnFloor`)।
     * ⛔ তাকে দশ, আটটা একটা অর্ডারের জন্য ধরা — তাক দেখলে পাঁচটা উপহার
     * বেরোত, আর অর্ডারের ক্রেতা মাল নিতে এসে পেতেন না। ⭐ বিক্রয় ঠিক
     * এটাই আগে ঠেকায় ([[SalesInvoiceService::assertEnoughToSell()]]),
     * আর উপহার বিক্রির চেয়ে বেশি অধিকার পায় না।
     *
     * ── ⓘ কেন লেনদেনের বাইরে, আর তবু নিরাপদ ──────────────────────────
     * ⭐ এটা বার্তার জন্য — উপহারের ভাষায়, পণ্য আর গুদাম নাম ধরে।
     * ⚠️ দুইজন একসাথে শেষ কার্টনটা দিলে দুইজনেই এটা পেরোতে পারেন;
     * তখন থামায় [[StockService]]-এর তালা-দেওয়া পাহারা, আর লেনদেনটা
     * কাগজসহ ফিরে যায়। ⓘ অর্থাৎ এটা আগে বলে, শেষ কথা মজুদের।
     *
     * ⓘ লটের ঘাটতি এখানে দেখা হয় না — মজুদের *"এই লটে নেই"* বার্তাটা
     * লট নাম ধরেই বলে, আর দ্বিতীয়বার লিখলে দুইটা একদিন আলাদা হত।
     */
    private function assertShelfHolds(Product $product, Warehouse $warehouse, string $qty): void
    {
        $available = $this->stock->availableQty($product, $warehouse);

        if (bccomp($qty, $available, 4) > 0) {
            throw ValidationException::withMessages([
                'qty' => __('promotion::validation.gift_shelf_short', [
                    'product' => $product->name(),
                    'warehouse' => $warehouse->name(),
                    'available' => rtrim(rtrim($available, '0'), '.') ?: '0',
                ]),
            ]);
        }
    }

    /**
     * ⛔ সিরিয়াল-রাখা পণ্যের উপহারে প্রতিটা পিসের নম্বর — স্পেক §৮ *"Serial"*।
     *
     * ── ⚠️ কেন উপহারের কাগজে কলাম নয় ───────────────────────────────
     * ⓘ মজুদের নকশায় যোগটা **পিসের সারিতে**: `inv_serial_numbers`-এর
     * `out_source_type` + `out_source_id`। ⛔ কাগজে একটা `serial_id` বসালে
     * পাঁচ পিসের উপহারে চারটা নম্বর হারাত।
     *
     * ── ⚠️ কেন এখানে আবার দেখা, [[SerialNumberService::issue()]] থাকতেও ─
     * ⓘ ঐ সেবা নম্বর ধরে খোঁজে, পণ্য বা গুদাম দেখে না। ⛔ তাই অন্য পণ্যের
     * টিভির নম্বর দিলেও সেটা *"বিক্রি"* হয়ে যেত — উপহার বেরোত এক পণ্য,
     * ওয়ারেন্টির খাতায় যেত আরেকটা, আর কোথাও কিছু লাল হত না।
     *
     * @param  list<string>  $serials
     */
    private function assertSerialsFit(Product $product, Warehouse $warehouse, string $qty, array $serials): void
    {
        if (! $product->track_serial) {
            return;
        }

        /* ⓘ পিস ভাঙা যায় না — আড়াইটা টিভি বলে কিছু নেই */
        if (bccomp($qty, bcadd($qty, '0', 0), 4) !== 0) {
            throw ValidationException::withMessages([
                'qty' => __('promotion::validation.gift_serial_whole', ['product' => $product->name()]),
            ]);
        }

        /* ⚠️ মজুদের নিজের নিয়মে পরিষ্কার — ফাঁকা বাদ, বড় হরফ; নাহলে "abc" আর "ABC" দুইটা পিস গোনা হত */
        $clean = array_values(array_unique(array_filter(
            array_map(fn ($s) => mb_strtoupper(trim((string) $s)), $serials),
            fn (string $s) => $s !== '',
        )));

        if (count($clean) !== (int) $qty) {
            throw ValidationException::withMessages([
                'serials' => __('promotion::validation.gift_needs_serials', [
                    'product' => $product->name(),
                    'qty' => (int) $qty,
                    'given' => count($clean),
                ]),
            ]);
        }

        foreach ($clean as $no) {
            $piece = SerialNumber::query()->numbered($no)->first();

            if ($piece === null
                || (int) $piece->product_id !== (int) $product->id
                || (int) $piece->warehouse_id !== (int) $warehouse->id
                || $piece->status === SerialNumber::SOLD) {
                throw ValidationException::withMessages([
                    'serials' => __('promotion::validation.gift_serial_not_here', [
                        'no' => $no,
                        'product' => $product->name(),
                        'warehouse' => $warehouse->name(),
                    ]),
                ]);
            }
        }
    }

    /**
     * ⭐ উপহারের খরচ খাতায় — FIFO স্তর থেকে, Dr প্রচারের খরচ / Cr মজুদ (মালিকের পরিকল্পনা সংস্করণ ২, ৪ অক্টোবর ২০২৬; IFRS 15:
     * ফ্রি মাল আয়ে নয়, প্রচারের খরচে)।
     *
     * ⛔ আগে উপহার কেবল তাক থেকে কমত: খাতার মজুদ (১১২০) বেশি দেখাত, খরচ উঠত না, আর খরচের স্তরে মালটা থেকে যেত —
     * পরের বিক্রি ঐ দামে আবার টানত। ⓘ এখন বিক্রির একই পথে স্তর থেকে টানা ([[CostLayerService::issue()]]), আর উপহারের
     * এককের দামও সেই খরচ। স্তর না কুলালে কেনা দামে (নিচে) — উপহার থামে না।
     */
    private function bookTheCost(PromotionGiftIssue $issue, Product $product, string $qty, ?Batch $batch, Carbon $date): void
    {
        $layers = app(CostLayerService::class);

        /*
         * ⓘ স্তরে কুলালে FIFO খরচ, স্তর থেকে টেনে; না কুলালে আগের মতো কেনা দামে, স্তর না ছুঁয়ে — খরচের স্তর ছাড়া তাকে
         * আসা মাল (পুরনো খোলা মজুদ) উপহারে দেওয়া আগে চলত, এখনো চলে; কেবল খাতায় খরচটা এখন ওঠে।
         */
        // ⓘ স্তরে যতটুকু, FIFO-তে; কেবল ঘাটতিটুকু কেনা দামে (ⓘ১২, [[CostLayerService::issueOrPrice()]])
        $cost = $layers->issueOrPrice(
            product: $product,
            qty: $qty,
            sourceType: 'promotion:gift',
            sourceId: (int) $issue->id,
            documentNo: $issue->code,
            date: $date,
            batch: $batch,
        );

        $issue->forceFill(['unit_cost' => bcdiv($cost, $qty, 4)])->save();

        if (bccomp($cost, '0', 4) <= 0) {
            return;
        }

        app(PostingEngine::class)->post(
            self::LEDGER_SOURCE,
            (int) $issue->id,
            $date->toDateString(),
            [
                ['account_id' => (int) StandardChart::find(StandardChart::PROMOTION_EXPENSE)?->id, 'debit' => $cost, 'credit' => '0'],
                ['account_id' => (int) StandardChart::find(StandardChart::INVENTORY)?->id, 'debit' => '0', 'credit' => $cost],
            ],
            documentNo: $issue->code,
            branchId: CompanyContext::branchId(),
        );
    }

}
