<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Reports\Filters;

use App\Core\Engines\Report\ModelFilterSource;
use App\Modules\Inventory\Models\Product;
use Illuminate\Database\Eloquent\Builder;

/**
 * রিপোর্টের ছাঁকনি "পণ্য" — রিপোর্ট সেন্টার ধাপ ১ ([[\App\Core\Contracts\ReportFilterSource]])।
 * ⓘ দেয়াল মডেলের নিজের: কোম্পানি আর হেডারে বাছা শাখায় বিক্রয়যোগ্য পণ্য।
 */
final class ProductFilter extends ModelFilterSource
{
    public function label(): string
    {
        return 'core.report.filters.product_id';
    }

    protected function query(): Builder
    {
        return Product::query()->soldInViewedBranch()->orderBy('code');
    }
}
