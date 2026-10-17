<?php

declare(strict_types=1);

namespace App\Modules\Finance\Reports\Filters;

use App\Core\Engines\Report\ModelFilterSource;
use App\Modules\Finance\Models\RentalContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * ⭐ রিপোর্টের "ভাড়ার চুক্তি" ছাঁকনির বাছাই — জামানতের খাতা (ফোনের রিপোর্ট-পর্দা, ১৭ অক্টোবর ২০২৬)।
 * ⓘ ওয়েবের পাতার একই তালিকা: দেখার শাখার চালু আর শেষ চুক্তি, "কাগজের নম্বর · পক্ষ" ([[RentalReportController]])।
 */
final class RentalContractFilter extends ModelFilterSource
{
    public function label(): string
    {
        return 'core.report.filters.rental_contract_id';
    }

    protected function query(): Builder
    {
        return RentalContract::query()->inViewedBranch()
            ->whereIn('status', [RentalContract::ACTIVE, RentalContract::CLOSED])
            ->orderBy('counterparty');
    }

    protected function codeOf(Model $model): string
    {
        return (string) ($model->getAttribute('document_no') ?? '');
    }

    protected function nameOf(Model $model): string
    {
        return (string) ($model->getAttribute('counterparty') ?? '');
    }
}
