<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Core\Support\DateFormat;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * রিপোর্ট খালি দেখাত, অথচ তথ্য আছে — পাতা-ঝাড়ু, ধাপ ০ (১০ অক্টোবর ২০২৬; ছবিতে বিক্রয়ের খাতা খালি)।
 *
 * ⓘ কারণ: তারিখ না দিলে রিপোর্ট এই মাসের ([[ReportEngine::normaliseFilters()]]), আর তারিখের ঘর "+ ছাঁকনি"-র ভেতরে লুকোনো।
 * ⭐ এখন শিরোনামের পাশে সারির সংখ্যার সাথে সময়টাও লেখা — সব মডিউলের রিপোর্ট এই এক পর্দা (`accounts::report.show`)।
 */
final class TheReportNeverSaidWhichPeriodTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_report_says_its_period_beside_the_title(): void
    {
        $this->travelTo('2026-10-10 11:00:00');
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, null);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $count = function (string $url): string {
            $html = $this->get($url)->assertOk()->getContent();
            $this->assertSame(1, preg_match('/data-record-count[^>]*>([^<]*)</u', $html, $m), 'দৃশ্যটাই বানানো যায়নি — সারির সংখ্যার ঘর নেই: '.$url);

            // ⓘ বেছে দেওয়া তারিখ ABOS রূপে তারিখের ঘরেই লেখা — একই কথা দুবার নয় (২৫ নভেম্বর ২০২৬); "এই মাস"-এর মতো নামে লেখা থাকলে সংখ্যার পাশে
            $chip = preg_match('/data-date-bar-text>([^<]*)</u', $html, $d) === 1 ? $d[1] : '';

            return html_entity_decode(trim($m[1]).' '.$chip);
        };
        $day = fn (string $d) => DateFormat::format($d);

        // ⭐ ডিফল্ট — এই মাস; এখন লেখা থাকে
        $this->assertStringContainsString($day('2026-10-01').' – '.$day('2026-10-10'), $count(route('accounts.report.show', 'day-book')), '⛔ দিনের খাতা বলে না কোন সময়ের।');
        $this->assertStringContainsString($day('2026-10-01').' – '.$day('2026-10-10'), $count(route('sales.report.show', 'register')), '⛔ বিক্রয়ের খাতা বলে না কোন সময়ের।');

        // ⓘ বেছে দেওয়া সময় — যা বাছা, তাই লেখা
        $this->assertStringContainsString($day('2026-07-01').' – '.$day('2026-09-30'),
            $count(route('accounts.report.show', ['slug' => 'day-book', 'from' => '2026-07-01', 'to' => '2026-09-30'])));

        // ⭐ রেওয়ামিল এখন ৬ কলামে (মালিক, ১৬ নভেম্বর ২০২৬) — শুরুর জের "থেকে"-র আগের সব, লেনদেন এই সময়ের; তাই সময়কালটাই লেখা
        $tb = $count(route('accounts.report.show', ['slug' => 'trial-balance', 'from' => '2026-07-01', 'to' => '2026-09-30']));
        $this->assertStringContainsString($day('2026-07-01').' – '.$day('2026-09-30'), $tb, '⛔ রেওয়ামিল বলে না কোন সময়ের লেনদেন।');
    }
}
