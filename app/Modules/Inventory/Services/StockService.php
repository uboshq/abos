<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Core\Support\CompanyContext;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
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
    /** সমন্বয়ের উৎস — ড্রিল-ডাউনে চেনা যায়। */
    public const ADJUSTMENT = 'stock_adjustment';

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
    ): StockMovement {
        $this->assertSomethingMoves($floor, $reserved, $hold);

        return DB::transaction(function () use (
            $product, $warehouse, $sourceType, $sourceId,
            $floor, $reserved, $hold, $reason, $date, $documentNo, $narration
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

            return StockMovement::create([
                'branch_id' => $warehouse->branch_id ?? CompanyContext::branchId(),
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
                'trx_date' => ($date instanceof Carbon ? $date : Carbon::parse($date ?? now()))->toDateString(),
                'floor_change' => $floor,
                'reserved_change' => $reserved,
                'hold_change' => $hold,
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

    // ── চারটা অবস্থা ───────────────────────────────────────────────────

    /**
     * চারটা সংখ্যা একসাথে — একটা কোয়েরিতে।
     *
     * আলাদা করে চারবার গুনলে একই পাতায় চারটা কোয়েরি হত, আর তালিকায়
     * পঞ্চাশ পণ্যে দুইশো। তার চেয়েও বড় কথা: চারটা আলাদা মুহূর্তে গোনা
     * সংখ্যা একে অন্যের সাথে না-ও মিলতে পারে।
     *
     * @return array{floor: string, reserved: string, hold: string, available: string}
     */
    public function statesFor(Product $product, ?Warehouse $warehouse = null): array
    {
        $row = StockMovement::query()
            ->forProduct($product->id)
            ->inWarehouse($warehouse?->id)
            ->selectRaw('
                COALESCE(SUM(floor_change), 0) as floor,
                COALESCE(SUM(reserved_change), 0) as reserved,
                COALESCE(SUM(hold_change), 0) as hold
            ')
            ->first();

        $floor = (string) ($row->floor ?? 0);
        $reserved = (string) ($row->reserved ?? 0);
        $hold = (string) ($row->hold ?? 0);

        return [
            'floor' => $floor,
            'reserved' => $reserved,
            'hold' => $hold,
            // বিক্রয়যোগ্য = তাকে যা আছে − ধরা − আটকানো
            'available' => bcsub(bcsub($floor, $reserved, 4), $hold, 4),
        ];
    }

    public function floorQty(Product $product, ?Warehouse $warehouse = null): string
    {
        return $this->statesFor($product, $warehouse)['floor'];
    }

    public function holdQty(Product $product, ?Warehouse $warehouse = null): string
    {
        return $this->statesFor($product, $warehouse)['hold'];
    }

    public function availableQty(Product $product, ?Warehouse $warehouse = null): string
    {
        return $this->statesFor($product, $warehouse)['available'];
    }

    // ── নিয়ম ───────────────────────────────────────────────────────────

    private function assertSomethingMoves(string $floor, string $reserved, string $hold): void
    {
        $allZero = bccomp($floor, '0', 4) === 0
            && bccomp($reserved, '0', 4) === 0
            && bccomp($hold, '0', 4) === 0;

        if ($allZero) {
            // তিনটাই শূন্য মানে সারিটা কিছুই বলে না, শুধু খতিয়ান লম্বা করে
            throw ValidationException::withMessages([
                'qty' => __('inventory::validation.nothing_moves'),
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
