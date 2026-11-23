<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Contracts\HomeSalesFilters;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Metrics\SalesArea;
use App\Modules\Sales\Models\SalesInvoice;

/**
 * হোমের ছাঁকনির উপকরণ, বিক্রয়ের দিক থেকে — মালিক, ৪ অক্টোবর ২০২৬ ([[HomeSalesFilters]], [[HomeFilter]])।
 *
 * ⓘ SR-এর তালিকা: ⭐ যাঁদের নামে এই কোম্পানিতে অন্তত একটা পাকা বিলের দায়িত্ব লেখা (`sales_person_id`; মালিক, ২৩ নভেম্বর ২০২৬) —
 * "এক নজরে" পাতার বিক্রেতারা। ⛔ আগে যাঁরা বিল কেটেছেন (`created_by`) — অফিসের অপারেটরও SR হয়ে তালিকায় আসতেন।
 * ⓘ এলাকা: [[SalesArea]]-এর ধাপের সব জায়গা, আর তার গ্রাহক সেই একই মই বেয়ে।
 */
final class SalesHomeFilters implements HomeSalesFilters
{
    public function choices(): array
    {
        $level = SalesArea::level();
        $sellerIds = SalesInvoice::query()->posted()->whereNotNull('sales_person_id')->distinct()->pluck('sales_person_id');

        return [
            'warehouses' => Warehouse::query()->orderBy('name_en')->get()
                ->mapWithKeys(fn (Warehouse $w) => [$w->id => $w->name()])->all(),
            'areas' => SalesArea::tree()->where('level', $level)->sortBy(fn ($l) => $l->name())
                ->mapWithKeys(fn ($l) => [$l->id => $l->name()])->all(),
            'sellers' => User::query()->whereIn('id', $sellerIds)->orderBy('name')->pluck('name', 'id')->all(),
        ];
    }

    public function customersInArea(int $areaId): array
    {
        $level = SalesArea::level();
        $tree = SalesArea::tree();

        $places = $tree->keys()->filter(fn (int $id) => SalesArea::of($id, $tree, $level)?->id === $areaId)->values()->all();

        if ($places === []) {
            return [];
        }

        return Customer::query()->whereIn('location_id', $places)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }
}
