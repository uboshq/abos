<?php

declare(strict_types=1);

namespace App\Modules\MasterData\Reports\Filters;

use App\Core\Engines\Report\ModelFilterSource;
use App\Modules\MasterData\Models\Location;
use Illuminate\Database\Eloquent\Builder;

/**
 * রিপোর্টের ছাঁকনি "এলাকা/পয়েন্ট" — রিপোর্ট সেন্টার ধাপ ১ ([[\App\Core\Contracts\ReportFilterSource]])।
 * ⓘ দেয়াল মডেলের নিজের: কোম্পানি; রিপোর্ট নিজে সাব-ট্রি ধরে।
 */
final class LocationFilter extends ModelFilterSource
{
    public function label(): string
    {
        return 'core.report.filters.location_id';
    }

    protected function query(): Builder
    {
        return Location::query()->orderBy('code');
    }
}
