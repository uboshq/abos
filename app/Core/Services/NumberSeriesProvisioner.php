<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Module\ModuleRegistry;
use App\Models\FinancialYear;
use App\Models\NumberSeries;
use Illuminate\Support\Facades\DB;

/**
 * প্রতিটা মডিউলের ঘোষিত ডকুমেন্ট টাইপের জন্য নম্বর সিরিজ তৈরি।
 *
 * সিরিজগুলো হাতে লেখা তালিকা থেকে তৈরি হত, আর module.php-তে ঘোষিত
 * তালিকার সাথে সেটা মিলত না — খরচ ভাউচার ঘোষিত ছিল কিন্তু সিরিজ ছিল
 * না, তাই প্রথম খরচ ভাউচারটা লিখতে গিয়েই আটকে গিয়েছিল।
 *
 * এখন উৎস একটাই: মডিউল যা ঘোষণা করে, কোর তার জন্য সিরিজ বানায়
 * (সেকশন ১৯.৩)। নতুন মডিউল যোগ হলে বা নতুন ডকুমেন্ট টাইপ এলে এখানে
 * কিছু লিখতে হয় না।
 */
final class NumberSeriesProvisioner
{
    public function __construct(private readonly ModuleRegistry $registry) {}

    /**
     * উপসর্গের সুন্দর রূপ।
     *
     * কোডে "RV" লেখা থাকলেও কাগজে "RCV-2026-2027-0001" ছাপা হলে
     * ব্যবহারকারীর কাছে সেটা বেশি চেনা লাগে। যেগুলো এখানে নেই সেগুলোর
     * কোডটাই উপসর্গ হয় — নতুন মডিউল যোগ করতে এই তালিকা ছুঁতে হয় না।
     *
     * @var array<string, string>
     */
    private const FRIENDLY_PREFIX = [
        'SI' => 'INV',
        'SR' => 'SRT',
        'PI' => 'PUR',
        'PR' => 'PRT',
        'RV' => 'RCV',
        'PV' => 'PAY',
        'EV' => 'EXP',
        'JV' => 'JRN',
        'CV' => 'CON',
        'MT' => 'TRF',
        'CC' => 'CNT',
    ];

    /**
     * অনুপস্থিত সিরিজগুলো তৈরি — যা আছে তা ছোঁয়া হয় না।
     *
     * @return int কতগুলো নতুন সিরিজ তৈরি হল
     */
    public function provision(?FinancialYear $year = null): int
    {
        $year = $year ?? FinancialYear::query()->where('is_current', true)->first();

        if ($year === null) {
            return 0;
        }

        return DB::transaction(function () use ($year) {
            $created = 0;

            foreach ($this->registry->all() as $module) {
                foreach (array_keys($module->docTypes) as $docType) {
                    $exists = NumberSeries::query()
                        ->where('doc_type', $docType)
                        ->where('financial_year_id', $year->id)
                        ->exists();

                    if ($exists) {
                        continue;
                    }

                    NumberSeries::create([
                        'module' => $module->code,
                        'doc_type' => $docType,
                        'prefix' => self::FRIENDLY_PREFIX[$docType] ?? $docType,
                        'format' => '{PREFIX}-{FY}-{SEQ}',
                        'padding' => 4,
                        'next_number' => 1,
                        'start_number' => 1,
                        'financial_year_id' => $year->id,
                    ]);

                    $created++;
                }
            }

            return $created;
        });
    }

    /**
     * ঘোষিত অথচ সিরিজ নেই এমন ডকুমেন্ট টাইপগুলো।
     *
     * টেস্টে ব্যবহৃত হয়: একটা মডিউল নতুন ডকুমেন্ট টাইপ ঘোষণা করে সিরিজ
     * বসাতে ভুলে গেলে সেটা ধরা পড়ে তখনই, প্রথম ব্যবহারকারী ওই ফর্মটা
     * খোলার দিন নয়।
     *
     * @return list<string>
     */
    public function missing(?FinancialYear $year = null): array
    {
        $year = $year ?? FinancialYear::query()->where('is_current', true)->first();

        if ($year === null) {
            return [];
        }

        $have = NumberSeries::query()
            ->where('financial_year_id', $year->id)
            ->pluck('doc_type')
            ->all();

        $missing = [];

        foreach ($this->registry->all() as $module) {
            foreach (array_keys($module->docTypes) as $docType) {
                if (! in_array($docType, $have, true)) {
                    $missing[] = "{$module->code}: {$docType}";
                }
            }
        }

        return $missing;
    }
}
