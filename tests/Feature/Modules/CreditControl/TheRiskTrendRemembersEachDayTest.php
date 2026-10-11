<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\CreditControl;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Modules\CreditControl\Reports\CreditControlLists;
use App\Modules\CreditControl\Services\CreditRisk;
use App\Modules\Customer\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ⭐ ঝুঁকির ধারা — রোজ সকালে প্রতিটা সক্রিয় গ্রাহকের মাত্রার ছবি, আর রিপোর্ট দিন ধরে কোন মাত্রায় কতজন (বাকি নিয়ন্ত্রণ ধাপ ৩.২)।
 *
 *   · `abos:credit-due` প্রতিটা সক্রিয় গ্রাহকের একটা সারি বসায়, মাত্রা [[CreditRisk]]-এর; একই দিনে আবার চললে দ্বিতীয় সারি নয়।
 *   · রিপোর্ট দিন ধরে গোনে — গতকালের ছবি গতকালের সারিতে।
 */
final class TheRiskTrendRemembersEachDayTest extends TestCase
{
    use CreditControlKit;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCreditCompany();
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    public function test_the_morning_job_keeps_one_picture_a_day_and_the_trend_counts_it(): void
    {
        $bad = $this->customer('TR-BAD', '10000');
        $this->bill($bad, 'INV-TR1', '6000', daysPastDue: 45);
        $this->bouncedCheque($bad, '1000');

        $this->artisan('abos:credit-due', ['--company' => $this->company->code])->assertSuccessful();
        $this->artisan('abos:credit-due', ['--company' => $this->company->code])->assertSuccessful();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $active = Customer::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('is_active', true)->count();
        $today = DB::table('credit_risk_snapshots')->where('company_id', $this->company->id)->where('taken_on', now()->toDateString());
        $this->assertSame($active, (clone $today)->count(), '⛔ সক্রিয় গ্রাহকের ছবি একটা করে নয় (দুইবার চালানোয় দুইটা?)।');
        $this->assertSame(CreditRisk::CRITICAL, (clone $today)->where('customer_id', $bad->id)->value('level'));

        DB::table('credit_risk_snapshots')->insert([
            'public_id' => (string) Str::uuid7(), 'company_id' => $this->company->id, 'customer_id' => $bad->id, 'taken_on' => now()->subDay()->toDateString(),
            'level' => CreditRisk::MEDIUM, 'score' => 25, 'created_at' => now()->subDay(),
        ]);

        $rows = collect(app(ReportEngine::class)->run(CreditControlLists::RISK_TREND,
            ['from' => now()->subDays(2)->toDateString(), 'to' => now()->toDateString()], perPage: 100)->rows)->keyBy(fn ($r) => (string) $r['taken_on']);

        $yesterday = $rows->first(fn ($r, $k) => str_starts_with($k, now()->subDay()->toDateString()));
        $now = $rows->first(fn ($r, $k) => str_starts_with($k, now()->toDateString()));
        $this->assertSame(1, (int) $yesterday['medium']);
        $this->assertGreaterThanOrEqual(1, (int) $now['critical']);
        $this->assertSame($active, (int) $now['low'] + (int) $now['medium'] + (int) $now['high'] + (int) $now['critical']);
    }
}
