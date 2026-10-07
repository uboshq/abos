<?php

declare(strict_types=1);

namespace App\Modules\Supplier\Reports\Filters;

use App\Core\Engines\Report\ModelFilterSource;
use App\Modules\Supplier\Models\Supplier;
use Illuminate\Database\Eloquent\Builder;

/**
 * রিপোর্টের ছাঁকনি "সরবরাহকারী" — রিপোর্ট সেন্টার ধাপ ১ ([[\App\Core\Contracts\ReportFilterSource]])।
 * ⓘ দেয়াল মডেলের নিজের: কোম্পানি।
 */
final class SupplierFilter extends ModelFilterSource
{
    public function label(): string
    {
        return 'core.report.filters.supplier_id';
    }

    protected function query(): Builder
    {
        return Supplier::query()->orderBy('code');
    }
}
