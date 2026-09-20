<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Services\NumberSeriesProvisioner;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\NumberSeries;
use App\Models\User;
use App\Modules\Accounts\Services\YearEndService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * নম্বর প্রতি বছর ১ থেকে শুরু হবে কি না — কে ঠিক করে।
 *
 * ── ⚠️ এই ফাইলটা একটা ভুল থেকে জন্মেছে, ৫ সেপ্টেম্বর ২০২৬ ────────────
 * মালিক জিজ্ঞেস করলেন নম্বরের নিয়ন্ত্রণ কন্ট্রোল প্যানেলে আছে কি না।
 * আমি ধরে নিলাম নেই, আর কোম্পানি-ব্যাপী একটা সুইচ বসিয়ে ফেললাম।
 * ⛔ **জিনিসটা আগে থেকেই ছিল** — `number_series.reset_yearly`, কাগজ ধরে।
 *
 * ── ⛔ আর তারপর, ২০ সেপ্টেম্বর ২০২৬: সেটা লাইভে ভাঙা পাওয়া গেল ──────
 * ১৬০টা সারির **১৬০টাতেই** রিসেট চালু, আর **একটাতেও** ছকে বছর নেই।
 * ⓘ বছর বন্ধ হলে গুনতি ১-এ ফেরে, নম্বর হয় আগের বছরের হুবহু সমান,
 * `issued_numbers`-এর unique সেটা আটকায়, লেনদেন rollback হয় — আর
 * rollback-এ `next_number`-এর বাড়াটাও মুছে যায়। ⚠️ তাই নতুন বছরের
 * প্রথম কাগজটা **কখনো** কাটা যেত না, যতবারই চেষ্টা হোক।
 *
 * ── ⭐ কেন এই ফাইলটা আগে ধরেনি ─────────────────────────────────────────
 * এখানকার দুইটা দাবি কিছুই মাপত না: একটা বলত `reset_yearly` মান
 * `[true, false]`-এর একটা (যেকোনো বুলিয়ান পাস করে), আরেকটা **সোর্সে
 * একটা লাইন খুঁজত**। ⛔ অর্থাৎ পরীক্ষাটা কোডের চেহারা দেখত, কাজ নয়।
 * ⭐ এখন সে বছর বন্ধ করে পরের বছরের প্রথম কাগজটা সত্যিই কাটে।
 */
class TheNumbersRestartedEveryYearAndNobodyCouldSayOtherwiseTest extends TestCase
{
    use RefreshDatabase;

    private FinancialYear $year;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->year = FinancialYear::query()->where('is_current', true)->firstOrFail();
    }

    /**
     * ⭐⭐ আসল প্রশ্নটা: নতুন বছরের প্রথম কাগজটা কাটা যায় কি?
     *
     * ⚠️ "`next_number` ১ হয়েছে" মেপে লাভ নেই — ওটা তো রোগের **লক্ষণ**।
     * ⓘ প্রশ্নটা হলো কাগজটা বেরোল কি না, আর সেটা জানার একমাত্র পথ
     * সত্যিই একটা নম্বর ইস্যু করা।
     */
    public function test_the_first_paper_of_the_new_year_can_actually_be_cut(): void
    {
        $this->assertFalse(
            NumberSeriesProvisioner::resetsWith((string) $this->series('INV')->format),
            'ডেমোর চালানের ছকে বছর আছে — তাহলে এই পরীক্ষাটা রোগটাই বসাতে পারছে না।',
        );

        $old = app(NumberSeriesEngine::class)->next('INV', date: $this->year->ends_on->copy());

        /*
         * ⚠️ ভুল মানটা বসানো হয় নম্বর কাটার **পরে**, আর মডেলকে পাশ
         * কাটিয়ে — দুইটাই ইচ্ছাকৃত।
         *
         * ⓘ লাইভে মানটা ঠিক এভাবেই এসেছে: কেউ লেখেনি, কলামের default
         * বসে গেছে। ⛔ আর আগে বসালে পরীক্ষাটা নিজেই নিজের রোগ সারিয়ে
         * ফেলত: নম্বর কাটার সময় ইঞ্জিন সিরিজটা সেভ করে, আর তখনই সারির
         * নিজের পাহারা পতাকাটা নিভিয়ে দেয়। ⭐ তাতে বছর বদলের লাইনটা
         * আর মাপাই হত না — মিউটেশনে ঠিক সেটাই ধরা পড়েছে।
         */
        DB::table('number_series')->update(['reset_yearly' => 1]);

        app(YearEndService::class)->close($this->year);

        $new = FinancialYear::query()->where('starts_on', '>', $this->year->ends_on)->orderBy('starts_on')->firstOrFail();

        /*
         * ⛔ এই ডাকটাই আগে ছুড়ত: QueryException, unique ভাঙার কারণে।
         * ⚠️ আর rollback-এ গুনতির বাড়াটাও মুছে যেত, তাই দ্বিতীয় চেষ্টাও
         * একই নম্বর চাইত — সিরিজটা চিরকাল ওখানেই আটকে থাকত।
         */
        $first = app(NumberSeriesEngine::class)->next('INV', date: $new->starts_on->copy());

        $this->assertNotSame($old, $first,
            "নতুন বছরের প্রথম চালান {$first} — আগের বছরের {$old}-এর হুবহু সমান।");

        // ⓘ আর নম্বরটা সত্যিই লেখা হয়েছে, কেবল ফেরত আসেনি
        $this->assertDatabaseHas('issued_numbers', [
            'company_id' => CompanyContext::id(),
            'document_no' => $first,
        ]);
    }

    /**
     * ⭐ ছকে বছর থাকলে রিসেট চলে — আর তখন ১ থেকে শুরু করাই নিরাপদ।
     *
     * ⓘ `INV-2027-2028-0001` আর `INV-2026-2027-0001` আলাদা দুইটা নম্বর,
     * তাই গুনতি ফিরে গেলেও সংঘর্ষ হয় না।
     */
    public function test_a_year_in_the_format_is_what_makes_a_reset_safe(): void
    {
        $series = $this->series('INV');
        $series->forceFill(['format' => '{PREFIX}-{FY}-{SEQ}', 'reset_yearly' => true])->save();

        $this->assertTrue($series->fresh()->reset_yearly, 'বছরওয়ালা ছকেও রিসেট বসেনি।');

        $before = app(NumberSeriesEngine::class)->next('INV', date: $this->year->ends_on->copy());

        app(YearEndService::class)->close($this->year);

        $new = FinancialYear::query()->where('starts_on', '>', $this->year->ends_on)->orderBy('starts_on')->firstOrFail();
        $after = app(NumberSeriesEngine::class)->next('INV', date: $new->starts_on->copy());

        $this->assertStringContainsString($this->year->name, $before);
        $this->assertStringContainsString($new->name, $after, 'নতুন বছরের নম্বরে নতুন বছরটাই বসার কথা।');

        $this->assertSame(
            1,
            (int) NumberSeries::query()
                ->where('doc_type', 'INV')
                ->where('financial_year_id', $new->id)
                ->value('next_number') - 1,
            'বছরওয়ালা সিরিজে গুনতি ১ থেকে শুরু হয়নি।',
        );
    }

    /**
     * ⛔ বছর ছাড়া ছকে রিসেট বসতেই পারে না — কে বসাতে চাইল তা নির্বিশেষে।
     *
     * ⚠️ নিয়মটা আগে কেবল নম্বর সিরিজের **পর্দায়** ছিল। তাই হাতে বানালে
     * মানা হত, প্রভিশনার বানালে হত না, আর বছর বদলের সময় পুরনো ভুল মানটা
     * হুবহু বয়ে নেওয়া হত। ⭐ এখন নিয়মটা সারিটার নিজের উপর।
     */
    public function test_a_series_without_a_year_cannot_be_made_to_reset(): void
    {
        $series = $this->series('INV');
        $series->forceFill(['format' => '{PREFIX}-{SEQ}', 'reset_yearly' => true])->save();

        $this->assertFalse($series->fresh()->reset_yearly,
            'বছরহীন ছকে বছর-রিসেট বসে গেছে — এটাই লাইভের ১৬০টা সারির রোগ।');
    }

    /**
     * ⭐ নিজে থেকে বসানো সারিগুলোও নিয়মটা মানে।
     *
     * ⓘ প্রভিশনার ঘরটা লিখতই না, আর কলামের default `true` — সেখান থেকেই
     * লাইভের সব সারি ভুল মান নিয়ে জন্মেছিল।
     */
    public function test_series_the_system_creates_obey_the_rule_too(): void
    {
        $wrong = NumberSeries::query()->get()->filter(
            fn (NumberSeries $s) => $s->reset_yearly && ! NumberSeriesProvisioner::resetsWith((string) $s->format)
        );

        $this->assertGreaterThan(5, NumberSeries::query()->count(),
            'ডেমোতে সিরিজই বসেনি — তাহলে দাবিটা কিছুই মাপত না।');

        $this->assertSame([], $wrong->pluck('doc_type')->all(),
            'এই সারিগুলোর ছকে বছর নেই, অথচ বছর-রিসেট চালু।');
    }

    /**
     * ⭐ সিদ্ধান্তটা কাগজ ধরে — কোম্পানি ধরে নয়।
     *
     * ⛔ কোম্পানি-ব্যাপী দ্বিতীয় কোনো সুইচ নেই, ইচ্ছাকৃতভাবে। ⚠️ থাকলে
     * একই প্রশ্নের দুইটা উত্তর থাকত, আর একদিন সেটিংস বলত "রিসেট হবে"
     * অথচ সারিটা বলত "হবে না"।
     */
    public function test_each_document_type_decides_for_itself(): void
    {
        /*
         * ⓘ `SettingsService::get()` অচেনা চাবিতে **ব্যতিক্রম ছোড়ে**,
         * `null` দেয় না — তাই ঘোষণার তালিকাটাই দেখা হয়।
         */
        $this->assertArrayNotHasKey(
            'master_data.series_reset_yearly',
            app(SettingsService::class)->definitions(),
            'নম্বরের রিসেট নিয়ে একটা দ্বিতীয় নিয়ন্ত্রণ ফিরে এসেছে — সিদ্ধান্তটা সিরিজের সারিতেই থাকার কথা।',
        );

        // এক কোম্পানিতেই এক কাগজ রিসেট করে, আরেকটা করে না — সেটা সম্ভব
        $this->series('INV')->forceFill(['format' => '{PREFIX}-{FY}-{SEQ}', 'reset_yearly' => true])->save();

        // ⓘ কোডটা `JV` (জাবেদা ভাউচার); `JRN` কেবল ছাপার উপসর্গ
        $this->assertTrue($this->series('INV')->fresh()->reset_yearly);
        $this->assertFalse((bool) $this->series('JV')->reset_yearly);
    }

    /**
     * ⭐ রিসেট না হলে গুনতি নতুন বছরেও যেখানে ছিল সেখান থেকেই চলে।
     *
     * ⓘ মালিকের আপত্তিটা এখানেই: *"কারো টোটাল লাইফটাইম কোনো হিসাব নাই,
     * সেটাই সমস্যা।"* — নম্বরটাই বলে দেয় এ পর্যন্ত কত কাগজ হয়েছে।
     */
    public function test_a_series_that_does_not_reset_keeps_counting(): void
    {
        app(NumberSeriesEngine::class)->next('INV', date: $this->year->ends_on->copy());
        app(NumberSeriesEngine::class)->next('INV', date: $this->year->ends_on->copy());

        $reached = (int) $this->series('INV')->next_number;

        app(YearEndService::class)->close($this->year);

        $new = FinancialYear::query()->where('starts_on', '>', $this->year->ends_on)->orderBy('starts_on')->firstOrFail();

        $this->assertSame(
            $reached,
            (int) NumberSeries::query()->where('doc_type', 'INV')
                ->where('financial_year_id', $new->id)
                ->value('next_number'),
            'বছর বদলে গুনতি পিছিয়ে গেছে, অথচ এই সিরিজের রিসেট বন্ধ।',
        );
    }

    private function series(string $docType, ?Carbon $on = null): NumberSeries
    {
        return NumberSeries::query()
            ->where('doc_type', $docType)
            ->where('financial_year_id', ($on === null ? $this->year : FinancialYear::forDate($on))->id)
            ->firstOrFail();
    }
}
