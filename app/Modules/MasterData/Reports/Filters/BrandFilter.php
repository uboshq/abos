<?php

declare(strict_types=1);

namespace App\Modules\MasterData\Reports\Filters;

use App\Core\Engines\Report\ModelFilterSource;
use App\Modules\MasterData\Models\Brand;
use Illuminate\Database\Eloquent\Builder;

/**
 * রিপোর্টের ছাঁকনি "ব্র্যান্ড" — রিপোর্ট সেন্টার ধাপ ১ ([[\App\Core\Contracts\ReportFilterSource]])।
 * ⓘ দেয়াল মডেলের নিজের: কোম্পানি।
 */
final class BrandFilter extends ModelFilterSource
{
    public function label(): string
    {
        return 'core.report.filters.brand_id';
    }

    protected function query(): Builder
    {
        return Brand::query()->orderBy('code');
    }
}
