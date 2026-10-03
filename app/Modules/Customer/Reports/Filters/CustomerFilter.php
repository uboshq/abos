<?php

declare(strict_types=1);

namespace App\Modules\Customer\Reports\Filters;

use App\Core\Engines\Report\ModelFilterSource;
use App\Modules\Customer\Models\Customer;
use Illuminate\Database\Eloquent\Builder;

/**
 * রিপোর্টের ছাঁকনি "গ্রাহক" — রিপোর্ট সেন্টার ধাপ ১ ([[\App\Core\Contracts\ReportFilterSource]])।
 * ⓘ দেয়াল মডেলের নিজের: কোম্পানি আর হেডারে বাছা শাখা; বিক্রয়কর্মীর নিজের-ডিলার পরিধি (⛔১৬) বসলে সেটাও এই কোয়েরিতেই আসবে।
 */
final class CustomerFilter extends ModelFilterSource
{
    public function label(): string
    {
        return 'core.report.filters.customer_id';
    }

    protected function query(): Builder
    {
        return Customer::query()->inViewedBranch()->orderBy('code');
    }
}
