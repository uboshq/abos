<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Modules\Inventory\Models\StockMovement;
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
            /*
             * ⭐ শাখার প্রথম গুদাম নিজেই প্রধান — শাখা ধরে, হেডারের দেখা নয় (লাইভের ত্রুটি, ৬ অক্টোবর ২০২৬)। ⛔ আগে গোনা হত
             * দেখা গুদাম: "সব শাখা"-য় দ্বিতীয় শাখার প্রথম গুদাম প্রধান হত না, আর সেই শাখার কাউন্টার গুদাম না পেয়ে লট খালি দেখাত।
             */
            $branchHasMain = Warehouse::query()
                ->withoutGlobalScopes(['user-warehouse', Warehouse::VIEWED_BRANCH])
                ->whereKeyNot($warehouse->id)
                ->where('is_default', true)
                ->when($warehouse->branch_id === null, fn ($q) => $q->whereNull('branch_id'), fn ($q) => $q->where('branch_id', $warehouse->branch_id))
                ->exists();

            if (($data['is_default'] ?? false) || ! $branchHasMain) {
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

        /*
         * ⭐ মজুদের ইতিহাস আছে এমন গুদাম শাখা বদলায় না — Inventory অডিট ম২১, ৫ অক্টোবর ২০২৬।
         * ⛔ আগে বদলানো যেত: আগের সব চলাচল আর খাতার মজুদ পুরনো শাখায়, তাক নতুন শাখায় — শাখার মজুদ আর তার খাতা আলাদা
         * কথা বলত, আর পুরনো শাখার রিপোর্ট থেকে গুদামটাই হারাত। ⓘ নতুন শাখায় নতুন গুদাম খুলে মাল স্থানান্তর করতে হয়।
         */
        if (array_key_exists('branch_id', $data)
            && (int) ($data['branch_id'] ?? 0) !== (int) ($warehouse->branch_id ?? 0)
            && $this->hasHistory($warehouse)) {
            throw ValidationException::withMessages([
                'branch_id' => __('inventory::validation.warehouse_branch_has_history', ['warehouse' => $warehouse->name()]),
            ]);
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

        /*
         * ⭐ মাল বা ধরা/আটকানো থাকা গুদাম নিষ্ক্রিয় হয় না — Inventory অডিট ম২১, ৫ অক্টোবর ২০২৬।
         * ⛔ আগে হত: তালিকা আর বাছাই থেকে গুদামটা সরে যেত, অথচ তাকে মাল আর তার নামে ধরা আদেশ ও খোলা কাগজ — সেগুলো আর
         * পূরণ বা ছাড়ার পথ থাকত না। ⓘ আগে মাল সরিয়ে আর ধরা ছেড়ে তারপর।
         */
        if ($this->holdsAnything($warehouse)) {
            throw ValidationException::withMessages([
                'is_active' => __('inventory::validation.warehouse_still_holds_stock', ['warehouse' => $warehouse->name()]),
            ]);
        }

        $warehouse->refresh()->forceFill(['is_active' => false])->save();

        return $warehouse->fresh();
    }

    /**
     * আবার সক্রিয় করা।
     *
     * ── কেন নিষ্ক্রিয় করা একমুখী দরজা হতে পারে না ───────────────────
     * ফেরার পথ না থাকলে ভুল করে বন্ধ করা গুদামের জন্য ব্যবহারকারী
     * দ্বিতীয় একটা রেকর্ড খুলতেন — একই গুদাম দুইবার, দুইটা আলাদা
     * স্টক নিয়ে। তারপর মাল কোনটায় আছে সেই প্রশ্নের উত্তর থাকত না।
     * গ্রাহকে ঠিক এই যুক্তিতেই activate আছে।
     */
    public function activate(Warehouse $warehouse): Warehouse
    {
        $warehouse->refresh()->forceFill(['is_active' => true])->save();

        return $warehouse->fresh();
    }

    private function hasHistory(Warehouse $warehouse): bool
    {
        return StockMovement::query()->withoutGlobalScopes()
            ->where('company_id', $warehouse->company_id)
            ->where('warehouse_id', $warehouse->id)
            ->exists();
    }

    /** ⓘ যেকোনো ঘরে বাকি — তাক, বসার অপেক্ষা, ফ্রি, ধরা, আটকানো */
    private function holdsAnything(Warehouse $warehouse): bool
    {
        $row = StockMovement::query()->withoutGlobalScopes()
            ->where('company_id', $warehouse->company_id)
            ->where('warehouse_id', $warehouse->id)
            ->selectRaw('SUM(floor_change) as f, SUM(unplaced_change) as u, SUM(free_change) as fr, SUM(unplaced_free_change) as uf,
                         SUM(reserved_change) as r, SUM(free_reserved_change) as frr, SUM(hold_change) as h')
            ->first();

        foreach (['f', 'u', 'fr', 'uf', 'r', 'frr', 'h'] as $column) {
            if (bccomp((string) ($row->{$column} ?? '0'), '0', 4) !== 0) {
                return true;
            }
        }

        return false;
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
