<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Core\Support\CompanyContext;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\SerialNumber;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * সিরিয়াল নম্বর বসানো, আর পিস ধরে ধরে পথ রাখা।
 *
 * ── ⚠️ এই সেবা মজুদ নাড়ে না, ইচ্ছাকৃতভাবে ───────────────────────────
 * ⓘ পরিমাণের হিসাব [[StockService]]-এর, আর সেখানে একটা পিস মানে
 * `floor` -এ এক। ⛔ এখানেও পরিমাণ নাড়লে একই মাল দুইবার গোনা হত, আর
 * দুইটা হিসাব একদিন আলাদা হয়েই যায়।
 *
 * ⭐ এই সেবার কাজ একটাই: **কোন পিসটা কোথায়** — আর সেটা পরিমাণের
 * প্রশ্ন নয়, পরিচয়ের।
 */
final class SerialNumberService
{
    /**
     * মাল ঢুকল — নম্বরগুলো বসাও।
     *
     * ── ⛔ একই নম্বর দুইবার নয় ───────────────────────────────────────
     * ⚠️ টেবিলে ইউনিক শর্ত আছে, কিন্তু সেটা ছুঁড়লে ব্যবহারকারী একটা
     * কাঁচা SQL ত্রুটি দেখতেন। ⓘ এখানে ধরলে বাংলা বার্তা পান, আর
     * **কোন নম্বরটা** দুইবার সেটাও বলা যায়।
     *
     * @param  list<string>  $serials
     * @param  array<string, mixed>  $data
     * @return list<SerialNumber>
     */
    public function receive(Product $product, array $serials, array $data = []): array
    {
        if (! $product->track_serial) {
            throw ValidationException::withMessages([
                'serials' => __('inventory::validation.serial_not_tracked', [
                    'product' => $product->name(),
                ]),
            ]);
        }

        $clean = $this->cleanNumbers($serials);

        if ($clean === []) {
            throw ValidationException::withMessages([
                'serials' => __('inventory::validation.serial_needs_numbers'),
            ]);
        }

        $this->assertFree($clean);

        $warehouse = Warehouse::query()->find($data['warehouse_id'] ?? null);
        $batch = Batch::query()->find($data['batch_id'] ?? null);

        return DB::transaction(function () use ($product, $clean, $data, $warehouse, $batch) {
            $made = [];

            foreach ($clean as $serial) {
                $made[] = SerialNumber::create([
                    'company_id' => CompanyContext::id(),
                    'product_id' => $product->id,
                    'batch_id' => $batch?->id,
                    'serial_no' => $serial,
                    'warehouse_id' => $warehouse?->id,
                    'in_source_type' => $data['source_type'] ?? null,
                    'in_source_id' => $data['source_id'] ?? null,
                    'received_on' => Carbon::parse($data['received_on'] ?? now())->toDateString(),
                    'status' => SerialNumber::IN_STOCK,
                    'created_by' => auth()->id(),
                ]);
            }

            return $made;
        });
    }

    /**
     * পিস বেরোল — কার কাছে, আর ওয়ারেন্টির ঘড়ি কবে থেকে।
     *
     * ── ⚠️ ওয়ারেন্টি শুরু হয় বেরোনোর দিনে, ঢোকার দিনে নয় ─────────────
     * ⛔ গুদামে ছয় মাস পড়ে থাকা টিভির ওয়ারেন্টি ছয় মাস খেয়ে ফেলত,
     * আর ক্রেতা তার প্রাপ্যটুকু পেতেন না। ⓘ আর ওটা ধরা পড়ত কেবল
     * দাবির দিনে, যেদিন আর কিছু করার থাকে না।
     *
     * @param  list<string>  $serials
     * @param  array<string, mixed>  $data
     * @return list<SerialNumber>
     */
    public function issue(array $serials, array $data = []): array
    {
        $clean = $this->cleanNumbers($serials);

        if ($clean === []) {
            throw ValidationException::withMessages([
                'serials' => __('inventory::validation.serial_needs_numbers'),
            ]);
        }

        $issuedOn = Carbon::parse($data['issued_on'] ?? now());

        $months = (int) ($data['warranty_months'] ?? 0);

        return DB::transaction(function () use ($clean, $data, $issuedOn, $months) {
            $out = [];

            foreach ($clean as $serial) {
                $piece = SerialNumber::query()->numbered($serial)->first();

                if ($piece === null) {
                    throw ValidationException::withMessages([
                        'serials' => __('inventory::validation.serial_unknown', ['no' => $serial]),
                    ]);
                }

                /*
                 * ⛔ যে পিস ইতিমধ্যে বেরিয়ে গেছে সেটা আবার বেরোতে পারে না।
                 *
                 * ⚠️ পারলে একই নম্বর দুইজন ক্রেতার কাছে যেত, আর
                 * ওয়ারেন্টির দাবিতে দুইজনের কাগজেই ঐ এক নম্বর থাকত।
                 */
                if ($piece->status === SerialNumber::SOLD) {
                    throw ValidationException::withMessages([
                        'serials' => __('inventory::validation.serial_already_out', ['no' => $serial]),
                    ]);
                }

                $piece->update([
                    'status' => SerialNumber::SOLD,
                    'out_source_type' => $data['source_type'] ?? null,
                    'out_source_id' => $data['source_id'] ?? null,
                    'issued_on' => $issuedOn->toDateString(),
                    'sold_to' => $data['sold_to'] ?? null,

                    /*
                     * ⓘ মাস শূন্য মানে *"ওয়ারেন্টি নেই"*, আর তখন তারিখ
                     * দুইটাই খালি থাকে। ⛔ শূন্য মাসের একটা তারিখ বসালে
                     * ওটা *"আজই শেষ"* বলত, যা মিথ্যা।
                     */
                    'warranty_from' => $months > 0 ? $issuedOn->toDateString() : null,
                    'warranty_to' => $months > 0
                        ? $issuedOn->copy()->addMonths($months)->toDateString()
                        : null,
                ]);

                $out[] = $piece->fresh();
            }

            return $out;
        });
    }

    /**
     * ⚠️ খালি ও পুনরাবৃত্ত নম্বর বাদ, আর বড় হরফে সাজানো।
     *
     * ⓘ কাগজে নম্বর বড় হরফে ছাপা থাকে, আর মানুষ যেভাবে পারেন টাইপ
     * করেন। ⛔ দুই রকম হরফে একই নম্বর দুইবার বসলে ইউনিক শর্তটা ধরত
     * না, অথচ মানুষের চোখে ওটা একই নম্বর।
     *
     * @param  list<string>  $serials
     * @return list<string>
     */
    private function cleanNumbers(array $serials): array
    {
        $seen = [];

        foreach ($serials as $serial) {
            $serial = mb_strtoupper(trim((string) $serial));

            if ($serial === '') {
                continue;
            }

            if (isset($seen[$serial])) {
                throw ValidationException::withMessages([
                    'serials' => __('inventory::validation.serial_twice', ['no' => $serial]),
                ]);
            }

            $seen[$serial] = true;
        }

        return array_keys($seen);
    }

    /**
     * ⛔ এই নম্বরগুলোর একটাও যেন আগে থেকে না থাকে।
     *
     * ⚠️ একবারে জিজ্ঞেস করা হয়, নম্বর ধরে ধরে নয় — ⓘ একশো পিসের
     * চালানে একশোটা কোয়েরি হত, আর কাউন্টারে দাঁড়ানো মানুষ সেটা টের
     * পেতেন।
     *
     * @param  list<string>  $serials
     */
    private function assertFree(array $serials): void
    {
        $taken = SerialNumber::query()
            ->whereIn(DB::raw('UPPER(serial_no)'), $serials)
            ->pluck('serial_no')
            ->all();

        if ($taken !== []) {
            throw ValidationException::withMessages([
                'serials' => __('inventory::validation.serial_taken', [
                    'no' => implode(', ', array_slice($taken, 0, 5)),
                ]),
            ]);
        }
    }
}
