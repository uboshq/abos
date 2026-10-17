<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Reports\Filters;

use App\Core\Engines\Report\ModelFilterSource;
use App\Modules\Accounts\Models\CostCenter;
use Illuminate\Database\Eloquent\Builder;

/**
 * ⭐ রিপোর্টের "প্রকল্প / খরচের কেন্দ্র" ছাঁকনির বাছাই — ফোনের রিপোর্ট-পর্দা (১৭ অক্টোবর ২০২৬)। ⓘ ওয়েবের পাতার একই তালিকা:
 * চালু কেন্দ্র। নিবন্ধন `cost_center_id` নামে — ইঞ্জিন যে নামে পড়ে।
 */
final class CostCentreFilter extends ModelFilterSource
{
    public function label(): string
    {
        return 'accounts::field.cost_center';
    }

    protected function query(): Builder
    {
        return CostCenter::query()->where('is_active', true)->orderBy('code');
    }
}
