<?php

declare(strict_types=1);

namespace App\Modules\MasterData\Reports\Filters;

use App\Core\Engines\Report\ModelFilterSource;
use App\Modules\MasterData\Models\ProductCategory;
use Illuminate\Database\Eloquent\Builder;

/**
 * রিপোর্টের ছাঁকনি "শ্রেণি" — রিপোর্ট সেন্টার ধাপ ১ ([[\App\Core\Contracts\ReportFilterSource]])।
 * ⓘ দেয়াল মডেলের নিজের: কোম্পানি।
 */
final class CategoryFilter extends ModelFilterSource
{
    public function label(): string
    {
        return 'core.report.filters.category_id';
    }

    protected function query(): Builder
    {
        return ProductCategory::query()->orderBy('code');
    }
}
