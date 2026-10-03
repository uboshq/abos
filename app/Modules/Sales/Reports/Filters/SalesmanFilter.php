<?php

declare(strict_types=1);

namespace App\Modules\Sales\Reports\Filters;

use App\Core\Engines\Report\ModelFilterSource;
use App\Core\Support\CompanyContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * রিপোর্টের ছাঁকনি "বিক্রয়কর্মী" — রিপোর্ট সেন্টার ধাপ ১; ঘোষণা বিক্রয় মডিউলের, কোরের নয় (সমন্বয়কের শর্ত ক)।
 *
 * ⓘ এই কোম্পানির সদস্যরা (কোড = ইমেইল, এক নামে দুইজন থাকলেও আলাদা)। ⚠️ "বিক্রয়কর্মী কেবল নিজের" পরিধি এখনো
 * নেই — সেটা ⛔১৬-এর কাজ, আর বসলে এই কোয়েরিতেই আসবে। অর্ধেক নিয়ম আজ বসানো হয়নি: হয় ব্যবস্থাপক আটকাতেন,
 * নয় ফাঁক থাকত। ⛔ তালিকার বাইরের নম্বর (অন্য কোম্পানির লোক) [[ReportFilters::resolve()]] ফেরায়।
 */
final class SalesmanFilter extends ModelFilterSource
{
    public function label(): string
    {
        return 'core.report.filters.salesman_id';
    }

    protected function query(): Builder
    {
        return User::query()
            ->whereHas('companies', fn ($q) => $q->whereKey(CompanyContext::id()))
            ->orderBy('name');
    }

    protected function codeOf(Model $model): string
    {
        return (string) ($model->getAttribute('email') ?? '');
    }

    protected function nameOf(Model $model): string
    {
        return (string) $model->getAttribute('name');
    }
}
