<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Reports\Filters;

use App\Core\Engines\Report\ModelFilterSource;
use App\Core\Services\DataScope;
use App\Modules\Inventory\Models\Batch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * ⭐ রিপোর্টের "লট" ছাঁকনির বাছাই — লটের খোঁজ (ফোনের রিপোর্ট-পর্দা, ১৭ অক্টোবর ২০২৬)। ⓘ "লট নম্বর · পণ্য"।
 * ⛔ দেয়াল: দেখার শাখার লট, আর শাখাহীন লট — অন্য শাখার লট তালিকায় আসে না, আর হাতে বসালে রিপোর্টই ফেরে।
 */
final class BatchFilter extends ModelFilterSource
{
    public function label(): string
    {
        return 'core.report.filters.batch_id';
    }

    protected function query(): Builder
    {
        $ids = app(DataScope::class)->viewBranchIds(auth()->user());

        return Batch::query()->with('product')
            ->when($ids !== null, fn (Builder $q) => $q->where(fn (Builder $w) => $w->whereIn('branch_id', $ids)->orWhereNull('branch_id')))
            ->orderBy('batch_no');
    }

    protected function codeOf(Model $model): string
    {
        return (string) ($model->getAttribute('batch_no') ?? '');
    }

    protected function nameOf(Model $model): string
    {
        $product = $model->getRelation('product');

        return $product === null ? '' : parent::nameOf($product);
    }
}
