<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\MasterData\Models\Unit;
use Illuminate\Support\Facades\DB;

/**
 * পুরনো ডেটা থেকে পণ্যের প্যাকের টেবিল ভরা — ধাপ ২, ১৯ সেপ্টেম্বর ২০২৬।
 *
 * ── মালিকের সিদ্ধান্ত (abos-8b-এর মাধ্যমে) ──────────────────────────
 * ① base = সবচেয়ে ছোট একক। ② একক ছাড়া পণ্যে PCS। ③ ডজনের base PCS,
 * ×১২। ④ যে পণ্যের মজুদ আজ কোনো প্যাকে (কার্টন ইত্যাদি) গোনা, তার
 * base বদলাতে "১ কার্টন = কত পিস" জানা চাই — উত্তর না আসা পর্যন্ত সেটা
 * **ছোঁয়া হয় না**, শুধু রিপোর্টে "অপেক্ষায়"।
 *
 * ── ⚠️ কী কখনো লেখে না ────────────────────────────────────────────────
 * মজুদ-চলাচল, cost layer, আর কোনো কাগজের লাইন। ⓘ `abos:packs-snapshot`
 * আগে-পরে চালিয়ে সেটা প্রমাণ করা হয় ([[PackSnapshot]])।
 *
 * ⓘ বারবার চালানো নিরাপদ: যা আগেই আছে তা আবার বসে না, বদলায়ও না।
 * ⓘ `apply: false` (ডিফল্ট) একই কাজ করে ট্রানজ্যাকশনের ভেতরে, তারপর
 * ফিরিয়ে নেয় — তাই dry-run-এর রিপোর্ট আর আসল চালানোর রিপোর্ট হুবহু এক।
 */
final class PackBackfill
{
    /**
     * যে এককগুলো আসলে ধারক — ভেতরে পিস থাকে।
     *
     * ⚠️ বস্তা (BAG) এখানে নেই, ইচ্ছে করে: "মিনিকেট চাল ৫০ কেজি" পণ্যটাই
     * একটা বস্তা, আর তার সবচেয়ে ছোট বিক্রয়-একক বস্তাই। বস্তাকে ধারক
     * ধরলে এমন পণ্যও "অপেক্ষায়" পড়ত যার উত্তর মালিকের কাছে নেই।
     */
    public const CONTAINER_CODES = ['CTN', 'BOX', 'DOZ', 'PKT', 'CASE'];

    public const PIECE_CODE = 'PCS';

    public const DOZEN_CODE = 'DOZ';

    /**
     * @return list<array{company: string, dozen_linked: bool, given_a_unit: list<string>,
     *     base_rows: int, waiting: list<array{product: string, unit: string}>, no_piece_unit: bool}>
     */
    public function run(bool $apply = false): array
    {
        $report = [];

        foreach (Company::query()->orderBy('id')->get() as $company) {
            CompanyContext::forCompany($company->id, function () use ($company, $apply, &$report) {
                DB::beginTransaction();

                try {
                    $report[] = ['company' => $company->code, ...$this->forCompany()];
                } finally {
                    $apply ? DB::commit() : DB::rollBack();
                }
            });
        }

        return $report;
    }

    /**
     * @return array{dozen_linked: bool, given_a_unit: list<string>, base_rows: int,
     *     waiting: list<array{product: string, unit: string}>, no_piece_unit: bool}
     */
    private function forCompany(): array
    {
        $units = Unit::query()->get()->keyBy(fn (Unit $u) => strtoupper((string) $u->code));
        $piece = $units->get(self::PIECE_CODE);

        return [
            'dozen_linked' => $this->linkDozen($units->get(self::DOZEN_CODE), $piece),
            'given_a_unit' => $this->giveAUnit($piece),
            ...$this->baseRows($units),
            'no_piece_unit' => $piece === null,
        ];
    }

    /**
     * ③ ডজন = ১২ পিস, সব পণ্যে সত্যি — তাই এককের মাস্টারে।
     *
     * ⓘ কেবল তখন, যখন ডজনের কোনো base নেই — কেউ হাতে অন্য কিছু বসিয়ে
     * থাকলে সেটা তাঁর সিদ্ধান্ত।
     */
    private function linkDozen(?Unit $dozen, ?Unit $piece): bool
    {
        if ($dozen === null || $piece === null || $dozen->base_unit_id !== null) {
            return false;
        }

        $dozen->forceFill(['base_unit_id' => $piece->id, 'factor' => '12'])->save();

        return true;
    }

    /**
     * ② একক ছাড়া পণ্য — PCS।
     *
     * @return list<string> যে পণ্যগুলো একক পেল (কোড)
     */
    private function giveAUnit(?Unit $piece): array
    {
        if ($piece === null) {
            return [];
        }

        $given = [];

        foreach (Product::query()->withTrashed()->whereNull('unit_id')->orderBy('id')->get() as $product) {
            $product->forceFill(['unit_id' => $piece->id])->save();
            $given[] = (string) $product->code;
        }

        return $given;
    }

    /**
     * প্রতিটা পণ্যে base-এর নিজের সারি — factor ১, চার কাজেই ডিফল্ট।
     *
     * ⚠️ ধারক-এককে গোনা পণ্য (④) বাদ, আর রিপোর্টে "অপেক্ষায়"।
     * ⚠️ পণ্যে আগে থেকে কোনো প্যাক থাকলে ডিফল্টগুলো সেখানে যেমন আছে থাকে —
     * নতুন base সারি তখন কোনো ডিফল্ট দাবি করে না।
     *
     * @param  \Illuminate\Support\Collection<string, Unit>  $units
     * @return array{base_rows: int, waiting: list<array{product: string, unit: string}>}
     */
    private function baseRows($units): array
    {
        /*
         * ⚠️ `toBase()` জরুরি: Eloquent-এর Collection-এ `only()` **মডেলের
         * id** ধরে ছাঁকে, সংগ্রহের চাবি ধরে নয় — তাই `only(['CTN'])` সবসময়
         * খালি ফিরত, আর কার্টনের পণ্যও চুপচাপ base সারি পেয়ে যেত। টেস্টটা
         * ঠিক এটাই ধরেছিল।
         */
        $containers = $units->toBase()->only(self::CONTAINER_CODES)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $made = 0;
        $waiting = [];

        foreach (Product::query()->withTrashed()->whereNotNull('unit_id')->orderBy('id')->get() as $product) {
            if (in_array((int) $product->unit_id, $containers, true)) {
                $waiting[] = [
                    'product' => (string) $product->code,
                    'unit' => (string) $units->firstWhere('id', $product->unit_id)?->code,
                ];

                continue;
            }

            $packs = ProductUnit::query()->where('product_id', $product->id);

            if ((clone $packs)->where('unit_id', $product->unit_id)->exists()) {
                continue;
            }

            $first = (clone $packs)->doesntExist();

            ProductUnit::query()->create([
                'company_id' => $product->company_id,
                'product_id' => $product->id,
                'unit_id' => $product->unit_id,
                'factor' => '1',
                'is_purchase_default' => $first,
                'is_sales_default' => $first,
                'is_pos_default' => $first,
                'is_counter_default' => $first,
                'is_active' => true,
            ]);

            $made++;
        }

        return ['base_rows' => $made, 'waiting' => $waiting];
    }
}
