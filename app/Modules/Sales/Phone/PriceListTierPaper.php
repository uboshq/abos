<?php

declare(strict_types=1);

namespace App\Modules\Sales\Phone;

use App\Modules\Sales\Services\SalesPrice;

/** দর তালিকা — ধরনভিত্তিক ([[PriceListPaper]]) */
final class PriceListTierPaper extends PriceListPaper
{
    protected function target(): string
    {
        return SalesPrice::TIER;
    }
}
