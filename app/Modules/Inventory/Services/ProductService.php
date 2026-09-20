<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Core\Engines\Duplication\DuplicationEngine;
use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Support\DocumentStatus;
use App\Models\IssuedNumber;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductUnit;
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
        $this->duplicates->check(Product::class, $data);

        $this->assertImportable($data);

        [$data, $packs, $defaults] = $this->splitPacks($data);

        return DB::transaction(function () use ($data, $packs, $defaults) {
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
     * @param  array<string, mixed>  $data
     */
    public function update(Product $product, array $data): Product
    {
        // নাম বদলে আরেকটা পণ্যের নকল হয়ে গেলেও একই পাহারা; নিজের সারি বাদ
        $this->duplicates->check(Product::class, $data, $product->id);

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

        return DB::transaction(function () use ($product, $data, $packs, $defaults) {
            $product->update($data);

            if ($packs !== null && $product->unit_id !== null) {
                $this->packs->sync($product, $packs, $defaults);
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
