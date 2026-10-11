<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\BankFacility;
use App\Modules\Finance\Models\Institution;
use App\Modules\Finance\Models\InsurancePolicy;
use App\Modules\Finance\Services\BankFacilityService;
use App\Modules\Finance\Services\InsuranceService;
use App\Modules\Finance\Services\LoanSchedule;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * একই ঋণ দুবার, একই বীমার মেয়াদ দুবার — দুই চাপ একসাথে (পুরো-ERP অডিট, অর্থ, ১০ অক্টোবর ২০২৬)।
 *
 * ⭐ ব্যাংক সুবিধা: একই ব্যাংকের একই মঞ্জুরি নম্বর দ্বিতীয়বার থামে, আর খোঁজটা ব্যাংকের সারির তালার পরে।
 * ⭐ বীমা নবায়ন: পলিসির সারিতে তালা, তারপর মেয়াদ মেলানো — দ্বিতীয় চাপ প্রথমটার বসানো নতুন মেয়াদ দেখে থামে।
 */
final class TwoClicksOpenedOneLoanTwiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
    }

    public function test_the_same_sanction_of_the_same_bank_is_refused_and_looked_up_under_the_banks_lock(): void
    {
        $bank = Institution::query()->create(['company_id' => CompanyContext::id(), 'kind' => Institution::BANK, 'name_en' => 'Twice Bank']);

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = strtolower($query->sql);
        });

        $first = $this->open($bank, 'SAN-77');

        $lockAt = collect($queries)->search(fn ($sql) => str_contains($sql, 'from `fin_institutions`') && str_contains($sql, 'for update'));
        $askAt = collect($queries)->search(fn ($sql) => str_contains($sql, 'from `fin_bank_facilities`') && str_contains($sql, 'sanction_no'));
        $this->assertNotFalse($askAt, 'দৃশ্যটাই বানানো যায়নি — মঞ্জুরি নম্বরের খোঁজ পাওয়া গেল না');
        $this->assertNotFalse($lockAt, '⛔ ব্যাংকের সারিতে তালা নেই — দুই চাপ একসাথে একই ঋণ বসাতে পারে');
        $this->assertLessThan($askAt, $lockAt, '⛔ খোঁজ তালার আগে');

        try {
            $this->open($bank, ' SAN-77 ');
            $this->fail('⛔ একই ব্যাংকের একই মঞ্জুরি নম্বর দ্বিতীয়বার বসল');
        } catch (ValidationException $e) {
            $this->assertStringContainsString((string) $first->document_no, $e->errors()['sanction_no'][0] ?? '', 'বার্তায় আগের সুবিধার নম্বর নেই');
        }

        // ⓘ অন্য নম্বর, বা অন্য ব্যাংকের একই নম্বর — চলে
        $this->open($bank, 'SAN-78');
        $this->open(Institution::query()->create(['company_id' => CompanyContext::id(), 'kind' => Institution::BANK, 'name_en' => 'Other Bank']), 'SAN-77');
        $this->assertSame(3, BankFacility::query()->where('sanction_no', 'like', 'SAN-7%')->count());
    }

    public function test_a_renewal_reads_the_policy_under_its_lock_before_checking_the_term(): void
    {
        $insurer = Institution::query()->create(['company_id' => CompanyContext::id(), 'kind' => Institution::INSURANCE, 'name_en' => 'Renew Insurer']);
        $policy = app(InsuranceService::class)->create([
            'institution_id' => $insurer->id, 'policy_no' => 'RN-1', 'covers' => InsurancePolicy::GOODS, 'subject' => 'Stock',
            'sum_insured' => '900000', 'premium' => '4500',
            'starts_on' => now()->subMonths(11)->toDateString(), 'ends_on' => now()->addMonth()->toDateString(),
        ]);
        $next = ['starts_on' => now()->addMonth()->addDay()->toDateString(), 'ends_on' => now()->addMonths(13)->toDateString(), 'premium' => '4800'];

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = strtolower($query->sql);
        });

        // ⓘ দ্বিতীয় চাপের ছবি: হাতে পুরনো মডেল (শেষের দিন পুরনো), প্রথমটা আগেই নবায়ন করেছে
        $stale = $policy->fresh();
        app(InsuranceService::class)->renew($policy->fresh(), $next);

        $lockAt = collect($queries)->search(fn ($sql) => str_contains($sql, 'from `fin_insurance_policies`') && str_contains($sql, 'for update'));
        $this->assertNotFalse($lockAt, '⛔ পলিসির সারিতে তালা নেই — দুই নবায়ন একসাথে পার হতে পারে');

        $this->expectException(ValidationException::class);
        app(InsuranceService::class)->renew($stale, $next);
    }

    private function open(Institution $bank, string $sanction): BankFacility
    {
        return app(BankFacilityService::class)->open([
            'kind' => BankFacility::TERM, 'institution_id' => $bank->id, 'bank' => $bank->name_en, 'sanction_no' => $sanction,
            'sanctioned_on' => now()->subDays(20)->toDateString(), 'limit_amount' => '100000', 'interest_rate' => '10',
            'instalments' => 12, 'instalment_amount' => LoanSchedule::instalment('100000', '10', 12),
            'liability_account_id' => Account::query()->where('code', '2211')->value('id'),
        ]);
    }
}
