<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * গুদাম তৈরি ও সম্পাদনা।
 */
final class WarehouseService
{
    public function __construct(private readonly NumberSeriesEngine $numbers) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Warehouse
    {
        return DB::transaction(function () use ($data) {
            /*
             * কোড না দিলে সিরিজ থেকে — মালিকের নির্দেশ (২০২৬-০৮-০৭):
             * "সব জায়গায় কোড অটো বসবে"।
             *
             * নম্বরটা ট্রানজেকশনের ভেতরে নেওয়া হয়, নাহলে গুদাম সেভ ব্যর্থ
             * হলেও কোডটা খরচ হয়ে যেত আর সিরিজে একটা ফাঁক পড়ত। গ্রাহকের
             * কোডেও একই সিদ্ধান্ত, একই কারণে।
             *
             * হাতে দিলে সেটাই থাকে: পুরনো হিসাব থেকে আসা গুদামের কোড
             * (WH-MMS) বদলে ফেললে কাগজপত্রের সাথে মিল হারাত।
             */
            $code = trim((string) ($data['code'] ?? ''));
            $code = $code !== '' ? $code : $this->numbers->next('WHS');

            $this->assertCodeIsFree($code);

            $warehouse = Warehouse::create([
                ...$data,
                'code' => $code,
                'is_default' => false,
                'is_active' => true,
                'created_by' => auth()->id(),
            ]);

            // প্রথম গুদামটাই প্রধান — নাহলে কোনো প্রধান ছাড়া শুরু হত,
            // আর মাল কোথায় ঢুকবে তা বলার কেউ থাকত না
            if (($data['is_default'] ?? false) || Warehouse::query()->count() === 1) {
                $warehouse->makeDefault();
            }

            return $warehouse->fresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Warehouse $warehouse, array $data): Warehouse
    {
        if (trim((string) $data['code']) !== $warehouse->code) {
            $this->assertCodeIsFree(trim((string) $data['code']), $warehouse->id);
        }

        $makeDefault = (bool) ($data['is_default'] ?? false);
        unset($data['is_default']);

        $warehouse->update($data);

        if ($makeDefault) {
            $warehouse->makeDefault();
        }

        return $warehouse->fresh();
    }

    public function deactivate(Warehouse $warehouse): Warehouse
    {
        /*
         * প্রধান গুদাম নিষ্ক্রিয় করা যায় না।
         *
         * করলে মাল কোথায় ঢুকবে তা বলার কেউ থাকত না, আর পরের ক্রয়টা
         * একটা অচেনা ত্রুটিতে আটকে যেত। আগে অন্য একটাকে প্রধান করতে হয়।
         */
        if ($warehouse->is_default) {
            throw ValidationException::withMessages([
                'is_default' => __('master_data::validation.default_cannot_be_deactivated'),
            ]);
        }

        $warehouse->refresh()->forceFill(['is_active' => false])->save();

        return $warehouse->fresh();
    }

    private function assertCodeIsFree(string $code, ?int $exceptId = null): void
    {
        $taken = Warehouse::query()
            ->where('code', $code)
            ->when($exceptId, fn ($q, $id) => $q->whereKeyNot($id))
            ->withTrashed()
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'code' => __('inventory::validation.warehouse_code_taken'),
            ]);
        }
    }
}
