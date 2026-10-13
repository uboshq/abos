<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Services\DataScope;
use App\Core\Services\PhoneModules;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Finance\Models\BankFacility;
use App\Modules\Finance\Models\Deposit;
use App\Modules\Finance\Models\DepositKind;
use App\Modules\Finance\Models\HandLoanAccount;
use App\Modules\Finance\Models\HandLoanMovement;
use App\Modules\Finance\Services\BankFacilityService;
use App\Modules\Finance\Services\DepositKindInstaller;
use App\Modules\Finance\Services\HandLoanService;
use App\Modules\Finance\Services\LoanSchedule;
use App\Modules\Finance\Services\RentalContractService;
use App\Modules\Finance\Services\RentalDues;
use App\Modules\Finance\Services\TenancyService;
use App\Modules\MasterData\Models\Person;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ ফোনে অর্থ — জমা, ব্যাংকের ঋণ ও কিস্তি, ভাড়া, হাতধার (মালিকের আদেশ, ১৩ অক্টোবর ২০২৬; [[FinanceApiController]])।
 *
 * দাবি:
 *  - প্রতিটা অঙ্ক মডিউলের নিজের সেবার: ঋণের বকেয়া = standing()['used'], পরের কিস্তি = সূচির প্রথম না-দেওয়া সারি, ভাড়ার
 *    বকেয়া = overdueMonths()-এর মাস আর তাদের ভাড়া, হাতধারের জের = balanceOf()।
 *  - চাবি ছাড়া ৪০৩; সংখ্যার আইডি ৪০৪; ফোনে অর্থ বন্ধ থাকলে ৪০৩ `module_off`।
 *  - এক শাখায় আটকানো কর্মী অন্য শাখার কাগজ পান না — ৪০৪ (ওয়েবের [[OpensOnlyInReach]]-এর একই দেয়াল)।
 */
final class ThePhoneReadsTheFinanceBooksTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs($this->owner);
        app(StandardChart::class)->install();
        app(DepositKindInstaller::class)->install();
        app(SettingsService::class)->set(PhoneModules::key('finance'), true);
    }

    public function test_deposits_list_by_maturity_and_a_closed_one_waits_on_its_own_tab(): void
    {
        $soon = $this->deposit('FD-SOON', now()->addDays(20));
        $later = $this->deposit('FD-LATER', now()->addYear());
        $closed = $this->deposit('FD-OLD', now()->subMonth(), Deposit::CLOSED);

        $rows = $this->phone($this->owner, '/api/v1/finance/deposits')->assertOk()->json('rows');
        $ids = array_column($rows, 'id');

        $this->assertSame([$soon->public_id, $later->public_id], array_values(array_intersect($ids, [$soon->public_id, $later->public_id])),
            '⛔ যার মেয়াদ আগে সে আগে নয়।');
        $this->assertNotContains($closed->public_id, $ids, '⛔ শেষ হওয়া জমা চালুর তালিকায়।');
        $this->assertSame(20, collect($rows)->firstWhere('id', $soon->public_id)['days_to_maturity']);

        $closedRows = $this->phone($this->owner, '/api/v1/finance/deposits?tab=closed')->assertOk()->json('rows');
        $this->assertContains($closed->public_id, array_column($closedRows, 'id'));

        $one = $this->phone($this->owner, '/api/v1/finance/deposits/'.$soon->public_id)->assertOk()->json();
        $this->assertSame('FD-SOON', $one['no']);
        $this->assertSame(0, bccomp((string) $one['principal'], '250000', 2));
    }

    public function test_a_loans_next_instalment_and_outstanding_come_from_the_loan_book(): void
    {
        $loan = $this->loan();
        $service = app(BankFacilityService::class);

        $used = $service->standing(collect([$loan]))[$loan->id]['used'];
        $this->assertSame(1, bccomp($used, '0', 4), 'দাবির ভিত্তি নেই — ঋণে কোনো দেনা নেই।');

        $row = collect($this->phone($this->owner, '/api/v1/finance/loans')->assertOk()->json('rows'))->firstWhere('id', $loan->public_id);
        $this->assertNotNull($row, '⛔ খোলা ঋণ তালিকায় নেই।');
        $this->assertSame(0, bccomp((string) $row['outstanding'], $used, 2), '⛔ ফোনের দেনা খাতার দেনা নয়।');

        // ⓘ প্রথম কিস্তি দেওয়া — পরেরটা দ্বিতীয়, আর তার দিন পেরিয়েছে
        $second = $service->datedSchedule($loan)['rows'][1];
        $this->assertSame($second['due_on'], $row['next']['due_on'], '⛔ পরের কিস্তি সূচির প্রথম না-দেওয়া সারি নয়।');
        $this->assertSame(BankFacilityService::OVERDUE, $row['next']['state']);

        $one = $this->phone($this->owner, '/api/v1/finance/loans/'.$loan->public_id)->assertOk()->json();
        $this->assertSame(1, $one['instalments_paid']);
        $this->assertSame($second['due_on'], $one['schedule'][0]['due_on']);
        $this->assertGreaterThanOrEqual(1, $one['overdue']);
    }

    public function test_rent_owed_is_the_months_the_rent_book_says_and_the_next_day_is_ahead(): void
    {
        $contract = app(RentalContractService::class)->open([
            'counterparty' => 'Landlord', 'subject' => 'Godown', 'deposit_amount' => '0', 'monthly_rent' => '12000',
            'monthly_adjustment' => '0', 'term_months' => 24, 'rent_day' => 5,
            'starts_on' => now()->subMonths(3)->startOfMonth()->toDateString(),
        ]);
        $months = app(RentalDues::class)->overdueMonths($contract);
        $this->assertNotEmpty($months, 'দাবির ভিত্তি নেই — কোনো মাস বাকি নেই।');

        $row = collect($this->phone($this->owner, '/api/v1/finance/rents?side=pay')->assertOk()->json('rows'))->firstWhere('id', $contract->public_id);
        $this->assertNotNull($row);
        $this->assertSame(count($months), $row['overdue_count'], '⛔ বকেয়া মাসের সংখ্যা ভাড়ার খাতার নয়।');
        $this->assertSame(0, bccomp((string) $row['overdue_amount'], bcmul('12000', (string) count($months), 2), 2));

        $next = Carbon::parse($row['next_due_on']);
        $this->assertTrue($next->gte(Carbon::today()), '⛔ পরের ভাড়ার দিন পেছনে।');
        $this->assertSame(5, $next->day);

        $one = $this->phone($this->owner, '/api/v1/finance/rents/pay/'.$contract->public_id)->assertOk()->json();
        $this->assertCount(count($months), $one['overdue_months']);

        $person = Person::query()->create(['code' => 'P-TEN', 'name_en' => 'Tenant', 'name_bn' => 'ভাড়াটে', 'is_active' => true]);
        $tenancy = app(TenancyService::class)->open([
            'party_type' => 'person', 'party_id' => $person->id, 'tenant' => 'Tenant', 'monthly_rent' => '8000',
            'deposit_amount' => '0', 'term_months' => 12, 'starts_on' => now()->startOfMonth()->toDateString(),
        ]);
        $let = collect($this->phone($this->owner, '/api/v1/finance/rents?side=let')->assertOk()->json('rows'))->firstWhere('id', $tenancy->public_id);
        $this->assertNotNull($let, '⛔ আমাদের ভাড়াটে "আমরা পাই"-এর তালিকায় নেই।');
        $this->assertSame(0, bccomp((string) $let['outstanding'], $tenancy->fresh()->outstanding(), 2));
        $this->phone($this->owner, '/api/v1/finance/rents/let/'.$tenancy->public_id)->assertOk()->assertJsonPath('party', 'Tenant');
        // ⓘ ভুল দিকে একই আইডি — অন্য টেবিলের সারি নয়
        $this->phone($this->owner, '/api/v1/finance/rents/pay/'.$tenancy->public_id)->assertNotFound();
    }

    public function test_a_hand_loans_balance_is_the_service_balance(): void
    {
        $account = $this->handLoan('Karim');

        $body = $this->phone($this->owner, '/api/v1/finance/hand-loans')->assertOk()->json();
        $row = collect($body['rows'])->firstWhere('id', $account->public_id);
        $this->assertNotNull($row);
        $this->assertSame(0, bccomp((string) $row['balance'], app(HandLoanService::class)->balanceOf($account), 2));
        $this->assertSame(0, bccomp((string) $row['balance'], '5000', 2));

        $one = $this->phone($this->owner, '/api/v1/finance/hand-loans/'.$account->public_id)->assertOk()->json();
        $this->assertSame('Karim', $one['name']);
        $this->assertCount(1, $one['movements']);
    }

    public function test_without_the_key_or_with_the_phone_switch_off_it_is_refused_and_a_number_is_not_an_address(): void
    {
        $deposit = $this->deposit('FD-1', now()->addMonth());
        $stranger = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id]);
        $stranger->companies()->attach($this->company, ['is_active' => true]);

        foreach (['/deposits', '/loans', '/rents', '/hand-loans'] as $path) {
            $this->phone($stranger, '/api/v1/finance'.$path)->assertForbidden();
        }

        $this->phone($this->owner, '/api/v1/finance/deposits/'.$deposit->id)->assertNotFound();

        app(SettingsService::class)->set(PhoneModules::key('finance'), false);
        $this->phone($this->owner, '/api/v1/finance/deposits')->assertForbidden()->assertJsonPath('reason', PhoneModules::REASON);
    }

    public function test_a_clerk_held_to_one_branch_never_opens_another_branchs_paper(): void
    {
        $mms = $this->branch('MMS');
        $ntk = $this->branch('NTK');

        CompanyContext::set($this->company->id, $ntk->id);
        $theirs = $this->handLoan('Other branch');
        CompanyContext::set($this->company->id, $mms->id);
        $mine = $this->handLoan('My branch');

        $clerk = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id, 'current_branch_id' => $mms->id]);
        $clerk->companies()->attach($this->company->id, ['is_active' => true]);
        CompanyContext::forCompany($this->company->id, fn () => $clerk->givePermissionTo(Permission::findOrCreate('finance.hand_loan.view', 'web')));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        UserDataScope::query()->create([
            'company_id' => $this->company->id, 'user_id' => $clerk->id,
            'scope_type' => UserDataScope::BRANCH, 'scope_id' => $mms->id,
        ]);
        app(DataScope::class)->forget();

        $this->phone($clerk, '/api/v1/finance/hand-loans/'.$mine->public_id)->assertOk();
        $this->phone($clerk, '/api/v1/finance/hand-loans/'.$theirs->public_id)->assertNotFound();
        $ids = array_column($this->phone($clerk, '/api/v1/finance/hand-loans')->assertOk()->json('rows'), 'id');
        $this->assertNotContains($theirs->public_id, $ids, '⛔ অন্য শাখার হাতধার তালিকায়।');
    }

    private function deposit(string $no, Carbon $matures, string $status = Deposit::ACTIVE): Deposit
    {
        return Deposit::query()->create([
            'branch_id' => CompanyContext::branchId(),
            'document_no' => $no,
            'kind_id' => DepositKind::query()->where('issuer', DepositKind::BANK)->firstOrFail()->id,
            'institution' => 'Sonali Bank',
            'held_by' => Deposit::BUSINESS,
            'principal' => '250000',
            'profit_rate' => '9',
            'opened_on' => now()->subYear()->toDateString(),
            'matures_on' => $matures->toDateString(),
            'status' => $status,
            'closed_on' => $status === Deposit::CLOSED ? now()->subMonth()->toDateString() : null,
        ]);
    }

    /** ⓘ মঞ্জুরি ৯০ দিন আগে, প্রথম কিস্তি ৬০ দিন আগে, পুরো টাকা তোলা, প্রথম কিস্তি দেওয়া ([[BankLoanInstalmentsAndLimitsTest]]-এর ছাঁচ) */
    private function loan(): BankFacility
    {
        $limit = '1200000';
        $liability = Account::query()->where('code', '2211')->firstOrFail();
        $equity = StandardChart::find(StandardChart::OWNER_CAPITAL);

        $loan = app(BankFacilityService::class)->open([
            'kind' => BankFacility::TERM, 'bank' => 'Sonali Bank', 'limit_amount' => $limit, 'interest_rate' => '12',
            'instalments' => 12, 'instalment_amount' => LoanSchedule::instalment($limit, '12', 12),
            'liability_account_id' => $liability->id,
            'sanctioned_on' => now()->subDays(90)->toDateString(),
            'first_instalment_on' => now()->subDays(60)->toDateString(),
        ]);

        $this->journal($loan, [
            ['account_id' => $equity->id, 'debit' => $limit, 'credit' => '0'],
            ['account_id' => $liability->id, 'debit' => '0', 'credit' => $limit],
        ]);
        $first = LoanSchedule::build($limit, '12', 12)['rows'][0];
        $this->journal($loan, [
            ['account_id' => $liability->id, 'debit' => (string) $first['principal'], 'credit' => '0'],
            ['account_id' => StandardChart::find(StandardChart::INTEREST_EXPENSE)->id, 'debit' => (string) $first['interest'], 'credit' => '0'],
            ['account_id' => $equity->id, 'debit' => '0', 'credit' => bcadd((string) $first['principal'], (string) $first['interest'], 2)],
        ]);

        return $loan->fresh();
    }

    /** @param list<array<string, mixed>> $lines */
    private function journal(BankFacility $facility, array $lines): void
    {
        $vouchers = app(VoucherService::class);
        $vouchers->post($vouchers->create([
            'type' => Voucher::JOURNAL, 'trx_date' => now()->subDays(80)->toDateString(), 'narration' => 'phone loan',
            'against_type' => BankFacility::drillSourceType(), 'against_id' => $facility->id,
        ], $lines));
    }

    /** ⓘ ভাউচার ছাড়া পুরনো সারি গোনা হয় ([[HandLoanMovement::scopeCounted()]]) — ৫,০০০ দেওয়া, তিনি দেবেন */
    private function handLoan(string $name): HandLoanAccount
    {
        $person = Person::query()->create(['code' => 'P-'.substr(md5($name), 0, 6), 'name_en' => $name, 'name_bn' => $name, 'is_active' => true]);
        $account = app(HandLoanService::class)->open(['person_id' => $person->id]);
        HandLoanMovement::query()->create([
            'account_id' => $account->id, 'direction' => HandLoanMovement::OUT, 'amount' => '5000',
            'moved_on' => now()->subDays(5)->toDateString(),
        ]);

        return $account->fresh();
    }

    private function branch(string $code): Branch
    {
        return Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', $code)->firstOrFail();
    }

    private function phone(User $user, string $url): TestResponse
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user->fresh(), [AuthController::APP]);

        return $this->getJson($url);
    }
}
