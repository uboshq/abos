<?php

declare(strict_types=1);

namespace App\Modules\Sales\Phone;

use App\Modules\Sales\Services\SalesPrice;

/** দর তালিকা — সবার ([[PriceListPaper]]) */
final class PriceListAllPaper extends PriceListPaper
{
    protected function target(): string
    {
        return SalesPrice::ALL;
    }
}
