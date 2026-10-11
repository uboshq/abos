<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Models;

/**
 * ⭐ ক্রয় বিলের মাল সম্পদে তোলার দাখিলার উৎস — সম্পদেরই সারি, নিজের ড্রিল-নাম (স্থায়ী সম্পদ ধাপ ১, ১০ অক্টোবর ২০২৬)।
 *
 * ⓘ দাখিলাটা ক্রয়ের ([[BillLinesForAssets::SOURCE]]), আইডি সম্পদের। ড্রিলের মানচিত্রে নাম আর ক্লাসের নিজের নাম এক হতে হয়
 * ([[EveryDrillSourceCanActuallyBeDrilledIntoTest]]) — তাই একই টেবিলের পাতলা উপশ্রেণি।
 */
class AssetCapitalisationPaper extends FixedAsset
{
    public const SOURCE = 'asset_capitalise';

    public static function drillSourceType(): string
    {
        return self::SOURCE;
    }
}
