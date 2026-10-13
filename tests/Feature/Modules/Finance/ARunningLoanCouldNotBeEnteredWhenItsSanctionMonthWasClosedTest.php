<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\PeriodLock;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\BankFacility;
use App\Modules\Finance\Services\BankFacilityService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ⛔ আগে থেকে চলা ঋণ তোলা যেত না, যদি মঞ্জুরির মাসটা বন্ধ থাকত — Finance লাইন-নিরীক্ষা, ১৩ অক্টোবর ২০২৬ (⚠️২)।
 *
 * ⓘ খোলা বকেয়া বসত মঞ্জুরির দিনে ([[BankFacilityService::openingFor()]])। ২০২৪-এর মার্চে মঞ্জুর হওয়া ঋণ আজ তুলতে গেলে,
 * আর মার্চ ২০২৪ বন্ধ থাকলে, পুরো জমাটাই থামত — ঋণটা কোনোদিন ব্যবস্থায় উঠত না।
 *
 * ⭐ দাবি:
 *   · মঞ্জুরির মাস বন্ধ → খোলা বকেয়া আজকের তারিখে বসে, ঋণ খোলে
 *   · মঞ্জুরির মাস খোলা → আগের মতোই মঞ্জুরির দিনে (পুরো বদলটা মালিকের সিদ্ধান্তের অপেক্ষায়)
 */
final class ARunningLoanCouldNotBeEnteredWhenItsSanctionMonthWasClosedTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
    }

    public function test_a_closed_sanction_month_books_the_opening_today(): void
    {
        PeriodLock::query()->create([
            'company_id' => $this->company->id, 'year' => 2024, 'month' => 3,
            'reason' => 'বছর বন্ধ', 'locked_by' => auth()->id(), 'locked_at' => now(),
        ]);

        $this->post(route('finance.bank_facility.store'), $this->running('2024-03-01'))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $facility = BankFacility::query()->latest('id')->first();
        $this->assertNotNull($facility, '⛔ মঞ্জুরির মাস বন্ধ বলে চলতি ঋণটা তোলাই গেল না।');

        $dates = $this->openingDates($facility);
        $this->assertSame([now()->toDateString()], $dates, '⛔ খোলা বকেয়া আজকের তারিখে বসেনি — এল '.implode(', ', $dates));
        $this->assertSame(0, bccomp(app(BankFacilityService::class)->standing(collect([$facility]))[$facility->id]['used'], '1800000', 4),
            '⛔ ঋণের বকেয়া খাতায় ১৮ লাখ নয়।');
    }

    public function test_an_open_sanction_month_keeps_the_sanction_day(): void
    {
        $this->post(route('finance.bank_facility.store'), $this->running('2024-05-01'))->assertSessionHasNoErrors();

        $facility = BankFacility::query()->latest('id')->firstOrFail();
        $this->assertSame(['2024-05-01'], $this->openingDates($facility), '⛔ খোলা মাসেও তারিখ বদলে গেল।');
    }

    /** @return list<string> */
    private function openingDates(BankFacility $facility): array
    {
        return DB::table('ledger_entries')
            ->where('source_type', BankFacilityService::OPENING_SOURCE)->where('source_id', $facility->id)
            ->distinct()->pluck('trx_date')->map(fn ($d) => substr((string) $d, 0, 10))->values()->all();
    }

    /** @return array<string, mixed> */
    private function running(string $sanctionedOn): array
    {
        return [
            'kind' => BankFacility::TERM, 'institution_new' => 'Sonali Bank', 'sanctioned_on' => $sanctionedOn,
            'limit_amount' => '2500000', 'interest_rate' => '11', 'instalments' => 60, 'instalment_amount' => '55000',
            'liability_account_id' => Account::query()->where('code', '2211')->value('id'),
            'already_running' => '1', 'opening_drawn' => '1800000', 'instalments_paid' => 10,
        ];
    }
}
