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

        /*
         * সরবরাহকারীকে পরিশোধ — PAY নয়, PMT।
         *
         * হিসাবের পরিশোধ ভাউচার (PV) ইতিমধ্যেই PAY উপসর্গ নেয়। ক্রয়ের
         * পরিশোধকেও PAY দিলে দুইটা আলাদা কাগজে একই নম্বর ছাপা হত —
         * PAY-2026-2027-0001 দুইবার, দুই জায়গায়। কাগজ মেলাতে গিয়ে
         * কেউ বুঝতই না কোনটার কথা হচ্ছে।
         */
        'SP' => 'PMT',
        'EV' => 'EXP',
        'JV' => 'JRN',
        'CV' => 'CON',
        'MT' => 'TRF',
        'CC' => 'CNT',
    ];

    /**
     * নম্বরের ছক — মাস্টারের পরিচয়ে অর্থবছর থাকে না।
     *
     * ── কেন থাকে না ─────────────────────────────────────────────────
     * একটা বিল ২০২৬-২৭ অর্থবছরের — তারিখটা তার পরিচয়ের অংশ। কিন্তু
     * একটা দোকান বা একটা পণ্য কোনো বছরের নয়; সে বছরের পর বছর একই
     * থাকে। CUS-2026-2027-0001 পড়তে গিয়ে চোখ প্রতিবার একটা অপ্রাসঙ্গিক
     * সংখ্যা পার হয়, আর তালিকার কলামে ওটা দুই লাইনে ভেঙে যায়।
     *
     * ── কোরের এই মডিউলগুলোর নাম জানার দরকার নেই (নিয়ম ১৯.৭) ────────
     * লেবেলের চাবিটাই বলে দেয়: যেটা "…_code"-এ শেষ, সেটা কোনো কাগজ নয়,
     * একটা জিনিসের পরিচয় — পণ্য, গুদাম, কর্মী, গ্রাহক। মডিউল নিজেই
     * নামটা বেছে দেয়, কোর শুধু নিয়মটা পড়ে।
     */
    private static function formatFor(string $labelKey): string
    {
        return str_ends_with($labelKey, '_code')
            ? '{PREFIX}-{SEQ}'
            : '{PREFIX}-{FY}-{SEQ}';
    }

    /**
     * কোনো মডিউল কি সত্যিই এই ডকুমেন্ট টাইপটা ঘোষণা করেছে?
     *
     * সিরিজ না পেলে ইঞ্জিন এটা জিজ্ঞেস করে। ঘোষিত হলে সিরিজটা নিজে
     * বসিয়ে নেয় (পুরনো কোম্পানিতে নতুন ফিচার এলে এটাই লাগে); অঘোষিত
     * হলে ব্যতিক্রম ছোড়ে — টাইপো থেকে নীরবে একটা সিরিজ জন্মানোর চেয়ে
     * থেমে যাওয়া ভালো।
     */
    public function knows(string $docType): bool
    {
        foreach ($this->registry->all() as $module) {
            if (array_key_exists($docType, $module->docTypes)) {
                return true;
            }
        }

        return false;
    }

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

            /*
             * কোন ধরনগুলো ইতিমধ্যে আছে — একবারে, ধরনপ্রতি নয়।
             *
             * আগে প্রতিটা ডকুমেন্ট টাইপের জন্য আলাদা করে "আছে কি না"
             * জিজ্ঞেস করা হত। ২৩টা ধরনের জন্য ২৩টা কোয়েরি, আর এই
             * কাজটা নম্বর সিরিজের পাতা খোলার সময় প্রতিবারই চলে —
             * অর্থাৎ প্রায় সবসময়ই উত্তর "হ্যাঁ, আছে", আর তবু ২৩বার
             * জিজ্ঞেস করা হত।
             *
             * তালিকাটা লুপের ভেতরেই বাড়ে, তাই নতুন বসানো সিরিজ
             * দ্বিতীয়বার বসে না — আগের exists() ঠিক এই কাজটাই করত।
             */
            $existing = NumberSeries::query()
                ->where('financial_year_id', $year->id)
                ->pluck('doc_type')
                ->flip();

            foreach ($this->registry->all() as $module) {
                foreach (array_keys($module->docTypes) as $docType) {
                    if ($existing->has($docType)) {
                        continue;
                    }

                    NumberSeries::create([
                        'module' => $module->code,
                        'doc_type' => $docType,
                        'prefix' => self::FRIENDLY_PREFIX[$docType] ?? $docType,
                        'format' => self::formatFor($module->docTypes[$docType]),
                        'padding' => 4,
                        'next_number' => 1,
                        'start_number' => 1,
                        'financial_year_id' => $year->id,
                    ]);

                    $existing->put($docType, true);
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
