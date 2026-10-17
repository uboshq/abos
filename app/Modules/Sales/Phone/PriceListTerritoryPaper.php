<?php

declare(strict_types=1);

namespace App\Modules\Sales\Phone;

use App\Modules\Sales\Services\SalesPrice;

/** দর তালিকা — এলাকাভিত্তিক ([[PriceListPaper]]) */
final class PriceListTerritoryPaper extends PriceListPaper
{
    protected function target(): string
    {
        return SalesPrice::TERRITORY;
    }
}
