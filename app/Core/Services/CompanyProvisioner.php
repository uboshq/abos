<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Module\ModuleRegistry;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * একটা নতুন কোম্পানি চালু করা — সবটা, একবারে।
 *
 * ── কেন এটা একটা সার্ভিস, কন্ট্রোলারে ছড়ানো কোড নয় ──────────────────
 * একটা কোম্পানি শুধু একটা সারি নয়। সে চালু হতে গেলে তার সাথে লাগে
 * অন্তত একটা শাখা, একটা অর্থবছর, নম্বর সিরিজ, হিসাবের প্রমিত ছক, আর
 * মাস্টার তালিকাগুলো (একক, কর, শর্ত, কারণ কোড)।
 *
 * এর একটাও বাদ পড়লে কোম্পানিটা তৈরি হয় ঠিকই, কিন্তু **কাজ করে না** —
 * আর সেটা টের পাওয়া যায় অনেক পরে, প্রথম বিল লিখতে গিয়ে:
 *
 *   অর্থবছর নেই   → কোনো লেনদেনের তারিখ বৈধ নয়
 *   সিরিজ নেই     → ডকুমেন্টের নম্বর বসে না
 *   ছক নেই        → দাখিলা বসার কোনো খাত নেই
 *   একক নেই       → পণ্যই বানানো যায় না
 *
 * সিডারে এই রেসিপিটা আগে থেকেই ছিল, কিন্তু কেবল সিডারে। পর্দা থেকে
 * কোম্পানি বানানোর পথ খুললে দুই জায়গায় দুইটা রেসিপি থাকত, আর একদিন
 * একটায় নতুন ধাপ যোগ হত আর অন্যটায় না — তখন পর্দা দিয়ে বানানো
 * কোম্পানিগুলো নীরবে অসম্পূর্ণ হয়ে থাকত।
 *
 * সিডার এখন এই সার্ভিসটাই ডাকে (DemoSeeder::setUpCompany), তাই ডেমো
 * আর আসল কোম্পানি হুবহু এক পথে তৈরি হয় — যা ডেমোতে কাজ করে, তা আসলেও
 * করে।
 */
final class CompanyProvisioner
{
    public function __construct(
        private readonly NumberSeriesProvisioner $series,
        private readonly ModuleRegistry $modules,
    ) {}

    /**
     * নতুন কোম্পানি, তার প্রধান শাখা, আর চালু হওয়ার সব কিছু।
     *
     * @param  array<string, mixed>  $data  কোম্পানির নিজের ঘরগুলো
     * @param  array<string, mixed>  $branch  প্রধান শাখা
     * @param  array<string, mixed>  $year  অর্থবছর — নাম ও দুই তারিখ
     */
    public function create(array $data, array $branch, array $year): Company
    {
        return DB::transaction(function () use ($data, $branch, $year) {
            $company = Company::create([
                ...$data,
                'currency' => $data['currency'] ?? 'BDT',
                'locale' => $data['locale'] ?? 'bn',
                'is_active' => true,
            ]);

            $this->setUp($company, [$branch], $year);

            return $company->fresh();
        });
    }

    /**
     * খালি একটা কোম্পানিকে কাজের অবস্থায় আনা।
     *
     * আলাদা করে রাখা হয়েছে যাতে সিডারও এটাই ডাকতে পারে — কোম্পানির
     * সারিটা সে নিজে বানায়, কিন্তু বাকিটা এখান থেকেই আসে।
     *
     * @param  list<array<string, mixed>>  $branches
     * @param  array<string, mixed>  $year
     */
    public function setUp(Company $company, array $branches, array $year): void
    {
        CompanyContext::forCompany($company->id, function () use ($branches, $year) {
            foreach ($branches as $branch) {
                Branch::create($branch);
            }

            $financialYear = FinancialYear::create([
                'name' => $year['name'],
                'starts_on' => $year['starts_on'],
                'ends_on' => $year['ends_on'],
                'is_current' => true,
            ]);

            /*
             * ক্রমটা বদলানো যাবে না।
             *
             * সিরিজ অর্থবছর ধরে বসে, তাই বছরটা আগে। মডিউলের ভিত্তি
             * সারিগুলো তার পরে — কর তার হিসাবের খাত খোঁজে, আর খাত না
             * থাকলে করের সারিটাই বসে না।
             *
             * ── কেন এখানে কোনো মডিউলের নাম নেই ──────────────────────
             * আগে এই তিনটা লাইনের দুইটা ছিল `$this->chart->install()`
             * আর `$this->lists->installDefaults()` — অর্থাৎ কোর জানত
             * accounts ও master_data নামে দুইটা মডিউল আছে, আর তাদের
             * সার্ভিসের নাম কী (§১৯.৭ ভাঙত)।
             *
             * তার আসল দাম ছিল নীরব: HR বা অন্য কোনো মডিউল নিজের
             * ভিত্তি সারি নিয়ে এলে সেগুলো নতুন কোম্পানিতে বসত না,
             * কারণ বসাতে হলে এই ফাইলটা খুলে আরেকটা লাইন লিখতে হত।
             *
             * ক্রমটা এখনো ঠিক থাকে: ModuleRegistry::all() নির্ভরতার
             * ক্রমে ফেরত দেয়, তাই accounts (যার নির্ভরতা নেই) সবসময়
             * master_data-র (যে accounts-এর উপর দাঁড়ায়) আগে চলে।
             */
            $this->series->provision($financialYear);

            foreach ($this->modules->all() as $module) {
                foreach ($module->provisions as $service) {
                    app($service)->provisionCompany();
                }
            }
        });
    }

    /**
     * একজন ব্যবহারকারীকে এই কোম্পানিতে ঢোকার অধিকার দেওয়া।
     *
     * ── কেন এটা কোম্পানি বানানোর অংশ ────────────────────────────────
     * যে মানুষটা কোম্পানিটা বানালেন, তিনি ওটায় ঢুকতে না পারলে পুরো
     * কাজটাই অর্থহীন — তালিকায় নতুন নামটা দেখা যেত, কিন্তু বেছে নেওয়া
     * যেত না, আর কেন যেত না তার কোনো ব্যাখ্যাও থাকত না।
     */
    public function grantAccess(Company $company, User $user, string $role = PermissionSyncer::OWNER_ROLE): void
    {
        $user->companies()->syncWithoutDetaching([$company->id]);

        CompanyContext::forCompany($company->id, function () use ($user, $role) {
            $user->assignRole($role);
        });
    }

    /**
     * এই অর্থবছরটা কোন তারিখ থেকে কোন তারিখ — বাংলাদেশের নিয়মে।
     *
     * জুলাই থেকে জুন। তারিখটা আগে থেকে বসানো থাকে যাতে কেউ ভুল করে
     * ক্যালেন্ডার বছর (জানুয়ারি–ডিসেম্বর) না বসায় — বসালে প্রতিটা
     * রিপোর্টের সময়সীমা কর বিভাগের সাথে অমিল হত।
     *
     * @return array{name: string, starts_on: string, ends_on: string}
     */
    public static function currentBangladeshiYear(?Carbon $on = null): array
    {
        $on ??= Carbon::today();

        $startYear = $on->month >= 7 ? $on->year : $on->year - 1;

        return [
            'name' => $startYear.'-'.($startYear + 1),
            'starts_on' => Carbon::create($startYear, 7, 1)->toDateString(),
            'ends_on' => Carbon::create($startYear + 1, 6, 30)->toDateString(),
        ];
    }
}
