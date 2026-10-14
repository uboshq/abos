<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Engines\Report\Trend;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * তুলনার শতাংশ: আগের মান ঋণাত্মক হলেও চিহ্ন ঠিক, আর গোল করা — কাটা নয় (ec, রিপোর্ট লাইন-নিরীক্ষা ⚠️১১, ১৪ অক্টোবর ২০২৬)।
 *
 * ⛔ আগে রিপোর্টের "তুলনা" চিহ্নসহ আগের মানে ভাগ করত: লোকসান −১,০০০ থেকে লাভ +৫০০ দেখাত "−১৫০%", যেন কমেছে — অথচ মালিকের
 * কেন্দ্র ([[Trend::change()]]) একই সংখ্যায় বলত +১৫০%। আর Trend নিজে শতাংশ কাটত: ৩ থেকে ৫ = ৬৬.৬৬%, ৬৬.৬৭% নয়।
 */
final class ALossThatShrankReadAsAFallTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_change_is_against_the_size_of_the_earlier_value_and_rounded(): void
    {
        $this->assertSame('150.00', Trend::change('500', '-1000'), '⛔ লোকসান থেকে লাভে ওঠা "কমেছে" পড়ল।');
        $this->assertSame('66.67', Trend::change('5', '3'), '⛔ বদলের শতাংশ কাটা হলো, গোল নয়।');
        $this->assertSame('-50.00', Trend::change('50', '100'));
        $this->assertNull(Trend::change('500', '0'));

        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $engine = app(ReportEngine::class);
        $engine->register(new ReportDefinition(
            key: 'ec_compare_probe',
            title: 'Compare probe',
            // ⓘ এই মাসে +৫০০, আগের সময়ে −১,০০০
            query: fn (array $f) => DB::query()->selectRaw("'p1' as k, ? as amount", [$f['from'] === '2026-09-01' ? '500' : '-1000']),
            columns: [
                ['key' => 'k', 'label' => 'Key', 'type' => ReportColumn::TEXT],
                ['key' => 'amount', 'label' => 'Amount', 'type' => ReportColumn::MONEY],
            ],
            groupBy: 'k',
            rankBy: 'amount',
        ));

        $result = $engine->run('ec_compare_probe', ['from' => '2026-09-01', 'to' => '2026-09-30', 'compare' => ReportEngine::COMPARE_PREVIOUS]);

        $this->assertSame(0, bccomp((string) $result->rows[0]['previous_value'], '-1000', 2), 'আগের মানই −১,০০০ নয় — দাবি অন্ধ।');
        $this->assertSame('150.00', $result->rows[0]['change_percent'], '⛔ রিপোর্টের তুলনা আর মালিকের কেন্দ্র দুই রকম বলে।');
    }
}
