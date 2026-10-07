<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Accounts\Services\OpeningBalanceService;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * শুরুর দিন তাকে যা ছিল — তিন জায়গায়, একসাথে, নাহলে কোথাও নয়।
 *
 * ── এই ক্লাসটা কেন আলাদা ────────────────────────────────────────────
 * পুরনো হিসাব থেকে ABOS-এ আসার দিন খোলা মজুদ বসাতে তিনটা কাজ করতে হয়,
 * আর তিনটাই করতে হয়:
 *
 *   ১. গুদামে পরিমাণ ঢোকে       → StockService
 *   ২. স্তরে দাম বসে             → CostLayerService  (নইলে প্রথম বিক্রয়েই
 *                                   FIFO জিজ্ঞেস করবে "কত দামে এসেছিল?"
 *                                   আর কোনো উত্তর থাকবে না)
 *   ৩. খতিয়ানে সম্পদ বসে         → OpeningBalanceService
 *
 * তিনটার যেকোনো একটা বাদ পড়লে দুইটা সংখ্যা আলাদা হয়ে যায়, আর কোনটা
 * সত্যি তা বলার উপায় থাকে না। ঠিক এটাই ঘটেছিল: তৃতীয়টা ছিল না, তাই
 * ডিপোর তাকে ৮,৪০,০০০ টাকার মাল থাকত আর ব্যালেন্স শিটে মজুদ শূন্য।
 *
 * তাই তিনটাকে একটা লেনদেনে বেঁধে একটা জায়গায় রাখা হল — যাতে ভবিষ্যতে
 * কেউ নতুন পথ লিখতে গিয়ে একটা ধাপ ভুলে না যায়।
 */
final class OpeningStockService
{
    /** খোলা মজুদের চলাচল ও স্তর এই ধরনেই বসে। */
    public const SOURCE_TYPE = 'opening';

    /** ⭐ খোলা মজুদের লট খালি হলে এই নাম (মালিক, ৬ অক্টোবর ২০২৬) */
    public const OPENING_LOT = 'Opening';

    public const DOCUMENT_NO = 'OPENING';

    /**
     * ⭐ সংশোধিত বা মুছে ফেলা সারির উল্টো চলাচল — উৎস মূল সারির চলাচল (মালিক, ৬ অক্টোবর ২০২৬)।
     * ⓘ "লেনদেন" নয়: [[stillOpen()]] এটা গোনে না, আর মূল সারিটা "আগেই বসানো"-তেও আর ধরা হয় না ([[exists()]])।
     */
    public const CORRECTED = 'opening:corrected';

    public const AUDIT_CORRECTED = 'opening_corrected';

    public const AUDIT_REMOVED = 'opening_removed';

    public function __construct(
        private readonly StockService $stock,
        private readonly CostLayerService $layers,
        private readonly OpeningBalanceService $opening,
    ) {}

    /**
     * এক পণ্য, এক গুদাম — পরিমাণ ও দর।
     *
     * ── ⚠️ লট ধরা পণ্যে লটটা বাধ্যতামূলক, ২৩ সেপ্টেম্বর ২০২৬ ──────────
     * ⓘ মালিকের নিয়ম *"লট ছাড়া মাল ঢুকবেও না"*। ⛔ এই দরজাটা এতদিন
     * লটের কথা জানতই না, তাই শুরুর দিনের গোটা মজুদটা ঢুকত লট ছাড়া —
     * আর ঢোকার পরদিনই সেটা [[StrandedStock]]-এর কাজ হয়ে যেত।
     *
     * ⚠️ ক্রয়ের পথ দুইটা এটা আগে থেকেই আটকাত ([[BringsInLots]])। ⓘ
     * পাহারা একটা **অবস্থা** আগলায়, একটা দরজা নয় — আর এখানে ঠিক সেই
     * ভুলটাই হয়েছিল: নিয়মটা বসানো হয়েছিল দুইটা দরজায়, সব দরজায় নয়।
     *
     * @throws ValidationException
     */
    public function bringIn(
        Product $product,
        Warehouse $warehouse,
        string $qty,
        string $unitCost,
        Carbon|string|null $date = null,
        ?string $narration = null,
        ?Batch $batch = null,

        // ⭐ মালটা কোন সরবরাহকারী/প্রিন্সিপালের — ঐচ্ছিক; স্তরে বসে, "আসল" কমিশন সেখান থেকে পড়ে (মালিক, ৬ অক্টোবর ২০২৬)
        ?int $supplierId = null,
    ): StockMovement {
        $this->assertSane($product, $warehouse, $qty, $unitCost, $batch, false, $date ?? now());

        return DB::transaction(function () use ($product, $warehouse, $qty, $unitCost, $date, $narration, $batch, $supplierId) {
            $movement = $this->stock->move(
                product: $product,
                warehouse: $warehouse,
                sourceType: self::SOURCE_TYPE,
                sourceId: $product->id,
                floor: $qty,
                date: $date,
                documentNo: self::DOCUMENT_NO,
                narration: $narration ?? __('inventory::message.opening_narration'),
                batch: $batch,
            );

            /*
             * স্তরের ও খতিয়ানের উৎস চলাচলের id, পণ্যের নয়।
             *
             * পণ্যের id দিলে একই পণ্য দুই গুদামে থাকলে দ্বিতীয় দাখিলাটা
             * বাতিল হত — পোস্টিং ইঞ্জিন একই উৎসে দুইবার বসতে দেয় না।
             * সিডারে ঠিক তাই হয়েছিল: নেত্রকোনার ৪০ বস্তা চাল, ১,৩৬,০০০
             * টাকা, নীরবে বাদ। স্তরেও একই কারণ — একই উৎস হলে একটা গুদামের
             * খোলা মজুদ বাতিল করলে অন্যটার স্তরও উঠে যেত।
             */
            $this->layers->receive(
                product: $product,
                qty: $qty,
                unitCost: $unitCost,
                sourceType: self::SOURCE_TYPE,
                sourceId: $movement->id,
                documentNo: self::DOCUMENT_NO,
                date: $date,

                // ⭐ স্তরও লট চেনে (চূড়ান্ত অডিট, [[CostLayerService::issue()]])
                batch: $batch,
                supplierId: $supplierId,
            );

            $this->opening->forInventory(
                sourceId: $movement->id,
                documentNo: self::DOCUMENT_NO,
                amount: bcmul($qty, $unitCost, 4),
                date: $date,
                // ⭐ গুদামের শাখার খাতায় — অডিট ম১১
                branchId: $warehouse->branch_id === null ? null : (int) $warehouse->branch_id,
            );

            return $movement;
        });
    }

    /**
     * ⭐ এক চাপে অনেক সারি — খোলা মজুদের কার্ট (মালিক, ৬ অক্টোবর ২০২৬: *"পাশাপাশি করে দিলে হতো না"*)।
     *
     * ⓘ এক গুদাম, এক তারিখ, এক বিবরণ; প্রতি সারি এক পণ্য। সব সারি **এক লেনদেনে**: একটা সারি ভুল হলে কোনোটাই বসে না,
     * আর প্রতিটা ভুল তার সারির নামে ফেরে (`rows.{i}.ঘর`) — পর্দা ঠিক সেই সারিটা লাল দেখায়।
     * ⓘ খাতায় **একটা** দাখিলা, সব সারির মোট মূল্যে (Dr মজুদ / Cr শুরুর মূলধন), গুদামের শাখায়।
     * ⓘ লট-ধরা পণ্যে লট খালি হলে লট-সিরিজ থেকে নম্বর (`LOT`), মেয়াদসহ।
     *
     * @param  list<array{product_id: int|string, qty: string, unit_cost: string, batch_no?: ?string, expiry_date?: ?string, supplier_id?: int|string|null}>  $rows
     * @return list<StockMovement>
     *
     * @throws ValidationException
     */
    public function bringInMany(Warehouse $warehouse, array $rows, Carbon|string|null $date = null, ?string $narration = null): array
    {
        if ($rows === []) {
            throw ValidationException::withMessages(['rows' => __('inventory::message.opening_cart_empty')]);
        }

        return DB::transaction(function () use ($warehouse, $rows, $date, $narration) {
            $errors = [];
            $movements = [];
            $total = '0';

            foreach (array_values($rows) as $i => $row) {
                try {
                    $product = Product::query()->find((int) ($row['product_id'] ?? 0));

                    if ($product === null) {
                        throw ValidationException::withMessages(['product_id' => __('inventory::message.opening_unknown_product')]);
                    }

                    $qty = (string) ($row['qty'] ?? '0');
                    $cost = (string) ($row['unit_cost'] ?? '0');

                    if (! is_numeric($qty) || ! is_numeric($cost)) {
                        throw ValidationException::withMessages(['qty' => __('inventory::message.opening_needs_qty')]);
                    }

                    $movement = $this->bringInRow($product, $warehouse, $qty, $cost, $date, $narration, $row);
                    $movements[] = $movement;
                    $total = bcadd($total, bcmul($qty, $cost, 4), 4);
                } catch (ValidationException $e) {
                    foreach ($e->errors() as $field => $messages) {
                        $errors["rows.{$i}.{$field}"] = $messages;
                    }
                }
            }

            // ⛔ একটা সারিও ভুল হলে কিছুই নয় — লেনদেনটা ফেরে, আর ভুলগুলো সারির নামে
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            $this->opening->forInventory(
                sourceId: (int) $movements[0]->id,
                documentNo: self::DOCUMENT_NO,
                amount: $total,
                date: $date,
                branchId: $warehouse->branch_id === null ? null : (int) $warehouse->branch_id,
            );

            return $movements;
        });
    }

    /**
     * কার্টের এক সারি — যাচাই, লট (খালি হলে সিরিজ থেকে), মজুদ আর খরচের স্তর; খাতা নয় ([[bringInMany()]] একবারে বসায়)।
     *
     * @param  array<string, mixed>  $row
     */
    private function bringInRow(Product $product, Warehouse $warehouse, string $qty, string $cost, Carbon|string|null $date, ?string $narration, array $row): StockMovement
    {
        $batch = null;

        if ($product->track_batch) {
            $no = trim((string) ($row['batch_no'] ?? ''));

            /*
             * ⭐ লট খালি → "Opening" — মালিক, ৬ অক্টোবর ২০২৬ (সিরিজ নয়, খোলা মজুদের জন্য)। ⓘ একই পণ্যে আগে থেকে
             * "Opening" লট থাকলে সেই লটেই যোগ হয় — হাতে লেখা লটের একই নিয়ম ([[BatchService::receive()]] খুঁজে পেলে সেটাই দেয়),
             * আর তাই এই লটে "আগেই বসানো" আটকায় না (`$topUp`)।
             */
            $topUp = false;

            if ($no === '') {
                $no = self::OPENING_LOT;
                $topUp = true;
            }

            $batch = app(BatchService::class)->receive(product: $product, batchNo: $no, expiry: ($row['expiry_date'] ?? null) ?: null);
        }

        $this->assertSane($product, $warehouse, $qty, $cost, $batch, $topUp ?? false, $date ?? now());

        // ⭐ ফ্রি — খরচ ছাড়া, একই লটে (ক্রয়ের মতো); খরচের স্তরে বসে না
        $free = trim((string) ($row['free_qty'] ?? ''));
        $free = is_numeric($free) && bccomp($free, '0', 4) > 0 ? bcadd($free, '0', 4) : '0';

        $movement = $this->stock->move(
            product: $product,
            warehouse: $warehouse,
            sourceType: self::SOURCE_TYPE,
            sourceId: $product->id,
            floor: $qty,
            free: $free,
            date: $date,
            documentNo: self::DOCUMENT_NO,
            narration: $narration ?? __('inventory::message.opening_narration'),
            batch: $batch,
        );

        $this->priceTheProduct($product, $cost, $row);

        $supplier = $row['supplier_id'] ?? null;

        // ⛔ প্রিন্সিপাল এই কোম্পানির সরবরাহকারীই — অন্যের id পাঠালে সারিটা থামে
        // ⛔ আর প্রিন্সিপাল মানে কারখানা — সেবাদাতা (পরিবহন, হাম্মালি…) নয় (মালিক, ৭ অক্টোবর ২০২৬; পর্দার তালিকার একই নিয়ম)
        if (filled($supplier) && ! DB::table('suppliers')
            ->leftJoin('mdm_party_types as pt', 'pt.id', '=', 'suppliers.party_type_id')
            ->where('suppliers.company_id', $product->company_id)->where('suppliers.id', (int) $supplier)->whereNull('suppliers.deleted_at')
            ->where(fn ($q) => $q->whereNull('suppliers.party_type_id')->orWhere('pt.code', 'VENDOR'))
            ->exists()) {
            throw ValidationException::withMessages(['supplier_id' => __('inventory::message.opening_unknown_principal')]);
        }

        $this->layers->receive(
            product: $product,
            qty: $qty,
            unitCost: $cost,
            sourceType: self::SOURCE_TYPE,
            sourceId: $movement->id,
            documentNo: self::DOCUMENT_NO,
            date: $date,
            batch: $batch,
            supplierId: filled($supplier) ? (int) $supplier : null,
        );

        return $movement;
    }

    /**
     * এই পণ্যের এই গুদামে খোলা মজুদ আগেই বসেছে কি না।
     *
     * ⭐ লট-ধরা পণ্যে প্রশ্নটা লট ধরে — Inventory অডিট ম২৪, ৫ অক্টোবর ২০২৬। ⛔ আগে প্রথম লট বসলেই পণ্যটা "হয়ে গেছে":
     * চালুর দিনে তাকে একই ওষুধের তিন লট থাকলে দ্বিতীয়টা আর ঢোকানো যেত না, আর মানুষ সব মাল এক লটে ঠেলতেন — মেয়াদ
     * আর রিকল দুটোই ভুল। ⓘ একই লট দুইবার নয়, আর লটহীন পণ্যে আগের মতো একবারই।
     */
    public function exists(Product $product, Warehouse $warehouse, ?Batch $batch = null): bool
    {
        return StockMovement::query()
            ->where('product_id', $product->id)
            ->where('warehouse_id', $warehouse->id)
            ->where('source_type', self::SOURCE_TYPE)
            ->when($product->track_batch && $batch !== null, fn ($q) => $q->where('batch_id', $batch->id))
            // ⓘ সংশোধিত বা মুছে ফেলা সারি আর বসানো নয় — একই লট আবার দেওয়া যায় (৬ অক্টোবর ২০২৬)
            ->whereNotExists(fn ($q) => self::correctionOf($q, 'inv_stock_movements.id'))
            ->exists();
    }

    /** ⓘ মূল সারির উল্টো চলাচল আছে কি না — তালিকা আর [[exists()]] একই শর্তে */
    public static function correctionOf($query, string $movementIdColumn)
    {
        return $query->selectRaw('1')
            ->from('inv_stock_movements as corr')
            ->where('corr.source_type', self::CORRECTED)
            ->whereColumn('corr.source_id', $movementIdColumn);
    }

    /**
     * ⭐ খোলা মজুদের এক সারি সংশোধন — মালিক, ৬ অক্টোবর ২০২৬: *"ভুলে লট ছাড়া সেভ করে ফেলেছি, এডিটের ব্যবস্থা কী?"*
     *
     * ⓘ লট, মেয়াদ, পরিমাণ, ফ্রি আর দর বদলানো যায় — কেবল যদি এই পণ্যের এই গুদামে খোলা মজুদ ছাড়া আর কিছু ঘটেনি
     * ([[stillOpen()]]: বিক্রি, স্থানান্তর, সমন্বয়, সংরক্ষণ — কিছুই নয়) আর সারির খরচের স্তরে কেউ হাত দেয়নি।
     *
     * ⛔ কিছুই মোছা বা বদলানো হয় না: মূল সারির উল্টো চলাচল ([[CORRECTED]]), তার স্তর তোলা ([[CostLayerService::withdraw()]]),
     * তারপর নতুন সারি আর নতুন স্তর, মূল তারিখেই। ⭐ খাতা: মূল্য (পরিমাণ × দর) বদলালে পুরনো মূল্যের উল্টো দাখিলা আর নতুন
     * মূল্যের দাখিলা; কেবল লট বা মেয়াদ বা ফ্রি বদলালে খাতায় কিছু বসে না — টাকা একই।
     *
     * @param  array{batch_no?: ?string, expiry_date?: ?string, qty: string, free_qty?: ?string, unit_cost: string}  $data
     *
     * @throws ValidationException
     */
    public function correct(StockMovement $movement, array $data): StockMovement
    {
        return DB::transaction(function () use ($movement, $data) {
            [$product, $warehouse, $layer, $batch] = $this->editable($movement);

            $qty = trim((string) ($data['qty'] ?? ''));
            $cost = trim((string) ($data['unit_cost'] ?? ''));

            if (! is_numeric($qty) || ! is_numeric($cost)) {
                throw ValidationException::withMessages(['qty' => __('inventory::message.opening_needs_qty')]);
            }

            $free = trim((string) ($data['free_qty'] ?? ''));
            $free = is_numeric($free) && bccomp($free, '0', 4) > 0 ? bcadd($free, '0', 4) : '0';

            $newBatch = null;
            $topUp = false;

            if ($product->track_batch) {
                $no = trim((string) ($data['batch_no'] ?? ''));
                $expiry = ($data['expiry_date'] ?? null) ?: null;

                if ($no === '') {
                    $no = self::OPENING_LOT;
                }

                $topUp = $no === self::OPENING_LOT;

                if ($batch !== null && $batch->batch_no === $no) {
                    $newBatch = $batch;

                    // ⓘ একই লট, মেয়াদ বদল — লটের মেয়াদ, পুরো লটের জন্য (লটের মেয়াদ একটাই)
                    if ($expiry !== null && $batch->expiry_date?->toDateString() !== Carbon::parse($expiry)->toDateString()) {
                        $newBatch = app(BatchService::class)->correctExpiry($batch, $expiry, __('inventory::message.opening_corrected_narration'));
                    }
                } else {
                    $newBatch = app(BatchService::class)->receive(product: $product, batchNo: $no, expiry: $expiry);
                }
            }

            $before = $this->snapshot($movement, $layer, $batch);
            $oldValue = bcmul((string) $movement->floor_change, (string) $layer->unit_cost, 4);
            $undo = $this->undo($movement, $product, $warehouse, $batch);

            $this->assertSane($product, $warehouse, $qty, $cost, $newBatch, $topUp, $movement->trx_date);

            $date = $movement->trx_date;
            $new = $this->stock->move(
                product: $product,
                warehouse: $warehouse,
                sourceType: self::SOURCE_TYPE,
                sourceId: $product->id,
                floor: $qty,
                free: $free,
                date: $date,
                documentNo: self::DOCUMENT_NO,
                narration: __('inventory::message.opening_corrected_narration'),
                batch: $newBatch,
            );

            $this->layers->receive(
                product: $product,
                qty: $qty,
                unitCost: $cost,
                sourceType: self::SOURCE_TYPE,
                sourceId: $new->id,
                documentNo: self::DOCUMENT_NO,
                date: $date,
                batch: $newBatch,
                supplierId: $layer->supplier_id === null ? null : (int) $layer->supplier_id,
            );

            $newValue = bcmul($qty, $cost, 4);

            if (bccomp($oldValue, $newValue, 4) !== 0) {
                $branch = $warehouse->branch_id === null ? null : (int) $warehouse->branch_id;
                $this->opening->withdrawInventory(sourceId: (int) $undo->id, documentNo: self::DOCUMENT_NO, amount: $oldValue, date: $date, branchId: $branch);
                $this->opening->forInventory(sourceId: (int) $new->id, documentNo: self::DOCUMENT_NO, amount: $newValue, date: $date, branchId: $branch);
            }

            app(\App\Core\Engines\Audit\AuditEngine::class)->record($new, self::AUDIT_CORRECTED, [
                'before' => [$before, null],
                'after' => [null, ['movement_id' => $new->id, 'batch_no' => $newBatch?->batch_no, 'expiry_date' => $newBatch?->expiry_date?->toDateString(),
                    'qty' => $qty, 'free_qty' => $free, 'unit_cost' => $cost]],
            ]);

            return $new;
        });
    }

    /**
     * ⭐ খোলা মজুদের এক সারি মুছে ফেলা — সংশোধনের একই শর্তে; মজুদ, স্তর আর খাতা তিনটাই উল্টো (মালিক, ৬ অক্টোবর ২০২৬)।
     *
     * @throws ValidationException
     */
    public function remove(StockMovement $movement): void
    {
        DB::transaction(function () use ($movement) {
            [$product, $warehouse, $layer, $batch] = $this->editable($movement);

            $before = $this->snapshot($movement, $layer, $batch);
            $value = bcmul((string) $movement->floor_change, (string) $layer->unit_cost, 4);
            $undo = $this->undo($movement, $product, $warehouse, $batch);

            $this->opening->withdrawInventory(
                sourceId: (int) $undo->id,
                documentNo: self::DOCUMENT_NO,
                amount: $value,
                date: $movement->trx_date,
                branchId: $warehouse->branch_id === null ? null : (int) $warehouse->branch_id,
            );

            app(\App\Core\Engines\Audit\AuditEngine::class)->record($undo, self::AUDIT_REMOVED, ['before' => [$before, null]]);
        });
    }

    /**
     * সংশোধন বা মুছে ফেলার শর্ত — খোলা মজুদের সারি, এখনো সংশোধিত নয়, আর তার মাল থেকে কিছুই বেরোয়নি।
     *
     * @return array{0: Product, 1: Warehouse, 2: \App\Modules\Inventory\Models\CostLayer, 3: ?Batch}
     *
     * @throws ValidationException
     */
    private function editable(StockMovement $movement): array
    {
        $movement = StockMovement::query()->whereKey($movement->id)->lockForUpdate()->firstOrFail();

        if ($movement->source_type !== self::SOURCE_TYPE || bccomp((string) $movement->floor_change, '0', 4) <= 0
            || StockMovement::query()->where('source_type', self::CORRECTED)->where('source_id', $movement->id)->exists()) {
            throw ValidationException::withMessages(['movement' => __('inventory::message.opening_not_editable')]);
        }

        $product = Product::query()->findOrFail($movement->product_id);
        $warehouse = Warehouse::query()->findOrFail($movement->warehouse_id);

        // ⛔ এই পণ্যের এই গুদামে বিক্রি, স্থানান্তর, সমন্বয় বা সংরক্ষণ — কিছু ঘটে থাকলে আর নয়
        if (! $this->stillOpen($product, $warehouse, $movement->trx_date)) {
            throw ValidationException::withMessages(['movement' => __('inventory::message.opening_edit_too_late', [
                'product' => $product->name(),
                'warehouse' => $warehouse->name(),
            ])]);
        }

        $layer = \App\Modules\Inventory\Models\CostLayer::query()
            ->where('source_type', self::SOURCE_TYPE)->where('source_id', $movement->id)->first();

        if ($layer === null) {
            throw ValidationException::withMessages(['movement' => __('inventory::message.opening_not_editable')]);
        }

        $batch = $movement->batch_id === null ? null : Batch::query()->find($movement->batch_id);

        return [$product, $warehouse, $layer, $batch];
    }

    /** মূল সারির উল্টো চলাচল আর তার স্তর তোলা — কিছুই মোছা হয় না, কেবল স্তর (ছোঁয়া হলে [[CostLayerService::withdraw()]] থামায়)। */
    private function undo(StockMovement $movement, Product $product, Warehouse $warehouse, ?Batch $batch): StockMovement
    {
        $undo = $this->stock->move(
            product: $product,
            warehouse: $warehouse,
            sourceType: self::CORRECTED,
            sourceId: (int) $movement->id,
            floor: bcmul((string) $movement->floor_change, '-1', 4),
            free: bcmul((string) ($movement->free_change ?? '0'), '-1', 4),
            date: $movement->trx_date,
            documentNo: self::DOCUMENT_NO,
            narration: __('inventory::message.opening_corrected_narration'),
            batch: $batch,
        );

        $this->layers->withdraw(self::SOURCE_TYPE, (int) $movement->id);

        return $undo;
    }

    /** @return array<string, mixed> নিরীক্ষার "আগে" */
    private function snapshot(StockMovement $movement, \App\Modules\Inventory\Models\CostLayer $layer, ?Batch $batch): array
    {
        return [
            'movement_id' => $movement->id,
            'batch_no' => $batch?->batch_no,
            'expiry_date' => $batch?->expiry_date?->toDateString(),
            'qty' => (string) $movement->floor_change,
            'free_qty' => (string) ($movement->free_change ?? '0'),
            'unit_cost' => (string) $layer->unit_cost,
        ];
    }

    /**
     * খোলা মজুদ এখনো বসানো যায় কি না।
     *
     * ── কেন লেনদেন শুরু হলে আর নয় ───────────────────────────────────
     * FIFO স্তর টানে বসার ক্রমে (`orderBy('id')`), তারিখে নয়। তাই আজ
     * বসানো খোলা মজুদের স্তরটা সারির **শেষে** গিয়ে দাঁড়াত — অথচ শুরুর
     * দিনের মালই সবার আগে বেরোনোর কথা।
     *
     * ফল হত নীরব আর মারাত্মক: গত মাসের বিক্রয়গুলো ইতিমধ্যেই পরের চালানের
     * দামে খরচ লিখে ফেলেছে, আর খোলা মজুদের সস্তা মালটা তাকে পড়ে থেকে
     * মুনাফাকে বছরের শেষে গিয়ে এলোমেলো করত। কোনো ত্রুটিবার্তা নেই,
     * শুধু ভুল লাভ-ক্ষতি।
     *
     * তাই নিয়মটা সহজ: এই পণ্যের এই গুদামে কোনো নড়াচড়া হয়ে থাকলে খোলা
     * মজুদের সময় পেরিয়ে গেছে। ভুল হলে সমন্বয়ের পর্দা আছে — সেটা কারণ
     * চায়, চিহ্ন রাখে, আর FIFO-কেও সঠিক ক্রমে জানায়।
     */
    public function stillOpen(Product $product, Warehouse $warehouse, Carbon|string|null $date = null): bool
    {
        $moves = StockMovement::query()
            ->where('product_id', $product->id)
            ->where('warehouse_id', $warehouse->id)
            // ⓘ অন্য লটের খোলা মজুদ লেনদেন নয় — চালুর দিন সব লট একই সারিতে বসে (ম২৪); তার সংশোধনও নয় (৬ অক্টোবর ২০২৬)
            ->whereNotIn('source_type', [self::SOURCE_TYPE, self::CORRECTED]);

        if ($date === null) {
            return ! $moves->exists();
        }

        /*
         * ⭐ তারিখ দিলে — মালিক, ৭ অক্টোবর ২০২৬ (ছবি: SL Lion WH-এ ২০টা পণ্য, ১৪টা "ইতিমধ্যেই নড়াচড়া করেছে"; আসলে কেবল ৬
         * অক্টোবরের ক্রয়-বিল ঢুকেছিল, কিছুই বেরোয়নি)।
         * ⓘ FIFO তারিখ ধরে টানে ([[CostLayerService]]: trx_date, তারপর id), তাই খোলা মজুদের তারিখ প্রথম চলাচলের আগে বা সেদিন হলে
         * শুরুর মাল ঠিকই সারির মাথায় বসে। ⛔ তবু আটকায়: (ক) খোলার তারিখের আগের কোনো চলাচল; (খ) কোনো বের হওয়া — সারির মোট
         * (তাক + অপেক্ষা + ফ্রি) ঋণাত্মক (বিক্রি, স্থানান্তর, সমন্বয়ে ঘাটতি); (গ) কোনো ধরা বা আটকানো (বিক্রির পথে মাল)।
         * ⓘ বসানোর সারি (অপেক্ষা → তাক) নিটে শূন্য, তাই সে বের হওয়া নয়।
         */
        $day = Carbon::parse($date)->toDateString();

        return ! $moves->where(fn ($q) => $q
            ->whereDate('trx_date', '<', $day)
            ->orWhereRaw('(floor_change + unplaced_change + free_change + unplaced_free_change) < 0')
            ->orWhere('reserved_change', '!=', 0)
            ->orWhere('free_reserved_change', '!=', 0)
            ->orWhere('hold_change', '!=', 0))
            ->exists();
    }

    /** @throws ValidationException */
    /**
     * ⭐ পণ্যের দাম — ক্রয়ের একই নিয়ম ([[PurchaseBillService]]): কেনা দর সবসময়; বিক্রয়মূল্য লেখা থাকলে; নীতি (মার্কআপ বা
     * মার্জিন, শতাংশসহ) বাছা থাকলে। ⓘ খালি বিক্রয়মূল্য মানে "দাম বদলাব না" — পুরনো দাম আর নীতি অক্ষত।
     *
     * @param  array<string, mixed>  $row
     */
    private function priceTheProduct(Product $product, string $cost, array $row): void
    {
        $update = ['purchase_price' => $cost];
        $price = trim((string) ($row['sales_price'] ?? ''));

        if (is_numeric($price) && bccomp($price, '0', 4) > 0) {
            $update['sale_price'] = bcadd($price, '0', 4);
            $anchor = (string) ($row['pricing_anchor'] ?? '');

            if (in_array($anchor, ['markup', 'margin', 'sales_price'], true)) {
                $pct = trim((string) ($row['pricing_pct'] ?? ''));
                $update['pricing_anchor'] = $anchor;
                $update['pricing_pct'] = $anchor !== 'sales_price' && is_numeric($pct) ? $pct : null;
            }
        }

        $product->update($update);
    }

    private function assertSane(
        Product $product,
        Warehouse $warehouse,
        string $qty,
        string $unitCost,
        ?Batch $batch = null,
        bool $topUp = false,
        Carbon|string|null $date = null,
    ): void {
        /*
         * ⛔ লট ধরা পণ্যে লট ছাড়া শুরুর মজুদ নয়।
         *
         * ⚠️ বার্তাটা ক্রয়ের পথের সাথে একই ([[BatchService]]), ইচ্ছাকৃতভাবে
         * — ⓘ দুই দরজায় একই অবস্থার দুই রকম কথা শুনলে ব্যবহারকারী ভাবতেন
         * নিয়ম দুইটা আলাদা।
         */
        if ($product->track_batch && $batch === null) {
            throw ValidationException::withMessages([
                'batch_no' => __('inventory::validation.batch_no_required', [
                    'product' => $product->name(),
                ]),
            ]);
        }

        /*
         * ⛔ আর লটটা এই পণ্যেরই।
         *
         * ⚠️ অন্য পণ্যের লট বসলে রিকলের ফোন ভুল ক্রেতার কাছে যেত, আর
         * যাঁদের কাছে যাওয়ার কথা তাঁরা বাদ পড়তেন।
         */
        if ($batch !== null && $batch->product_id !== $product->id) {
            throw ValidationException::withMessages([
                'batch_no' => __('inventory::validation.lot_of_another_product', [
                    'lot' => $batch->batch_no,
                    'product' => $product->name(),
                ]),
            ]);
        }

        if (bccomp($qty, '0', 4) <= 0) {
            throw ValidationException::withMessages([
                'qty' => __('inventory::message.opening_needs_qty'),
            ]);
        }

        /*
         * দর শূন্য হতে পারে না।
         *
         * শূন্য দরের মাল ব্যালেন্স শিটে কোনো সম্পদ নয়, অথচ তাকে আছে —
         * আর বেচলে খরচ শূন্য, অর্থাৎ পুরো বিক্রয়মূল্যটাই মুনাফা। সংখ্যাটা
         * কেউ হাতে না লিখলে ধরাই পড়ত না।
         */
        if (bccomp($unitCost, '0', 4) <= 0) {
            throw ValidationException::withMessages([
                'unit_cost' => __('inventory::message.opening_needs_cost'),
            ]);
        }

        if (! $topUp && $this->exists($product, $warehouse, $batch)) {
            throw ValidationException::withMessages([
                'product_id' => __('inventory::message.opening_already_done', [
                    'product' => $product->name(),
                    'warehouse' => $warehouse->name(),
                ]),
            ]);
        }

        if (! $this->stillOpen($product, $warehouse, $date)) {
            // ⓘ কিছুই বের না হয়ে থাকলে পথটা বলে দেওয়া — খোলার তারিখ প্রথম চলাচলের দিন বা তার আগে
            $first = StockMovement::query()->where('product_id', $product->id)->where('warehouse_id', $warehouse->id)
                ->whereNotIn('source_type', [self::SOURCE_TYPE, self::CORRECTED])->min('trx_date');
            $onlyLater = $first !== null && $this->stillOpen($product, $warehouse, $first);

            throw ValidationException::withMessages([
                'product_id' => $onlyLater
                    ? __('inventory::message.opening_date_before_first', [
                        'product' => $product->name(),
                        'warehouse' => $warehouse->name(),
                        'date' => \App\Core\Support\DateFormat::format($first),
                    ])
                    : __('inventory::message.opening_too_late', [
                        'product' => $product->name(),
                        'warehouse' => $warehouse->name(),
                    ]),
            ]);
        }
    }
}
