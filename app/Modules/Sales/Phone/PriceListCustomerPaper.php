<?php

declare(strict_types=1);

namespace App\Modules\Sales\Phone;

use App\Modules\Sales\Services\SalesPrice;

/** দর তালিকা — গ্রাহকভিত্তিক ([[PriceListPaper]]) */
final class PriceListCustomerPaper extends PriceListPaper
{
    protected function target(): string
    {
        return SalesPrice::CUSTOMER;
    }
}
