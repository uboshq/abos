<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Core\Services\OpenPeriod;
use App\Core\Support\CompanyContext;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\StorageLocation;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MasterData\Models\ReasonCode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * মজুদে লেখার একমাত্র পথ।
 *
 * Posting engine যেমন হিসাবের খাতার একমাত্র দরজা, এটাও তেমনি গুদামের।
 * প্রতিটা মডিউল — ক্রয়, বিক্রয়, সমন্বয় — এখান দিয়েই ঢোকে, আর সেই এক
 * দরজার কারণেই স্টকের যোগফল সবসময় চলাচলের সাথে মেলে।
 *
 * চারটা অবস্থার অঙ্ক:
 *
 *     Floor      = যা সত্যিই তাকে আছে
 *       − Reserved   = অনুমোদিত অর্ডারে ধরা
 *       − Hold       = আটকানো, কারণ সহ
 *       = Available  = যা বেচা যাবে
 *
 * Floor থেকে বিয়োগ, কারণ Reserved ও Hold-এর মাল তাকেই আছে — শুধু
 * বেচা যাবে না। আলাদা করে সরিয়ে রাখলে গণনার সময় তাকের সংখ্যা আর
 * খাতার সংখ্যা মিলত না, অথচ কোনো ভুল হয়নি।
 */
final class StockService
{
    public function __construct(private readonly OpenPeriod $period) {}

    /** সমন্বয়ের উৎস — ড্রিল-ডাউনে চেনা যায়। */
    public const ADJUSTMENT = 'stock_adjustment';

    /** মাল বুঝে নেওয়ার উৎস — Stock Placement। */
    public const PLACEMENT = 'stock_placement';

    /** মাল আটকানো ও ছাড়ার উৎস। */
    public const HOLD = 'stock_hold';

    /**
     * একটা চলাচল লেখা।
     *
     * সরাসরি StockMovement::create() ডাকা হয় না কোথাও — এখানেই একমাত্র
     * জায়গা, কারণ এখানেই নিয়মগুলো: শূন্য চলাচল নয়, আটকাতে কারণ লাগে,
     * আর তাকে যা নেই তা বের করা যায় না।
     */
    public function move(
        Product $product,
        Warehouse $warehouse,
        string $sourceType,
        int $sourceId,
        string $floor = '0',
        string $reserved = '0',
        string $hold = '0',
        ?ReasonCode $reason = null,
        Carbon|string|null $date = null,
        ?string $documentNo = null,
        ?string $narration = null,
        string $free = '0',
        string $freeReserved = '0',

        /*
         * কোন লট থেকে — ব্যাচ ধরা পণ্যে বাধ্যতামূলক, বাকিতে খালি।
         *
         * চলাচলের সারিতেই বসে, আলাদা কোনো লট-খতিয়ানে নয়। দুইটা রাখলে
         * একদিন দুইটা আলাদা হত, আর তখন রিকলের সময় কোনটা সত্যি তা বলার
         * উপায় থাকত না (Inventory-র ভুল নং ২)।
         */
        ?Batch $batch = null,

        /*
         * এসেছে, কিন্তু কেউ বুঝে নেয়নি — Stock Placement (৪ সেপ্টেম্বর ২০২৬)।
         *
         * ⭐ প্যারামিটারটা **সবার শেষে, ডিফল্ট শূন্য** — তাই বিদ্যমান
         * একটা ডাকও বদলাতে হয়নি। বাইরে থেকে মাল আনে মাত্র দুইটা সেবা
         * ([[PurchaseBillService]], [[PurchaseReceiptService]]); বাকি
         * দশটা ভেতরের মাল নাড়ে, আর তাদের কাছে এই ঘরটার কোনো অর্থ নেই।
         *
         * ⚠️ মালটা এখানে `floor`-এ ঢোকে **না**। তাই
         * `available = floor − reserved − hold` অপরিবর্তিত, আর বসানোর
         * আগে কেউ ওটা বেচতে গেলে `assertEnoughOnFloor()` নিজেই থামায় —
         * নতুন কোনো নিয়ম লেখা লাগেনি।
         */
        string $unplaced = '0',

        /*
         * ফ্রি মালের অপেক্ষা — একই গাড়ি, একই দায়িত্ব।
         *
         * ⓘ আলাদা ঘর, কারণ ফ্রি মালের ভাণ্ডারও আলাদা: বসানোর সময় বলা
         * যেতে হবে কতটা বিক্রির আর কতটা ফ্রি।
         */
        string $unplacedFree = '0',

        /*
         * গুদামের ভিতরে কোথায় — ৫ সেপ্টেম্বর ২০২৬।
         *
         * ⭐ আবারও **সবার শেষে, ডিফল্ট null**, ঠিক `unplaced`-এর মতোই —
         * তাই বিদ্যমান কোনো ডাক বদলাতে হয়নি, আর আজকের প্রতিটা সারির
         * অর্থ অবিকল একই।
         *
         * ⚠️ এই ঘরটা কোনো যোগফলে অংশ নেয় না, ইচ্ছাকৃতভাবে। বিক্রি ও
         * ইস্যুর পথগুলো তাক জিজ্ঞেস করে না, তাই তাক ধরে যোগ করলে
         * সংখ্যাটা **কেবল বাড়ত, কখনো কমত না**। এটা একটা ঘটনার
         * স্মৃতি — "কার্টনটা ঐ তাকে রাখা হয়েছিল" — আর ওটুকুই সৎ।
         */
        ?StorageLocation $location = null,
    ): StockMovement {
        $this->assertSomethingMoves(
            $floor, $reserved, $hold, $free, $freeReserved, $unplaced, $unplacedFree,
        );

        /*
         * বন্ধ মাসে মালও নড়বে না — ১ সেপ্টেম্বর ২০২৬।
         *
         * ── কী ভাঙা ছিল ─────────────────────────────────────────────
         * [[OpenPeriod::assertOpen()]] ডাকা হত কেবল টাকার পথে
         * ([[PostingEngine]], আর তার উপরে দাঁড়ানো সার্ভিসগুলো)। মালের
         * এই দরজাটা তিনটা নিয়ম দেখত — শূন্য চলাচল নয় · তাকে যা নেই তা
         * যাবে না · ফ্রি ভাণ্ডারে যা নেই তা যাবে না — **মাসের তালা
         * দেখত না**, অথচ নিচেই `trx_date` বসিয়ে দিত।
         *
         * বেশিরভাগ পথে ধরা পড়ত না, কারণ বিল-ক্রয়-ফেরত একই
         * `DB::transaction`-এ খতিয়ানেও যায়, আর তালাটা সেখানেই ছুঁড়ত।
         * **যে পথগুলো খতিয়ানে যায় না, সেগুলোই খোলা ছিল:** গুদাম বদল,
         * উৎপাদন, আর মাল আটকানো/ছাড়া।
         *
         * ফল: অগাস্ট বন্ধ করে রিপোর্ট পাঠানোর পরেও অগাস্টের তারিখে
         * একটা গুদাম-বদল ঢোকানো যেত। খাতা স্থির থাকত, মাল নড়ত — আর
         * অগাস্টের স্টক রিপোর্ট পরদিন অন্য সংখ্যা দেখাত।
         *
         * ── কেন কোনো ছাড়ের তালিকা নেই ───────────────────────────────
         * প্রথমে ভেবেছিলাম সংরক্ষণ (`reserved`) ছাড় পাবে আর বাতিলের
         * পথগুলো আটকে যাবে। মেপে দেখা গেল দুইটাই অপ্রয়োজনীয়:
         * **বাতিল ও ফেরতের প্রতিটা ডাক ইতিমধ্যেই `date: now()` পাঠায়**
         * (StockTransfer ২১৩·২৩২·২৮২, SalesOrder ২০১, …), ঠিক যেমন
         * PostingEngine উল্টো এন্ট্রি আজকের তারিখে লেখে। তাই নিয়মটা
         * ব্যতিক্রমহীন রাখা গেল, আর ব্যতিক্রমহীন নিয়মই একমাত্র নিয়ম
         * যেটা ছয় মাস পরও কেউ ভুল বোঝে না।
         *
         * ── জানালাটা এখানে কাকে আটকায় না ────────────────────────────
         * `assertOpen()` মাসের তালার সাথে পেছনের-তারিখের জানালাটাও
         * দেখে, কিন্তু [[OpenPeriod::windowDays()]] লগইন ছাড়া চলা কোড
         * ও `accounts.backdate.override` — দুইটাকেই ছেড়ে দেয়। তাই
         * সিডার, ইমপোর্ট আর খোলা মজুদ আগের মতোই বসে।
         */
        $this->period->assertOpen($date ?? now());

        return DB::transaction(function () use (
            $product, $warehouse, $sourceType, $sourceId,
            $floor, $reserved, $hold, $reason, $date, $documentNo, $narration,
            $free, $freeReserved, $batch, $unplaced, $unplacedFree
        ) {
            /*
             * তাকে যা নেই তা বের করা যায় না।
             *
             * লকটা দরকার: দুইজন একই মুহূর্তে শেষ কার্টনটা বেচলে দুইজনেই
             * "আছে" দেখত, আর দুইটা চালান ছাপা হয়ে যেত। নম্বর সিরিজে ঠিক
             * একই সমস্যা, একই সমাধান।
             */
            if (bccomp($floor, '0', 4) < 0) {
                $this->assertEnoughOnFloor($product, $warehouse, $floor);
            }

            // ফ্রি ভাণ্ডারেও একই নিয়ম — যে ফ্রি মাল নেই তা দেওয়া যায় না।
            // না আটকালে ফ্রি স্টক ঋণাত্মক হয়ে যেত, আর প্রস্তুতকারকের কাছে
            // হিসাব দিতে গিয়ে দেখা যেত আমরা যা পেয়েছি তার চেয়ে বেশি দিয়েছি।
            if (bccomp($free, '0', 4) < 0) {
                $this->assertEnoughFree($product, $warehouse, $free);
            }

            return StockMovement::create([
                'branch_id' => $warehouse->branch_id ?? CompanyContext::branchId(),
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
                'storage_location_id' => $location?->id,
                'batch_id' => $batch?->id,
                'trx_date' => ($date instanceof Carbon ? $date : Carbon::parse($date ?? now()))->toDateString(),
                'floor_change' => $floor,
                'reserved_change' => $reserved,
                'hold_change' => $hold,
                'free_change' => $free,
                'free_reserved_change' => $freeReserved,
                'unplaced_change' => $unplaced,
                'unplaced_free_change' => $unplacedFree,
                'reason_code_id' => $reason?->id,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'document_no' => $documentNo,
                'narration' => $narration,
                'created_by' => auth()->id(),
            ]);
        });
    }

    /**
     * তাক থেকে মাল বের করা — লট ধরা থাকলে লট বেছে।
     *
     * ── কেন এটা move()-এর পাশে, ভেতরে নয় ────────────────────────────
     * move() একটা সারি লেখে। লট ধরা পণ্যে একটা বিক্রয় কয়টা সারি হবে
     * তা আগে থেকে জানা যায় না — পাঁচটা চাইলে পুরনো লটে তিনটা, পরেরটায়
     * দুইটা, অর্থাৎ দুইটা সারি। move()-কে বহুবচন বানালে বাকি সব
     * ডাকনেওয়ালার কাছেও সেটা ফেরত দিত, অথচ তাদের একটাই সারি।
     *
     * ── কেন track_batch দেখে ─────────────────────────────────────────
     * ডিপোর চাল-ডাল-সাবানে লট নেই, আর কোনোদিন হবেও না। সবাইকে লট দিয়ে
     * বের করতে বললে ওই পণ্যগুলোর প্রতিটা বিক্রয় "লটে যথেষ্ট নেই" বলে
     * ফিরে যেত — একটা ফার্মেসি-সুবিধা চালু করলে গোটা ডিপো বন্ধ।
     *
     * @return list<StockMovement> লট ধরা না থাকলে একটাই সারি
     */
    public function issue(
        Product $product,
        Warehouse $warehouse,
        string $sourceType,
        int $sourceId,
        string $qty,
        string $reserved = '0',
        Carbon|string|null $date = null,
        ?string $documentNo = null,
        ?string $narration = null,
        string $hold = '0',
    ): array {
        $out = bcmul($qty, '-1', 4);

        if (! $product->track_batch) {
            return [$this->move(
                product: $product,
                warehouse: $warehouse,
                sourceType: $sourceType,
                sourceId: $sourceId,
                floor: $out,
                reserved: $reserved,
                hold: $hold,
                date: $date,
                documentNo: $documentNo,
                narration: $narration,
            )];
        }

        $allocation = app(BatchAllocator::class)->allocate(
            $product,
            $warehouse,
            $qty,
            $date === null ? null : ($date instanceof Carbon ? $date : Carbon::parse($date)),
        );

        $movements = [];

        foreach ($allocation as $index => $slice) {
            /*
             * ধরা মাল ছাড়ার অঙ্কটা প্রথম সারিতেই, ভাগ করে নয়।
             *
             * Reserved লট ধরে রাখা হয় না — ওটা পণ্য ও গুদামের সংখ্যা।
             * প্রতিটা সারিতে ভাগ করে বসালে যোগফল একই থাকত, কিন্তু
             * পড়ার সময় মনে হত লট ধরেও কিছু ধরা আছে, যা মিথ্যা।
             */
            $movements[] = $this->move(
                product: $product,
                warehouse: $warehouse,
                sourceType: $sourceType,
                sourceId: $sourceId,
                floor: bcmul($slice['qty'], '-1', 4),
                reserved: $index === 0 ? $reserved : '0',
                hold: $index === 0 ? $hold : '0',
                date: $date,
                documentNo: $documentNo,
                narration: $narration,
                batch: $slice['batch'],
            );
        }

        return $movements;
    }

    /**
     * আগের চলাচলগুলো উল্টে দেওয়া — বাতিল বা ফেরতের সময়।
     *
     * ── কেন লাইন থেকে নতুন করে গোনা হয় না ───────────────────────────
     * বেরোনোর সময় কোন লট থেকে কতটা গেছে সেটা কেবল চলাচলের সারিতেই
     * লেখা আছে। লাইন থেকে আবার গুনলে FEFO আজকের অবস্থা ধরে অন্য লট
     * বাছত — মাল ফিরত সেই লটে যেখান থেকে কখনো বেরোয়ইনি, আর রিকলের
     * খাতা মিথ্যা হয়ে যেত।
     *
     * ── ফ্রি ভাণ্ডারও উল্টায় ────────────────────────────────────────
     * প্রথম লেখায় কেবল `floor_change` উল্টাত। তখন ধরা পড়েনি, কারণ ফ্রি
     * ভাণ্ডারে মাল ঢোকানোর কোনো পথই ছিল না — সারিগুলো সবসময় শূন্য
     * থাকত, আর শূন্য উল্টালে কিছুই বদলায় না।
     *
     * পথটা বসানোর সাথে সাথেই এটা সত্যিকারের বাগ হয়ে উঠত: বাতিল করা
     * বিলের ফ্রি মাল গুদামে থেকে যেত, আর বাতিল করা বিক্রয়ের ফ্রি মাল
     * ফিরত না — প্রস্তুতকারকের কাছে "কত ফ্রি দিলাম" বলার সংখ্যাটাই
     * ভুল হয়ে যেত।
     *
     * @return list<StockMovement>
     */
    public function reverse(
        string $sourceType,
        int $sourceId,
        string $reversedType,
        Carbon|string|null $date = null,
        ?string $narration = null,
        ?callable $reservedFor = null,
    ): array {
        $original = StockMovement::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->with(['product', 'warehouse', 'batch'])
            ->get();

        $movements = [];

        foreach ($original as $row) {
            if ($row->product === null || $row->warehouse === null) {
                continue;
            }

            $reserved = $reservedFor === null ? '0' : (string) $reservedFor($row);
            $free = bcmul((string) $row->free_change, '-1', 4);

            /*
             * বসার অপেক্ষায় থাকা মালও ফিরে যায় — Stock Placement।
             *
             * ⚠️ **এটা না থাকলে বাতিল করা ক্রয়ের মাল চিরকাল "বসেনি"
             * ঘরে বসে থাকত।** ক্রয়ের মাল এখন `floor`-এ ঢোকে না, তাই
             * উল্টানোর সারিতে `floor_change` শূন্য — আর নিচের শর্তটা
             * সারিটাকে **পুরোপুরি এড়িয়ে যেত** ("কিছুই নড়েনি")।
             *
             * ফল হত সবচেয়ে খারাপ ধরনের: বিলটা বাতিল, খতিয়ান উল্টে
             * গেছে, অথচ Placement-এর পর্দায় কাগজটা রয়ে গেছে — আর কেউ
             * ওটা বসিয়ে দিলে **বাতিল করা মাল বিক্রয়যোগ্য হয়ে যেত।**
             */
            $unplaced = bcmul((string) $row->unplaced_change, '-1', 4);
            $unplacedFree = bcmul((string) $row->unplaced_free_change, '-1', 4);

            // পুরোপুরি শূন্য সারি উল্টানোর কিছু নেই — move() নিজেই
            // আপত্তি করত ("কিছুই নড়ছে না"), আর সেটা ঠিকই করত
            if (bccomp((string) $row->floor_change, '0', 4) === 0
                && bccomp($reserved, '0', 4) === 0
                && bccomp($free, '0', 4) === 0
                && bccomp($unplaced, '0', 4) === 0
                && bccomp($unplacedFree, '0', 4) === 0) {
                continue;
            }

            $movements[] = $this->move(
                product: $row->product,
                warehouse: $row->warehouse,
                sourceType: $reversedType,
                sourceId: $sourceId,
                floor: bcmul((string) $row->floor_change, '-1', 4),
                reserved: $reserved,
                date: $date,
                documentNo: $row->document_no,
                narration: $narration,
                free: $free,
                batch: $row->batch,
                unplaced: $unplaced,
                unplacedFree: $unplacedFree,
            );
        }

        return $movements;
    }

    /**
     * গণনার পর সমন্বয় — তাকে যা পাওয়া গেল সেটাই সত্যি।
     *
     * পার্থক্যটা লেখা হয়, নতুন সংখ্যাটা নয়। "৫০ ছিল, ৪৭ পাওয়া গেল, তাই
     * −৩" — এভাবে লিখলে পরে প্রশ্ন করা যায় "ওই তিনটা কোথায় গেল"। শুধু
     * ৪৭ লিখে দিলে প্রশ্নটাই আর করা যেত না।
     */
    public function adjust(
        Product $product,
        Warehouse $warehouse,
        string $countedQty,
        ReasonCode $reason,
        Carbon|string|null $date = null,
        ?string $narration = null,
    ): ?StockMovement {
        $current = $this->floorQty($product, $warehouse);
        $difference = bcsub($countedQty, $current, 4);

        // মিলে গেলে কোনো সারি নয় — শূন্য সারি খতিয়ানে শুধু ভিড় বাড়ায়
        if (bccomp($difference, '0', 4) === 0) {
            return null;
        }

        return $this->move(
            product: $product,
            warehouse: $warehouse,
            sourceType: self::ADJUSTMENT,
            sourceId: $product->id,
            floor: $difference,
            reason: $reason,
            date: $date,
            narration: $narration,
        );
    }

    /**
     * মাল আটকানো — কারণ সহ।
     *
     * কারণ ছাড়া আটকানো যায় না, আর কারণটা মাস্টার তালিকা থেকে। মুক্ত
     * লেখা হলে "damaged", "Damaged", "ক্ষতিগ্রস্ত" তিন রকম বানানে জমত,
     * আর তখন "কত মাল ক্ষতিগ্রস্ত" প্রশ্নের উত্তর বের করা যেত না।
     *
     * আর সবচেয়ে জরুরি: দাম বাড়ার অপেক্ষায় ধরে রাখা মালও এখানেই বসে,
     * কিন্তু তার কারণ আলাদা — রিপোর্টে দুইটা মিলিয়ে ফেললে মালিককে বলা
     * হত তার মালে সমস্যা, অথচ ওটা তার সিদ্ধান্ত।
     */
    public function hold(
        Product $product,
        Warehouse $warehouse,
        string $qty,
        ReasonCode $reason,
        Carbon|string|null $date = null,
        ?string $narration = null,
    ): StockMovement {
        if (bccomp($qty, '0', 4) <= 0) {
            throw ValidationException::withMessages([
                'qty' => __('inventory::validation.hold_needs_quantity'),
            ]);
        }

        if ($reason->context !== ReasonCode::HOLD) {
            throw ValidationException::withMessages([
                'reason_code_id' => __('inventory::validation.wrong_reason_context'),
            ]);
        }

        // যা বেচা যায় তার বেশি আটকানো যায় না — নাহলে Available ঋণাত্মক
        // হয়ে যেত, আর ঋণাত্মক "বিক্রয়যোগ্য" বলে কিছু নেই
        $available = $this->availableQty($product, $warehouse);

        if (bccomp($qty, $available, 4) > 0) {
            throw ValidationException::withMessages([
                'qty' => __('inventory::validation.not_enough_available', [
                    'available' => $available,
                ]),
            ]);
        }

        return $this->move(
            product: $product,
            warehouse: $warehouse,
            sourceType: self::HOLD,
            sourceId: $product->id,
            hold: $qty,
            reason: $reason,
            date: $date,
            narration: $narration,
        );
    }

    /** আটকানো মাল ছেড়ে দেওয়া। */
    public function release(
        Product $product,
        Warehouse $warehouse,
        string $qty,
        ReasonCode $reason,
        Carbon|string|null $date = null,
    ): StockMovement {
        $held = $this->holdQty($product, $warehouse);

        if (bccomp($qty, $held, 4) > 0) {
            throw ValidationException::withMessages([
                'qty' => __('inventory::validation.not_that_much_held', ['held' => $held]),
            ]);
        }

        return $this->move(
            product: $product,
            warehouse: $warehouse,
            sourceType: self::HOLD,
            sourceId: $product->id,
            hold: bcmul($qty, '-1', 4),
            reason: $reason,
            date: $date,
        );
    }

    // ── অবস্থাগুলো ─────────────────────────────────────────────────────

    /**
     * সবগুলো সংখ্যা একসাথে — একটা কোয়েরিতে।
     *
     * আলাদা করে বারবার গুনলে একই পাতায় অনেকগুলো কোয়েরি হত, আর তালিকায়
     * পঞ্চাশ পণ্যে কয়েকশো। তার চেয়েও বড় কথা: আলাদা মুহূর্তে গোনা সংখ্যা
     * একে অন্যের সাথে না-ও মিলতে পারে।
     *
     * বিক্রির মাল আর ফ্রি মাল দুইটা আলাদা ভাণ্ডার, একই ছকে গোনা:
     *
     *     বিক্রয়যোগ্য     = তাকে − অর্ডারে ধরা − আটকানো
     *     ফ্রি পাওয়া যাবে = ফ্রি তাকে − ফ্রি অর্ডারে ধরা
     *
     * ফ্রি মালে "আটকানো" নেই — দাম বাড়ার অপেক্ষায় ফ্রি মাল কেউ ধরে রাখে
     * না, কারণ ওটা বেচাই হয় না।
     *
     * @return array{floor: string, reserved: string, hold: string, available: string, free: string, free_reserved: string, free_available: string, unplaced: string, on_hand: string, unplaced_free: string, free_on_hand: string}
     */
    public function statesFor(Product $product, ?Warehouse $warehouse = null): array
    {
        $row = StockMovement::query()
            ->forProduct($product->id)
            ->inWarehouse($warehouse?->id)
            ->selectRaw('
                COALESCE(SUM(floor_change), 0) as floor,
                COALESCE(SUM(reserved_change), 0) as reserved,
                COALESCE(SUM(hold_change), 0) as hold,
                COALESCE(SUM(free_change), 0) as free,
                COALESCE(SUM(free_reserved_change), 0) as free_reserved,
                COALESCE(SUM(unplaced_change), 0) as unplaced,
                COALESCE(SUM(unplaced_free_change), 0) as unplaced_free
            ')
            ->first();

        $floor = (string) ($row->floor ?? 0);
        $reserved = (string) ($row->reserved ?? 0);
        $hold = (string) ($row->hold ?? 0);
        $free = (string) ($row->free ?? 0);
        $freeReserved = (string) ($row->free_reserved ?? 0);
        $unplaced = (string) ($row->unplaced ?? 0);
        $unplacedFree = (string) ($row->unplaced_free ?? 0);

        return [
            'floor' => $floor,
            'reserved' => $reserved,
            'hold' => $hold,
            // বিক্রয়যোগ্য = তাকে যা আছে − ধরা − আটকানো
            'available' => bcsub(bcsub($floor, $reserved, 4), $hold, 4),

            'free' => $free,
            'free_reserved' => $freeReserved,
            'free_available' => bcsub($free, $freeReserved, 4),

            /*
             * এসেছে, কিন্তু কেউ বুঝে নেয়নি।
             *
             * ⚠️ `available`-এর সূত্রে এটা **নেই, আর থাকবেও না** — সেটাই
             * গোটা নকশার ভিত্তি: বসানো হয়নি এমন মাল বিক্রয়যোগ্য নয়।
             *
             * ⭐ কিন্তু `on_hand` আলাদা কথা — *"গুদামে মোট কত"* প্রশ্নের
             * উত্তরে ওটা ধরতেই হবে। ⓘ না ধরলে গাড়ি থেকে নামা মাল
             * ব্যবস্থায় **উধাও** দেখাত, আর গুদামের লোক বলতেন "মাল তো
             * আছে" — লক্ষণ আর কারণ দুই বিভাগে।
             */
            'unplaced' => $unplaced,
            'on_hand' => bcadd($floor, $unplaced, 4),

            /* ফ্রি মালেরও একই জোড়া — একই গাড়ি, একই দায়িত্ব */
            'unplaced_free' => $unplacedFree,
            'free_on_hand' => bcadd($free, $unplacedFree, 4),
        ];
    }

    /**
     * গোটা ক্যাটালগের অবস্থা — একটা কোয়েরিতে।
     *
     * ── কেন এটা আলাদা করে লাগল ──────────────────────────────────────
     * `statesFor()` একটা পণ্যের জন্য একটা কোয়েরি করে, আর সেটাই ঠিক
     * যখন একটা পণ্যের পাতা খোলা হয়। কিন্তু চার্ট/বাল্ক DO-র শীটে
     * চারশো পণ্য একসাথে বসে — তখন ওটা চারশো কোয়েরি, আর পাতাটা
     * খুলতেই কয়েক সেকেন্ড।
     *
     * আরও একটা কারণ, যেটা গতির চেয়েও বড়: আলাদা আলাদা মুহূর্তে গোনা
     * চারশো সংখ্যা একে অন্যের সাথে না-ও মিলতে পারে। এক কোয়েরিতে
     * গুনলে গোটা শীটটা একই মুহূর্তের ছবি।
     *
     * @return array<int, array{floor: string, reserved: string, hold: string, available: string, free: string, free_reserved: string, free_available: string}>
     */
    public function statesForAll(?Warehouse $warehouse = null): array
    {
        $rows = StockMovement::query()
            ->inWarehouse($warehouse?->id)
            ->groupBy('product_id')
            ->selectRaw('
                product_id,
                COALESCE(SUM(floor_change), 0) as floor,
                COALESCE(SUM(reserved_change), 0) as reserved,
                COALESCE(SUM(hold_change), 0) as hold,
                COALESCE(SUM(free_change), 0) as free,
                COALESCE(SUM(free_reserved_change), 0) as free_reserved,
                COALESCE(SUM(unplaced_change), 0) as unplaced,
                COALESCE(SUM(unplaced_free_change), 0) as unplaced_free
            ')
            ->get();

        $states = [];

        foreach ($rows as $row) {
            $floor = (string) $row->floor;
            $reserved = (string) $row->reserved;
            $hold = (string) $row->hold;
            $free = (string) $row->free;
            $freeReserved = (string) $row->free_reserved;
            $unplaced = (string) $row->unplaced;
            $unplacedFree = (string) $row->unplaced_free;

            $states[(int) $row->product_id] = [
                'floor' => $floor,
                'reserved' => $reserved,
                'hold' => $hold,
                'available' => bcsub(bcsub($floor, $reserved, 4), $hold, 4),
                'free' => $free,
                'free_reserved' => $freeReserved,
                'free_available' => bcsub($free, $freeReserved, 4),

                /* statesFor()-এর সাথে হুবহু এক — দুইটা আলাদা হলে একই
                   পণ্য দুই পর্দায় দুই সংখ্যা দেখাত */
                'unplaced' => $unplaced,
                'on_hand' => bcadd($floor, $unplaced, 4),
                'unplaced_free' => $unplacedFree,
                'free_on_hand' => bcadd($free, $unplacedFree, 4),
            ];
        }

        return $states;
    }

    /**
     * মাল বুঝে নেওয়া — `unplaced` থেকে `floor`-এ।
     *
     * ── কেন একটাই সারি, দুইটা নয় ────────────────────────────────────
     * এক চলাচলেই `unplaced` কমে আর `floor` বাড়ে, তাই **মোট কখনো নড়ে
     * না** — এমনকি এক মুহূর্তের জন্যও। দুইটা সারিতে করলে দুইটার মাঝখানে
     * মালটা কোথাও থাকত না, আর ঠিক ওই মুহূর্তে কেউ রিপোর্ট খুললে গুদাম
     * খালি দেখাত।
     *
     * ── আংশিক বসানো ইচ্ছাকৃতভাবে সম্ভব ──────────────────────────────
     * ⚠️ দশ কার্টনের আটটা ঠিক, দুইটা ভাঙা — সবটা একসাথে বসানোর নিয়ম
     * করলে লোকে ভাঙা মালও বসিয়ে দিতেন, কারণ কাজটা এগোতে হবে। বাকি
     * দুইটা `unplaced`-এই থেকে যায়, আর তালিকায় দেখা যায়।
     *
     * ⛔ যা বসেনি তার বেশি বসানো যায় না — নাহলে `unplaced` ঋণাত্মক হয়ে
     * শূন্য থেকে মাল তৈরি হত।
     */
    public function place(
        Product $product,
        Warehouse $warehouse,
        string $qty,

        /*
         * ⚠️ বসানোর সারিটা **মূল কাগজের উৎসেই** লেখা হয়, নিজের নামে নয়।
         *
         * ── কী ভাঙা ছিল, ৪ সেপ্টেম্বর ২০২৬ ─────────────────────────
         * প্রথমে এখানে `sourceType: self::PLACEMENT` বসত। ফলে আসা আর
         * বসানো **দুইটা আলাদা দলে** পড়ত: চালানের দলে `unplaced +১২`,
         * বসানোর দলে `−১২`। যোগফল কাটাকাটি হত না, আর **কাগজটা
         * তালিকা থেকে কোনোদিন সরত না** — যদিও মাল বসে গেছে।
         *
         * ⓘ ধরা পড়েছে ব্রাউজারে পুরোটা চালিয়ে: পণ্যের যোগফল ঠিক
         * (`unplaced=0`), অথচ পর্দায় কাগজটা রয়ে গেছে। কোড পড়ে ধরা
         * পড়ত না, কারণ দুইটা সংখ্যাই আলাদাভাবে সঠিক।
         *
         * ⭐ একই উৎসে লেখায় ড্রিল-ডাউনও ঠিক থাকে: সারিটা যে চালান
         * থেকে এসেছিল, বসানোর সারিটাও সেখানেই নিয়ে যায়।
         */
        string $sourceType,
        int $sourceId,
        Carbon|string|null $date = null,
        ?string $documentNo = null,
        ?Batch $batch = null,

        /*
         * ফ্রি কার্টনও একই সারিতে — কাগজের লাইন ধরে।
         *
         * ⚠️ আলাদা করলে গুদামের লোক একই লাইনের অর্ধেক বসিয়ে চলে যেতে
         * পারতেন, আর বাকি ফ্রি কার্টনটা চিরকাল অপেক্ষায় থাকত।
         */
        string $freeQty = '0',

        /*
         * কোন তাকে রাখা হলো — গুদামে তাক থাকলে।
         *
         * ⚠️ তাকটা **এই গুদামেরই** কি না, সেটা এখানে মেলানো হয়।
         * ⛔ না মেলালে অন্য গুদামের একটা তাকের আইডি পাঠিয়ে দিলে খাতায়
         * এমন একটা সারি বসত যা বলত মালটা এমন জায়গায় আছে যেখানে ওই
         * গুদামের কেউ কোনোদিন যান না — আর ভুলটা ধরা পড়ত কেবল যেদিন
         * কেউ জিনিসটা খুঁজতে যেতেন।
         *
         * ⓘ কন্ট্রোলারেও একই যাচাই থাকবে (ব্যবহারকারী বাংলা বার্তা
         * পান), কিন্তু নিয়মটা এখানেও — সার্ভিসটা সিডার আর ইমপোর্ট
         * থেকেও ডাকা হয়, আর তারা কন্ট্রোলার দিয়ে আসে না।
         */
        ?StorageLocation $location = null,
    ): StockMovement {
        if ($location !== null && (int) $location->warehouse_id !== (int) $warehouse->id) {
            throw ValidationException::withMessages([
                'storage_location_id' => __('inventory::validation.location_not_in_warehouse'),
            ]);
        }

        $wantsPaid = bccomp($qty, '0', 4) > 0;
        $wantsFree = bccomp($freeQty, '0', 4) > 0;

        if (! $wantsPaid && ! $wantsFree) {
            throw ValidationException::withMessages([
                'qty' => __('inventory::validation.nothing_moves'),
            ]);
        }

        $state = $this->statesFor($product, $warehouse);

        if (bccomp($qty, $state['unplaced'], 4) > 0) {
            throw ValidationException::withMessages([
                'qty' => __('inventory::validation.more_than_unplaced', [
                    'waiting' => $state['unplaced'],
                ]),
            ]);
        }

        if (bccomp($freeQty, $state['unplaced_free'], 4) > 0) {
            throw ValidationException::withMessages([
                'free_qty' => __('inventory::validation.more_than_unplaced', [
                    'waiting' => $state['unplaced_free'],
                ]),
            ]);
        }

        return $this->move(
            product: $product,
            warehouse: $warehouse,
            sourceType: $sourceType,
            sourceId: $sourceId,
            narration: __('inventory::message.placed_narration'),
            floor: $wantsPaid ? $qty : '0',
            date: $date,
            documentNo: $documentNo,
            free: $wantsFree ? $freeQty : '0',
            batch: $batch,
            unplaced: $wantsPaid ? bcmul($qty, '-1', 4) : '0',
            unplacedFree: $wantsFree ? bcmul($freeQty, '-1', 4) : '0',
            location: $location,
        );
    }

    /**
     * একটা কাগজ এ পর্যন্ত মাল কোথা থেকে কতটা নিয়েছে — পণ্য ধরে।
     *
     * ── কেন এটা লাগল, ৫ সেপ্টেম্বর ২০২৬ ─────────────────────────────
     * ক্রয়-ফেরত এখন **আগে অপেক্ষার ঘর, তারপর তাক** থেকে নেয় (কার্টন
     * না খুলে ফেরত দেওয়াটাই স্বাভাবিক)। ⚠️ তাই বাতিল করার সময় ভাগটা
     * আন্দাজ করা চলে না — দশটার ছয়টা অপেক্ষার ঘর থেকে গেলে ছয়টা
     * সেখানেই ফিরতে হবে।
     *
     * ⛔ সবটা `floor`-এ ফেরালে বাতিল করাটা নীরবে একটা **বসানোর কাজ**
     * হয়ে যেত, আর "বসানোর আগে বিক্রি নয়" নিয়মটা একটা বাতিল দিয়ে পাশ
     * কাটানো যেত।
     *
     * ⓘ কাগজের নিজের সারিগুলোই সত্য — কোনো নতুন কলাম লাগেনি।
     *
     * @return array<int, array{floor: string, unplaced: string, free: string, unplaced_free: string}>
     */
    public function netBySource(string $sourceType, int $sourceId): array
    {
        $rows = StockMovement::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->groupBy('product_id')
            ->selectRaw('product_id')
            ->selectRaw('COALESCE(SUM(floor_change), 0) as floor')
            ->selectRaw('COALESCE(SUM(unplaced_change), 0) as unplaced')
            ->selectRaw('COALESCE(SUM(free_change), 0) as free')
            ->selectRaw('COALESCE(SUM(unplaced_free_change), 0) as unplaced_free')
            ->get();

        $net = [];

        foreach ($rows as $row) {
            $net[(int) $row->product_id] = [
                'floor' => (string) $row->floor,
                'unplaced' => (string) $row->unplaced,
                'free' => (string) $row->free,
                'unplaced_free' => (string) $row->unplaced_free,
            ];
        }

        return $net;
    }

    /** কত মাল বুঝে নেওয়ার অপেক্ষায় — গুদাম ধরে, বা সব গুদামে। */
    public function unplacedQty(Product $product, ?Warehouse $warehouse = null): string
    {
        return $this->statesFor($product, $warehouse)['unplaced'];
    }

    /** তাকে থাকা ফ্রি মাল — বিক্রির মজুদের সাথে মেশে না। */
    public function freeQty(Product $product, ?Warehouse $warehouse = null): string
    {
        return $this->statesFor($product, $warehouse)['free'];
    }

    /** যত ফ্রি মাল সত্যিই দেওয়া যাবে। */
    public function freeAvailableQty(Product $product, ?Warehouse $warehouse = null): string
    {
        return $this->statesFor($product, $warehouse)['free_available'];
    }

    public function floorQty(Product $product, ?Warehouse $warehouse = null): string
    {
        return $this->statesFor($product, $warehouse)['floor'];
    }

    public function holdQty(Product $product, ?Warehouse $warehouse = null): string
    {
        return $this->statesFor($product, $warehouse)['hold'];
    }

    /** অর্ডারে ধরা পরিমাণ — Sales মডিউল এটাই লেখে ও পড়ে। */
    public function reservedQty(Product $product, ?Warehouse $warehouse = null): string
    {
        return $this->statesFor($product, $warehouse)['reserved'];
    }

    public function availableQty(Product $product, ?Warehouse $warehouse = null): string
    {
        return $this->statesFor($product, $warehouse)['available'];
    }

    // ── নিয়ম ───────────────────────────────────────────────────────────

    private function assertSomethingMoves(
        string $floor,
        string $reserved,
        string $hold,
        string $free = '0',
        string $freeReserved = '0',
        string $unplaced = '0',
        string $unplacedFree = '0',
    ): void {
        $allZero = bccomp($floor, '0', 4) === 0
            && bccomp($reserved, '0', 4) === 0
            && bccomp($hold, '0', 4) === 0
            && bccomp($free, '0', 4) === 0
            && bccomp($freeReserved, '0', 4) === 0
            /* শেষ দুইটা ঘর না গুনলে কেবল-unplaced চলাচল "কিছুই নড়েনি"
               বলে ফিরে যেত — অর্থাৎ মাল আসার পথটাই বন্ধ থাকত */
            && bccomp($unplaced, '0', 4) === 0
            && bccomp($unplacedFree, '0', 4) === 0;

        if ($allZero) {
            // তিনটাই শূন্য মানে সারিটা কিছুই বলে না, শুধু খতিয়ান লম্বা করে
            throw ValidationException::withMessages([
                'qty' => __('inventory::validation.nothing_moves'),
            ]);
        }
    }

    /**
     * ফ্রি ভাণ্ডারে যা নেই তা দেওয়া যায় না।
     *
     * তাকের নিয়মের হুবহু যমজ, আলাদা ঘরে — কারণ ভাণ্ডার দুইটা। একটার ঘাটতি
     * অন্যটা দিয়ে পূরণ করা যায় না: বিক্রির মাল ফ্রি দিয়ে দিলে ওটার
     * ক্রয়মূল্য কোথাও যেত না, আর মুনাফা ঠিক ততটাই বেশি দেখাত।
     */
    private function assertEnoughFree(Product $product, Warehouse $warehouse, string $free): void
    {
        $onHand = StockMovement::query()
            ->forProduct($product->id)
            ->inWarehouse($warehouse->id)
            ->lockForUpdate()
            ->selectRaw('COALESCE(SUM(free_change), 0) as free')
            ->value('free');

        $wanted = bcmul($free, '-1', 4);

        if (bccomp($wanted, (string) $onHand, 4) > 0) {
            throw ValidationException::withMessages([
                'free_qty' => __('inventory::validation.not_enough_free', [
                    'product' => $product->name(),
                    'warehouse' => $warehouse->name(),
                    'have' => (string) $onHand,
                ]),
            ]);
        }
    }

    private function assertEnoughOnFloor(Product $product, Warehouse $warehouse, string $floor): void
    {
        $onFloor = StockMovement::query()
            ->forProduct($product->id)
            ->inWarehouse($warehouse->id)
            ->lockForUpdate()
            ->selectRaw('COALESCE(SUM(floor_change), 0) as floor')
            ->value('floor');

        $wanted = bcmul($floor, '-1', 4);

        if (bccomp($wanted, (string) $onFloor, 4) > 0) {
            throw ValidationException::withMessages([
                'qty' => __('inventory::validation.not_enough_on_floor', [
                    'product' => $product->name(),
                    'warehouse' => $warehouse->name(),
                    'have' => (string) $onFloor,
                ]),
            ]);
        }
    }
}
