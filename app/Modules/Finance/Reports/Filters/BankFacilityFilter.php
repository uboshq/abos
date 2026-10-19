<?php

declare(strict_types=1);

namespace App\Modules\Finance\Reports\Filters;

use App\Core\Engines\Report\ModelFilterSource;
use App\Modules\Finance\Models\BankFacility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * ⭐ রিপোর্টের "ব্যাংক ঋণ" ছাঁকনির বাছাই — ব্যাংক ঋণের খতিয়ান (ফোনের রিপোর্ট-পর্দা, ১৭ অক্টোবর ২০২৬)।
 * ⓘ ওয়েবের পাতার একই তালিকা আর একই দেয়াল: দেখার শাখার ঋণ, "কাগজের নম্বর · ব্যাংক" ([[FinanceReportController]])।
 */
final class BankFacilityFilter extends ModelFilterSource
{
    public function label(): string
    {
        return 'core.report.filters.facility_id';
    }

    protected function query(): Builder
    {
        return BankFacility::query()->inViewedBranch()->orderBy('bank');
    }

    protected function codeOf(Model $model): string
    {
        return (string) ($model->getAttribute('document_no') ?? '');
    }

    protected function nameOf(Model $model): string
    {
        return (string) ($model->getAttribute('bank') ?? '');
    }
}
