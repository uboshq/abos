<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\NumberSeries;
use App\Models\User;
use App\Modules\Accounts\Services\YearEndService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * বছর আবার খুলে আবার বন্ধ করলে নতুন বছরের গুনতি পিছিয়ে যেত — অডিট §১.৮, ২৭ সেপ্টেম্বর ২০২৬।
 *
 * ── ⛔ রোগটা ─────────────────────────────────────────────────────────
 * বছর বন্ধ হলে পুরনো বছরের `next_number` নতুন বছরে **হুবহু বসানো** হত।
 * ⚠️ বছর বন্ধ → নতুন বছরে INV-0101..0150 কাটা → পুরনো বছর সমন্বয়ের জন্য
 * খোলা → আবার বন্ধ: নতুন বছরের গুনতি আবার ১০১-এ। ⓘ তারপর প্রতিটা নতুন
 * চালান "নম্বর আগেই আছে" বলে আটকে যেত, যতক্ষণ না কেউ হাতে ঠিক করে।
 *
 * ⭐ এখন রিসেট-হীন সিরিজে নতুন বছরে যা আছে আর যা আসছে — দুইটার বড়টা থাকে।
 */
final class ReopeningAYearPulledTheNumbersBackTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private FinancialYear $year;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        $this->year = FinancialYear::query()->where('is_current', true)->firstOrFail();
    }

    /**
     * ⭐⭐ আসল প্রশ্ন: আবার বন্ধের পর নতুন বছরের পরের চালানটা কাটা যায় কি, আর ১৫১ থেকে?
     */
    public function test_closing_again_after_a_reopen_does_not_pull_the_new_years_counter_back(): void
    {
        $engine = app(NumberSeriesEngine::class);
        $years = app(YearEndService::class);

        // ⓘ পুরনো বছরে গুনতি ১০১-এ পৌঁছায় — অর্থাৎ ১০০টা চালান হয়ে গেছে
        $this->series('INV', $this->year)->forceFill(['next_number' => 101])->save();

        $new = $years->close($this->year);

        $this->assertSame(101, (int) $this->series('INV', $new)->next_number,
            'প্রথম বন্ধেই গুনতি বহন হয়নি — তাহলে পরের দাবিগুলো কিছুই মাপে না।');

        // নতুন বছরে ৫০টা চালান: ০১০১..০১৫০
        for ($i = 0; $i < 50; $i++) {
            $last = $engine->next('INV', date: $new->starts_on->copy());
        }

        $this->assertStringEndsWith('0150', $last);
        $this->assertSame(151, (int) $this->series('INV', $new)->next_number);

        // সমন্বয়ের জন্য পুরনো বছর খোলা, তারপর আবার বন্ধ
        $years->reopen($this->year->fresh(), $this->owner);
        $again = $years->close($this->year->fresh());

        $this->assertSame($new->id, $again->id, 'আবার বন্ধে দ্বিতীয় একটা নতুন বছর তৈরি হয়েছে।');

        $this->assertSame(151, (int) $this->series('INV', $new)->next_number,
            'আবার বন্ধ করায় নতুন বছরের চালানের গুনতি পিছিয়ে গেছে।');

        /*
         * ⛔ আগে এই ডাকটাই ছুড়ত — INV-0101 আগেই কাটা, unique ভাঙে।
         */
        $next = $engine->next('INV', date: $new->starts_on->copy());

        $this->assertStringEndsWith('0151', $next, "আবার বন্ধের পর পরের চালান {$next}, ০১৫১ নয়।");
        $this->assertDatabaseHas('issued_numbers', [
            'company_id' => CompanyContext::id(),
            'document_no' => $next,
        ]);
    }

    /**
     * ⭐ আর পুরনো বছরে সমন্বয়ে নম্বর বাড়লে সেটাও হারায় না — বড়টাই থাকে।
     */
    public function test_a_higher_carried_value_still_wins(): void
    {
        $years = app(YearEndService::class);

        $new = $years->close($this->year);
        $this->series('INV', $new)->forceFill(['next_number' => 120])->save();

        $years->reopen($this->year->fresh(), $this->owner);
        $this->series('INV', $this->year)->forceFill(['next_number' => 300])->save();
        $years->close($this->year->fresh());

        $this->assertSame(300, (int) $this->series('INV', $new)->next_number,
            'পুরনো বছরের বড় গুনতিটা নতুন বছরে পৌঁছায়নি।');
    }

    /**
     * ⛔ পাল্টা দাবি: বছরওয়ালা ছকের সিরিজ নতুন বছরে এখনো ১ থেকেই শুরু হয়।
     */
    public function test_a_per_year_reset_series_still_resets(): void
    {
        $this->series('INV', $this->year)
            ->forceFill(['format' => '{PREFIX}-{FY}-{SEQ}', 'reset_yearly' => true, 'next_number' => 101])
            ->save();

        $new = app(YearEndService::class)->close($this->year);

        $this->assertSame(1, (int) $this->series('INV', $new)->next_number,
            'বছরওয়ালা সিরিজে নতুন বছরের গুনতি ১-এ ফেরেনি।');
    }

    /**
     * ⛔ বছর-রিসেট সিরিজও আবার বন্ধে পিছায় না — প্রথম বন্ধে কেবল একবার ১-এ ফেরে।
     *
     * ⚠️ আগে আবার বন্ধ করলে নতুন বছরের গুনতি আবার `start_number`-এ যেত, আর
     * INV-{নতুন বছর}-0001 দ্বিতীয়বার চাওয়া হত — unique ভেঙে কাগজ আটকে যেত।
     */
    public function test_a_per_year_reset_series_does_not_restart_on_a_re_close(): void
    {
        $engine = app(NumberSeriesEngine::class);
        $years = app(YearEndService::class);

        $this->series('INV', $this->year)
            ->forceFill(['format' => '{PREFIX}-{FY}-{SEQ}', 'reset_yearly' => true, 'next_number' => 101])
            ->save();

        $new = $years->close($this->year);

        $this->assertSame(1, (int) $this->series('INV', $new)->next_number,
            'প্রথম বন্ধে বছর-রিসেট সিরিজ ১-এ ফেরেনি — তাহলে পরের দাবি কিছুই মাপে না।');

        for ($i = 0; $i < 5; $i++) {
            $last = $engine->next('INV', date: $new->starts_on->copy());
        }

        $this->assertStringEndsWith('0005', $last);

        $years->reopen($this->year->fresh(), $this->owner);
        $years->close($this->year->fresh());

        $this->assertSame(6, (int) $this->series('INV', $new)->next_number,
            'আবার বন্ধ করায় বছর-রিসেট সিরিজের গুনতি আবার শুরুতে ফিরে গেছে।');

        $next = $engine->next('INV', date: $new->starts_on->copy());

        $this->assertStringEndsWith('0006', $next, "আবার বন্ধের পর পরের চালান {$next}, ০০০৬ নয়।");
        $this->assertStringContainsString($new->name, $next);
        $this->assertDatabaseHas('issued_numbers', [
            'company_id' => CompanyContext::id(),
            'document_no' => $next,
        ]);
    }

    private function series(string $docType, FinancialYear $year): NumberSeries
    {
        return NumberSeries::query()
            ->where('doc_type', $docType)
            ->where('financial_year_id', $year->id)
            ->firstOrFail();
    }
}
