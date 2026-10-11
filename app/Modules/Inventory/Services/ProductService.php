<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Core\Engines\Duplication\DuplicationEngine;
use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Support\DocumentStatus;
use App\Models\IssuedNumber;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * পণ্য তৈরি ও সম্পাদনা।
 *
 * গ্রাহক ও সরবরাহকারীর সার্ভিসের মতোই গঠন, আর সেটা ইচ্ছাকৃত: একই নিয়মে
 * চললে একটা শিখলে বাকিগুলো চেনা যায়।
 */
final class ProductService
{
    public function __construct(
        private readonly NumberSeriesEngine $numbers,
        private readonly DuplicationEngine $duplicates,
        private readonly ProductPackService $packs,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Product
    {
        // একই নামে দুইবার পণ্য নয় — নাম মিললে সতর্ক করে থামে, allow_duplicate
        // দিলে এগোয়। এই দরজাটাই এতদিন ছিল না, তাই লাইভে জোড়া পণ্য বসেছিল।
        // ⭐ ৫ অক্টোবর ২০২৬: মেলানো হয় কেবল একই শাখার পণ্যের সাথে ([[sameBranches()]])
        $this->duplicates->check(Product::class, $data, null, $this->sameBranches($data));

        $this->assertImportable($data);

        [$data, $packs, $defaults] = $this->splitPacks($data);
        [$data, $branches] = $this->splitBranches($data);

        return DB::transaction(function () use ($data, $packs, $defaults, $branches) {
            $givenCode = filled($data['code'] ?? null);

            $data['code'] = $givenCode ? trim((string) $data['code']) : $this->numbers->next('PRD');

            $this->assertCodeIsFree($data['code']);
            $this->assertBarcodeIsFree($data['barcode'] ?? null);

            $product = Product::create([
                ...$data,
                'status' => DocumentStatus::CONFIRMED,
                'is_active' => $data['is_active'] ?? true,
                'created_by' => auth()->id(),
            ]);

            // ⓘ প্যাক এলে একই ট্রানজ্যাকশনে — ভুল প্যাকে পণ্যটাও তৈরি হয় না
            if ($packs !== null && $product->unit_id !== null) {
                $this->packs->sync($product, $packs, $defaults);
            }

            $this->syncBranches($product, $branches);

            if (! $givenCode) {
                IssuedNumber::query()
                    ->where('document_no', $product->code)
                    ->whereNull('source_id')
                    ->update([
                        'source_type' => Product::drillSourceType(),
                        'source_id' => $product->id,
                    ]);
            }

            return $product;
        });
    }

    /**
     * ⛔ কোনো লটে মজুদ থাকলে লট বন্ধ নয় — মালিকের পরিকল্পনা ২, ৪ অক্টোবর ২০২৬ (সমন্বয়কের শর্ত)।
     * ⓘ বন্ধ হলে ঐ মজুদ কোন চালানের, কবে মেয়াদ, কত ফ্রি এসেছিল — কোনো প্রশ্নের উত্তর থাকত না, আর
     * লটহীন বিক্রি লটের মজুদ ছুঁতই না। প্রতিটা লটের পাঁচ ঘরের যোগফল ধরে মাপা, তাক থেকে আটকানো পর্যন্ত।
     */
    private function assertNoStockInAnyLot(Product $product): void
    {
        $held = StockMovement::query()
            ->where('product_id', $product->id)
            ->whereNotNull('batch_id')
            ->select('batch_id')
            ->groupBy('batch_id')
            ->havingRaw('ABS(SUM(COALESCE(floor_change, 0) + COALESCE(hold_change, 0) + COALESCE(free_change, 0)'
                .' + COALESCE(unplaced_change, 0) + COALESCE(unplaced_free_change, 0))) > 0.00005')
            ->exists();

        if ($held) {
            throw ValidationException::withMessages(['track_batch' => __('inventory::message.lot_off_has_stock')]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Product $product, array $data): Product
    {
        // নাম বদলে আরেকটা পণ্যের নকল হয়ে গেলেও একই পাহারা; নিজের সারি বাদ
        $this->duplicates->check(Product::class, $data, $product->id, $this->sameBranches($data, $product));

        /*
         * ⛔ ফাঁকা কোড মানে "যেমন আছে" — পুরো-ERP অডিট, মজুদ ছ১৫ ([[AnEmptyCodeKeepsTheOldOneTest]])। ⓘ ফর্মের ফাঁকা ঘর
         * `null` হয়ে আসে, আর ঘরটা `NOT NULL` — আগে সোজা লেখা হত, পাতা ৫০০। নতুন পণ্যে ফাঁকা কোড সিরিজ থেকে আসে ([[create()]]);
         * পুরনো পণ্যের কোড কাগজে ছাপা হয়ে গেছে, তাই ফাঁকা রাখলে পুরনোটাই থাকে।
         */
        if (array_key_exists('code', $data) && blank($data['code'])) {
            unset($data['code']);
        }

        if (isset($data['code']) && trim((string) $data['code']) !== $product->code) {
            $this->assertCodeIsFree(trim((string) $data['code']), $product->id);
        }

        if (isset($data['barcode']) && $data['barcode'] !== $product->barcode) {
            $this->assertBarcodeIsFree($data['barcode'], $product->id);
        }

        /*
         * ⛔ base বদলানো — মজুদ বা কাগজ হয়ে গেলে আর নয় (মালিকের নিয়ম,
         * ১৯ সেপ্টেম্বর ২০২৬)। কারণ [[ProductPackService::assertBaseCanChange()]]-এ।
         * ⓘ একক ছিলই না এমন পণ্যে প্রথমবার একক বসানো সবসময় চলে।
         */
        $newUnit = array_key_exists('unit_id', $data) ? (int) $data['unit_id'] : (int) $product->unit_id;

        [$data, $packs, $defaults] = $this->splitPacks($data);

        if ($product->unit_id !== null && $newUnit !== (int) $product->unit_id) {
            $this->packs->assertBaseCanChange($product, $packs !== null);
        }

        // ⓘ কোন এককটা base ছিল — বদলানোর আগেই ধরে রাখা, কারণটা
        // [[ProductPackService::defaults()]]-এ
        $wasBase = $product->unit_id === null ? null : (int) $product->unit_id;

        if (array_key_exists('track_batch', $data) && ! (bool) $data['track_batch'] && (bool) $product->track_batch) {
            $this->assertNoStockInAnyLot($product);
        }

        [$data, $branches] = $this->splitBranches($data);

        return DB::transaction(function () use ($product, $data, $packs, $defaults, $wasBase, $branches) {
            $product->update($data);

            $this->syncBranches($product, $branches);

            if ($packs !== null && $product->unit_id !== null) {
                $this->packs->sync($product, $packs, $defaults, $wasBase);
            }

            return $product->fresh();
        });
    }

    /**
     * নিষ্ক্রিয় করা — মোছা নয় (নিয়ম ৫)।
     *
     * মজুদ থাকা অবস্থাতেও নিষ্ক্রিয় করা যায়: পণ্যটা আর কেনা হবে না,
     * কিন্তু গুদামে যা আছে তা তো আছেই, আর সেটা বেচে শেষ করতে হবে।
     * আটকালে ব্যবহারকারী বাধ্য হতেন একটা ভুয়া সমন্বয় দিয়ে মজুদ শূন্য
     * করতে — যা আসল মালটা লুকিয়ে ফেলত।
     */
    /**
     * ⭐ কোন শাখায় বিক্রি হয় — ফর্মের ঘর আলাদা করা (৩০ সেপ্টেম্বর ২০২৬, মালিকের সিদ্ধান্ত খ)।
     *
     * ⓘ `branch_table` না এলে null — "ছোঁয়া হবে না" (আমদানি, পুরনো ফর্ম, API)। এলে তালিকাটা
     * যা আছে তাই, আর খালি তালিকা মানে **সব শাখায়**। ⚠️ ব্রাউজার খালি চেকবক্স পাঠায় না,
     * তাই আলাদা সংকেত — [[splitPacks()]]-এর `pack_table`-এর একই কারণ।
     *
     * @param  array<string, mixed>  $data
     * @return array{0: array<string, mixed>, 1: list<int>|null}
     */
    private function splitBranches(array $data): array
    {
        $sent = array_key_exists('branch_table', $data);
        $ids = $sent ? array_values(array_unique(array_map('intval', (array) ($data['branch_ids'] ?? [])))) : null;

        unset($data['branch_table'], $data['branch_ids']);

        return [$data, $ids];
    }

    /**
     * ⭐ একই নামের পাহারা কেবল একই শাখার পণ্যের মধ্যে — মালিক, ৫ অক্টোবর ২০২৬ (ADI: "এই ব্যবসায় আলাদা আলাদা শাখা,
     * আলাদা পণ্য")। SL-Gold Group-এর "Lexus Box- 180 gm" আর SL-Super Group-এর "Lexus Box- 180 gm" দুই ব্যবসার দুই
     * পণ্য, নিজের নিজের কোডে; অথচ পাহারা গোটা কোম্পানিতে মেলাত, তাই Gold-এর ৬১টা আর Lion-এর ৪২টা আমদানি আটকে ছিল।
     *
     * ⓘ কোন শাখা: নতুন পণ্যের বেলায় পাঠানো `branch_ids` (আমদানি হেডারের শাখা পাঠায়, [[ProductImporter]]); সম্পাদনায়
     * পাঠানো তালিকা, না এলে পণ্যের নিজের শাখা। ⛔ শাখা নেই মানে "সব শাখার" পণ্য — তখন গোটা কোম্পানিতে মেলানো,
     * আগের মতোই কড়া। আর সব-শাখার পুরনো পণ্য প্রতিটা শাখার সাথেই মেলে, কারণ সেটা সব শাখাতেই বিক্রি হয়।
     *
     * @param  array<string, mixed>  $data
     */
    private function sameBranches(array $data, ?Product $product = null): ?\Closure
    {
        $ids = array_key_exists('branch_table', $data)
            ? array_values(array_unique(array_map('intval', (array) ($data['branch_ids'] ?? []))))
            : ($product?->branches()->pluck('branches.id')->map(fn ($id) => (int) $id)->all() ?? []);

        if ($ids === []) {
            return null;
        }

        return fn ($query) => $query->where(fn ($q) => $q
            ->whereNotExists(fn ($s) => $s->from('inv_product_branches')->whereColumn('inv_product_branches.product_id', 'inv_products.id'))
            ->orWhereExists(fn ($s) => $s->from('inv_product_branches')
                ->whereColumn('inv_product_branches.product_id', 'inv_products.id')
                ->whereIn('inv_product_branches.branch_id', $ids)));
    }

    /** @param  list<int>|null  $ids */
    private function syncBranches(Product $product, ?array $ids): void
    {
        if ($ids === null) {
            return;
        }

        $product->branches()->sync(
            collect($ids)->mapWithKeys(fn (int $id) => [$id => ['company_id' => $product->company_id]])->all(),
        );
    }

    /**
     * প্যাকের দুই ঘর পণ্যের ঘর থেকে আলাদা করা।
     *
     * ⚠️ পণ্যের মডেল অচেনা ঘর পেলে থেমে যায় (MassAssignmentException) —
     * আর সেটাই ঠিক, নইলে টাইপো নীরবে হারাত। তাই `packs` আর
     * `pack_defaults` আগে তুলে নেওয়া হয়।
     *
     * ⓘ `packs` না এলে null — মানে "টেবিলটা ছোঁয়া হবে না", খালি তালিকা
     * নয়। খালি তালিকা মানে "সব প্যাক সরাও"।
     *
     * ⚠️ `pack_table`: ফর্মে সব সারি মুছে জমা দিলে ব্রাউজার `packs` ঘরটা
     * পাঠায়ই না — তখন "ছোঁয়া হবে না" আর "সব সরাও" আলাদা করা যেত না, আর
     * শেষ প্যাকটা কখনো মোছা যেত না। তাই ফর্ম একটা লুকানো চিহ্ন পাঠায়:
     * টেবিলটা এই ফর্মে ছিল।
     *
     * @return array{0: array<string, mixed>, 1: ?array, 2: array}
     */
    private function splitPacks(array $data): array
    {
        $sent = array_key_exists('packs', $data) || ! empty($data['pack_table']);
        $packs = $sent ? (array) ($data['packs'] ?? []) : null;
        $defaults = (array) ($data['pack_defaults'] ?? []);

        unset($data['packs'], $data['pack_defaults'], $data['pack_table']);

        return [$data, $packs, $defaults];
    }

    public function deactivate(Product $product): Product
    {
        $product->refresh()->forceFill(['is_active' => false])->save();

        return $product->fresh();
    }

    public function activate(Product $product): Product
    {
        $product->refresh()->forceFill(['is_active' => true])->save();

        return $product->fresh();
    }

    /**
     * সেভ না করে দেখা — সারিটা গ্রহণযোগ্য কি না।
     *
     * ইমপোর্টের যাচাই-পর্দার জন্য। এখানে দাম নিয়ে একটাই নিয়ম, আর সেটা
     * সতর্কতা নয়, বাধা: বিক্রয়মূল্য ঋণাত্মক হতে পারে না।
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function assertImportable(array $data): void
    {
        foreach (['purchase_price', 'sale_price', 'reorder_level'] as $field) {
            $value = $data[$field] ?? 0;

            if ($value !== null && $value !== '' && bccomp((string) $value, '0', 4) < 0) {
                throw ValidationException::withMessages([
                    $field => __('inventory::validation.not_negative', [
                        'field' => __('inventory::field.'.$field),
                    ]),
                ]);
            }
        }
    }

    private function assertCodeIsFree(string $code, ?int $exceptId = null): void
    {
        $taken = Product::query()
            ->where('code', $code)
            ->when($exceptId, fn ($q, $id) => $q->whereKeyNot($id))
            // মুছে ফেলা পণ্যের কোডও ধরা হয়: unique ইনডেক্স সেটাও দেখে
            ->withTrashed()
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'code' => __('inventory::validation.code_taken', ['code' => $code]),
            ]);
        }
    }

    /**
     * বারকোড অনন্য।
     *
     * দুইটা পণ্যে একই বারকোড থাকলে স্ক্যানার কোনটা বেছে নেবে তা বলা যায়
     * না — আর ভুলটা ধরা পড়ে ভুল জিনিস বিক্রি হওয়ার পরে, কাউন্টারে।
     */
    private function assertBarcodeIsFree(?string $barcode, ?int $exceptId = null): void
    {
        if (blank($barcode)) {
            return;
        }

        $taken = Product::query()
            ->where('barcode', $barcode)
            ->when($exceptId, fn ($q, $id) => $q->whereKeyNot($id))
            ->withTrashed()
            ->exists()

            /*
             * ⚠️ কোনো পণ্যের প্যাকের বারকোডও (কার্টনের গায়েরটা) —
             * ১৯ সেপ্টেম্বর ২০২৬। নইলে এই পিসের নম্বর আর অন্য কারো কার্টনের
             * নম্বর এক হত, আর স্ক্যানার দুইটার একটা বেছে নিত।
             */
            || ProductUnit::query()->where('barcode', $barcode)->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'barcode' => __('inventory::validation.barcode_taken', ['barcode' => $barcode]),
            ]);
        }
    }
}
