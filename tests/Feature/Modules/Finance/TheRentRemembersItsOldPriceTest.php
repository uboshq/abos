<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\Notification;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\RentalContract;
use App\Modules\Finance\Models\RentalTerm;
use App\Modules\Finance\Reports\RentalReports;
use App\Modules\Finance\Services\RentalContractService;
use App\Modules\Finance\Services\RentalDues;
use App\Modules\Finance\Services\RentalNotices;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⭐ ভাড়ার শর্তের ইতিহাস আর বর্ষপূর্তির ঘণ্টা — মালিকের সিদ্ধান্ত প্র১, ৬ অক্টোবর ২০২৬ ([[RentalTerm]], [[RentalNotices::anniversaries()]])।
 *
 * ⛔ পুরনো মাস পুরনো দরে (সময়সূচি আর বকেয়া দুই জায়গায়); সামনের মাসের দফা আজকের দর বদলায় না; একই মাসে আবার বদলালে
 * নতুন সারি নয়; মেয়াদের বাইরের মাস নয়। বর্ষপূর্তি: % থাকলে ৩০ দিন আগে একবার; % না থাকলে বা দূরে হলে নয়; ভাড়া নিজে বাড়ে না।
 */
final class TheRentRemembersItsOldPriceTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
        app(StandardChart::class)->install();

        // ⓘ আজ = মাসের ১০ তারিখ
        Carbon::setTestNow(now()->startOfMonth()->addDays(9));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_old_months_keep_their_old_rent_and_new_months_take_the_new(): void
    {
        $contract = $this->open('Old Price Landlord', $this->month(-6), '10000');
        app(RentalContractService::class)->reviseTerms($contract, ['monthly_rent' => '12000', 'effective_from' => $this->month(-2)->format('Y-m')]);

        $contract->refresh();
        $this->assertSame('12000.0000', (string) $contract->monthly_rent, 'এখনকার দর');
        $this->assertSame('10000', bcadd($contract->rentFor($this->month(-3)), '0', 0), 'পুরনো মাস পুরনো দরে');
        $this->assertSame('12000', bcadd($contract->rentFor($this->month(-2)), '0', 0));

        $due = collect(app(ReportEngine::class)->run(RentalReports::SCHEDULE, ['from' => $this->month(-6)->toDateString(), 'to' => now()->toDateString()])->rows)
            ->map(fn ($r) => (array) $r)->filter(fn ($r) => $r['counterparty'] === 'Old Price Landlord')
            ->mapWithKeys(fn ($r) => [substr((string) $r['for_month'], 0, 10) => bcadd((string) $r['rent_due'], '0', 0)]);

        $this->assertSame('10000', $due[$this->month(-3)->toDateString()], '⛔ পুরনো না-দেওয়া মাস এখনকার দরে দেখাল');
        $this->assertSame('12000', $due[$this->month(-1)->toDateString()]);

        // ⓘ বকেয়া — ছয় মাস আগে থেকে এ মাস (ভাড়ার দিন ৫, আজ ১০): চার মাস ১০,০০০ + তিন মাস ১২,০০০
        $row = collect(app(RentalDues::class)->overdue())->first(fn ($r) => $r['contract']->is($contract));
        $this->assertSame(0, bccomp('76000', $row['amount'], 4), '⛔ বকেয়া এখনকার দরে গোনা হল');
    }

    public function test_a_future_term_waits_and_the_same_month_is_updated_not_doubled(): void
    {
        $contract = $this->open('Future Landlord', $this->month(-3), '10000');
        $service = app(RentalContractService::class);

        $service->reviseTerms($contract, ['monthly_rent' => '15000', 'effective_from' => $this->month(1)->format('Y-m')]);
        $this->assertSame('10000.0000', (string) $contract->fresh()->monthly_rent, '⛔ সামনের মাসের দফা আজকের দর বদলাল');
        $this->assertSame('15000', bcadd($contract->fresh()->rentFor($this->month(1)), '0', 0));

        $service->reviseTerms($contract->fresh(), ['monthly_rent' => '16000', 'effective_from' => $this->month(1)->format('Y-m')]);
        $this->assertSame(2, RentalTerm::query()->where('rental_contract_id', $contract->id)->count(), '⛔ একই মাসে দুই দফা');
        $this->assertSame('16000', bcadd($contract->fresh()->rentFor($this->month(1)), '0', 0));

        try {
            $service->reviseTerms($contract->fresh(), ['monthly_rent' => '9000', 'effective_from' => $this->month(-12)->format('Y-m')]);
            $this->fail('⛔ চুক্তির শুরুর আগের মাসে দফা বসল।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('effective_from', $e->errors());
        }
    }

    public function test_the_anniversary_rings_once_only_where_the_contract_promises_an_increase(): void
    {
        // ⓘ বর্ষপূর্তি ২০ দিন পরে, ৫% — বাজবে; % ছাড়া — বাজবে না; বর্ষপূর্তি ৪০ দিন পরে — বাজবে না
        $soon = $this->open('Rising Landlord', now()->subYear()->addDays(20), '20000', increase: '5');
        $this->open('Flat Landlord', now()->subYear()->addDays(20), '20000');
        $this->open('Far Landlord', now()->subYear()->addDays(40), '20000', increase: '5');

        $this->app['auth']->forgetGuards();
        $this->assertSame(1, app(RentalNotices::class)->anniversaries());
        $this->assertSame(0, app(RentalNotices::class)->anniversaries(), '⛔ একই বর্ষপূর্তিতে দ্বিতীয়বার');

        // ⓘ আট দিন পরে — এখনো ৩০ দিনের ভিতরে, তবু একই বর্ষপূর্তিতে আর নয়
        Carbon::setTestNow(now()->addDays(8));
        $this->assertSame(0, app(RentalNotices::class)->anniversaries(), '⛔ এক সপ্তাহ পরে একই বর্ষপূর্তিতে আবার বাজল');

        $bell = Notification::query()->where('user_id', $this->owner->id)->where('type', 'like', RentalNotices::ANNIVERSARY.'%')->sole();
        $this->assertStringContainsString(\App\Core\Support\Money::format('21000'), (string) $bell->body, '২০,০০০ × ১.০৫');
        $this->assertSame('20000.0000', (string) $soon->fresh()->monthly_rent, '⛔ ভাড়া নিজে বাড়ল');
    }

    public function test_the_pages_carry_the_history_and_the_percent(): void
    {
        $contract = $this->open('Page Landlord', $this->month(-3), '10000', increase: '7.5');
        app(RentalContractService::class)->reviseTerms($contract, ['monthly_rent' => '11000', 'effective_from' => now()->format('Y-m')]);

        $this->get(route('finance.rental.show', $contract))->assertOk()->assertSee('data-rental-terms', false)
            ->assertSee(\App\Core\Support\Money::format('11000'))->assertSee('name="increase_percent"', false);
        $this->assertSame('7.50', (string) $contract->fresh()->increase_percent);
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    private function month(int $offset): Carbon
    {
        return now()->startOfMonth()->addMonths($offset);
    }

    private function open(string $who, Carbon $starts, string $rent, ?string $increase = null): RentalContract
    {
        return app(RentalContractService::class)->open([
            'counterparty' => $who, 'subject' => $who.' place', 'deposit_amount' => '0', 'monthly_rent' => $rent,
            'monthly_adjustment' => '0', 'term_months' => 36, 'starts_on' => $starts->toDateString(), 'rent_day' => 5,
            'increase_percent' => $increase,
        ]);
    }
}
