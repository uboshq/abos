<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Models;

/**
 * ⭐ বিদায়ের দাখিলার উৎস — সম্পদেরই সারি, নিজের ড্রিল-নাম (স্থায়ী সম্পদ ধাপ ১, ১০ অক্টোবর ২০২৬)।
 *
 * ⓘ বিদায়ের দাখিলা `asset_disposal` নামে বসে, আইডি সম্পদের ([[FixedAsset::disposalSourceType()]])। ড্রিলের মানচিত্রে নাম আর
 * ক্লাসের নিজের নাম এক হতে হয় ([[EveryDrillSourceCanActuallyBeDrilledIntoTest]]) — তাই একই টেবিলের পাতলা উপশ্রেণি, যাতে
 * খাতার ঐ সারি থেকেও সম্পদের পাতা খোলে।
 */
class AssetDisposalPaper extends FixedAsset
{
    public static function drillSourceType(): string
    {
        return parent::disposalSourceType();
    }
}
