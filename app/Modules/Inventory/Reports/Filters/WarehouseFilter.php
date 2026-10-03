<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Reports\Filters;

use App\Core\Engines\Report\ModelFilterSource;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Database\Eloquent\Builder;

/**
 * রিপোর্টের ছাঁকনি "গুদাম" — রিপোর্ট সেন্টার ধাপ ১ ([[\App\Core\Contracts\ReportFilterSource]])।
 * ⓘ দেয়াল মডেলের নিজের: কোম্পানি।
 */
final class WarehouseFilter extends ModelFilterSource
{
    public function label(): string
    {
        return 'core.report.filters.warehouse_id';
    }

    protected function query(): Builder
    {
        return Warehouse::query()->orderBy('code');
    }
}
