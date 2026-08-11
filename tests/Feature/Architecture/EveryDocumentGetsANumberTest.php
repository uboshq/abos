<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Module\ModuleRegistry;
use App\Core\Services\NumberSeriesProvisioner;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\NumberSeries;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * প্রতিটা ডকুমেন্ট তার নম্বরটা পায় — প্ল্যান WP-0.2, §৯.৪-এর ছয় নম্বর।
 *
 * ── কেন এই পাহারাটা লাগল ─────────────────────────────────────────────
 * একটা মডিউল module.php-তে নতুন ডকুমেন্ট টাইপ ঘোষণা করে, আর সিরিজটা
 * বসে কোম্পানি তৈরির সময়। মাঝখানে একটা ফাঁক আছে, আর সেটা তাত্ত্বিক নয়:
 *
 * ঋণের 'LN' ধরনটা যোগ হয়েছিল কোম্পানিগুলো তৈরি হওয়ার **পরে**। ফলে
 * অফিসের আসল কোম্পানিতে LN-এর কোনো সিরিজ ছিল না, আর ঋণ বসাতে গেলে
 * পর্দায় আসত `HTTP 500 — Server Error`। পরীক্ষক চারটা সংমিশ্রণ চেষ্টা
 * করে তবেই ধরতে পেরেছিলেন কখন ভাঙে, কারণ ভুলটা কিছুই বলত না।
 *
 * এখানে দুইটা আলাদা প্রশ্ন, দুইটাই দরকার:
 *
 *   ১. নতুন কোম্পানিতে প্রতিটা ঘোষিত ধরনের সিরিজ বসে কি?
 *   ২. পুরনো কোম্পানিতে **পরে যোগ হওয়া** ধরনটা নিজে থেকে বসে কি?
 *
 * দ্বিতীয়টাই আসল পরীক্ষা। প্রথমটা পাশ করলেও দ্বিতীয়টা ভাঙতে পারে, আর
 * ঠিক সেটাই হয়েছিল।
 */
class EveryDocumentGetsANumberTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
    }

    /**
     * ঘোষিত অথচ সিরিজ নেই — এমন একটাও থাকতে পারবে না।
     *
     * তালিকাটা রেজিস্ট্রি থেকে আসে, হাতে লেখা নয়। নতুন মডিউল এলে সে
     * নিজে থেকেই এই পরীক্ষার আওতায় পড়ে — নাহলে ঠিক যেদিন নতুন কিছু
     * যোগ হয়, সেদিনই পাহারাটা চুপ করে যেত।
     */
    public function test_every_declared_document_type_has_a_series(): void
    {
        $missing = app(NumberSeriesProvisioner::class)->missing();

        $this->assertSame([], $missing, implode("\n", [
            'এই ডকুমেন্ট টাইপগুলো ঘোষিত, কিন্তু কোনো নম্বর সিরিজ নেই।',
            'প্রথম যে মানুষটা এই ফর্মটা খুলবেন, তিনি পাবেন HTTP 500।',
            ...$missing,
        ]));
    }

    /**
     * একটা ধরনও যেন দুইবার না বসে।
     *
     * দুইটা সিরিজ থাকলে lockSeries() যেকোনো একটা তুলত, আর দুইটা আলাদা
     * কাউন্টার থেকে নম্বর বেরোত — একই নম্বরের দুইটা বিল, দুই দিনে।
     */
    public function test_no_document_type_has_two_series_in_one_year(): void
    {
        $duplicates = NumberSeries::query()
            ->selectRaw('doc_type, financial_year_id, COUNT(*) as total')
            ->groupBy('doc_type', 'financial_year_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('doc_type')
            ->all();

        $this->assertSame([], $duplicates,
            'একই বছরে একই ধরনের দুইটা সিরিজ — একই নম্বর দুইবার বসতে পারে: '
            .implode(', ', $duplicates));
    }

    /**
     * পুরনো কোম্পানিতে পরে যোগ হওয়া ধরনটাও কাজ করে।
     *
     * ── কেন সিরিজটা মুছে পরীক্ষা করা হয় ─────────────────────────────
     * এটাই অফিসের আসল পরিস্থিতির হুবহু নকল: কোম্পানিটা তৈরি হয়েছিল
     * যখন 'LN' বলে কিছু ছিল না, তাই সিরিজটাও তৈরি হয়নি। মুছে দিলে
     * ডাটাবেজটা ঠিক ওই দিনের অবস্থায় ফিরে যায়।
     *
     * সিরিজ না বসিয়ে শুধু `knows()` ডাকলে পরীক্ষাটা পাশ করত অথচ
     * ব্যবহারকারী তবু 500 পেতেন — কারণ আসল কাজটা next() করে, knows()
     * নয়।
     */
    public function test_a_type_added_after_the_company_was_made_still_gets_a_number(): void
    {
        $engine = app(NumberSeriesEngine::class);

        foreach (array_keys($this->declaredDocTypes()) as $docType) {
            NumberSeries::query()->where('doc_type', $docType)->delete();

            $number = $engine->next($docType);

            $this->assertNotSame('', $number,
                "'{$docType}' ধরনের সিরিজ না থাকলে নম্বর বসেনি — পুরনো কোম্পানিতে "
                .'এই ফর্মটা খুললেই HTTP 500।');
        }
    }

    /** রেজিস্ট্রি ধরে সব মডিউলের ঘোষিত ধরন — হাতে লেখা তালিকা নয়। */
    private function declaredDocTypes(): array
    {
        $types = [];

        foreach (app(ModuleRegistry::class)->all() as $module) {
            $types += $module->docTypes;
        }

        return $types;
    }
}
