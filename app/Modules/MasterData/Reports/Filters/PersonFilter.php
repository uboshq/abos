<?php

declare(strict_types=1);

namespace App\Modules\MasterData\Reports\Filters;

use App\Core\Engines\Report\ModelFilterSource;
use App\Modules\MasterData\Models\Person;
use Illuminate\Database\Eloquent\Builder;

/**
 * ⭐ রিপোর্টের "মানুষ" ছাঁকনির বাছাই — ব্যক্তির খতিয়ান, হাতে-ঋণ আর মূলধনের খাতা (ফোনের রিপোর্ট-পর্দা, ১৭ অক্টোবর ২০২৬)।
 * ⓘ ওয়েবের পাতা নিজের "পক্ষ" ঘরে এই একই মানুষদের আঁকে (`partyFilter`); মানুষ কোম্পানির, শাখার নয়।
 */
final class PersonFilter extends ModelFilterSource
{
    public function label(): string
    {
        return 'core.report.filters.person_id';
    }

    protected function query(): Builder
    {
        return Person::query()->orderBy('name_en');
    }
}
