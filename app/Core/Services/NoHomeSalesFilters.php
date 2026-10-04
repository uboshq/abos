<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Contracts\HomeSalesFilters;

/**
 * বিক্রয় বন্ধ — হোমে ছাঁকার কিছু নেই ([[HomeSalesFilters]])।
 */
final class NoHomeSalesFilters implements HomeSalesFilters
{
    public function choices(): array
    {
        return ['warehouses' => [], 'areas' => [], 'sellers' => []];
    }

    public function customersInArea(int $areaId): array
    {
        return [];
    }
}
