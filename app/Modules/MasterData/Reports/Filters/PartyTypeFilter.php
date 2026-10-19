<?php

declare(strict_types=1);

namespace App\Modules\MasterData\Reports\Filters;

use App\Core\Engines\Report\ModelFilterSource;
use App\Modules\MasterData\Models\PartyType;
use Illuminate\Database\Eloquent\Builder;

/**
 * ⭐ রিপোর্টের "পক্ষের ধরন" ছাঁকনির বাছাই — ফোনের রিপোর্ট-পর্দা (১৭ অক্টোবর ২০২৬)। ⓘ গ্রাহক আর সরবরাহকারীর রিপোর্টের পাতার
 * একই তালিকা: চালু ধরন। নিবন্ধন `party_type_id` নামে — ইঞ্জিন যে নামে পড়ে।
 */
final class PartyTypeFilter extends ModelFilterSource
{
    public function label(): string
    {
        return 'master_data::menu.party_types';
    }

    protected function query(): Builder
    {
        return PartyType::query()->where('is_active', true)->orderBy('code');
    }
}
