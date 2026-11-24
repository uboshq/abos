<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Hr;

use App\Core\Security\FieldSecurity;
use App\Core\Support\CompanyContext;
use App\Core\Support\Money;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\FinalSettlement;
use App\Modules\Hr\Models\HeldWagePayment;
use App\Modules\Hr\Models\SalaryHead;
use App\Modules\Hr\Services\EmployeeService;
use App\Modules\Hr\Services\PayrollService;
use App\Modules\Hr\Services\SalaryHeadService;
use App\Modules\Hr\Services\SalaryStructureService;
use App\Modules\MasterData\Services\MasterListService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * HR-এর দুই তালিকা বাকি সব তালিকার মতো, আর আইনি খাতের আদেশ দল-খাত ছোঁয় না (২৫ নভেম্বর ২০২৬)।
 *
 * দাবি:
 *  · চূড়ান্ত পাওনার সর্বমোট কেবল অনুমোদিত কাগজের — অপেক্ষার, ফেরত আর তুলে নেওয়া কাগজ যোগে নয় (মালিক, ২৪ অক্টোবর ২০২৬: "মোট কেবল
 *    চূড়ান্ত কাগজের"); মোট সব পাতার, খোঁজার সাথে বদলায়, আর যিনি খুঁজলেন তাঁর খোঁজা কর্মীরই।
 *  · বকেয়া মজুরির দেওয়া কাগজ পাতায় পাতায়, ভাউচার প্রতি একটা — পুরনোটার ফাইলও নামে (আগে কুড়িটার পরেরগুলোর পথই ছিল না)।
 *  · `abos:hr-law-accounts` দল-খাতকে সিস্টেমের চিহ্ন দেয় না; সাধারণ খাতকে আগের মতো দেয়।
 */
final class TheHrListsPageAndTotalOnlyFinalPapersTest extends TestCase
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
        FieldSecurity::forget();
    }

    public function test_the_settlement_total_counts_only_approved_papers(): void
    {
        [$a, $b] = [$this->employee('FS-A', 'Alpha Leaver'), $this->employee('FS-B', 'Beta Leaver')];

        $this->settlement($a, '700', FinalSettlement::APPROVED);
        $this->settlement($b, '50', FinalSettlement::APPROVED);
        foreach ([FinalSettlement::PENDING, FinalSettlement::REJECTED, FinalSettlement::WITHDRAWN] as $status) {
            $this->settlement($a, '9000', $status);
        }

        $page = $this->get(route('hr.final_settlement.index'))->assertOk();
        $this->assertSame(5, $page->viewData('rows')->total(), 'প্রস্তুতিটাই ভুল — পাঁচটা কাগজ তালিকায় নেই।');
        $this->assertSame(0, bccomp('750', (string) $page->viewData('grand')['net'], 2),
            '⛔ সর্বমোটে অনুমোদন না-পাওয়া কাগজ — '.Money::format((string) $page->viewData('grand')['net']));
        $page->assertSee(Money::format('9000'), false);

        $found = $this->get(route('hr.final_settlement.index', ['q' => 'Beta Leaver']))->assertOk();
        $this->assertSame(1, $found->viewData('rows')->total(), '⛔ কর্মীর নামে খোঁজা কাজ করে না।');
        $this->assertSame(0, bccomp('50', (string) $found->viewData('grand')['net'], 2), '⛔ খোঁজার পরে মোট খোঁজা সারির নয়।');
    }

    public function test_an_old_held_wage_paper_is_still_reachable_one_per_voucher(): void
    {
        app(MasterListService::class)->installDefaults();
        app(SalaryHeadService::class)->installDefaults();
        Employee::query()->delete();
        $basic = SalaryHead::query()->where('code', 'BASIC')->firstOrFail();

        for ($i = 1; $i <= 28; $i++) {
            app(SalaryStructureService::class)->set($this->employee('HW-'.$i, 'Held Wage '.$i), $basic, now()->subYear()->startOfMonth()->toDateString(), '10000');
        }
        $run = app(PayrollService::class)->build(now()->startOfMonth()->toDateString());
        $slips = $run->payslips()->orderBy('id')->get();
        $this->assertCount(28, $slips, 'প্রস্তুতিটাই ভুল — ২৮টা স্লিপ হয়নি।');

        // ⓘ প্রথম দুই স্লিপ এক ভাউচারে, বাকি প্রতিটা নিজের ভাউচারে — ২৭টা ভাউচার
        $vouchers = [];
        foreach ($slips as $n => $slip) {
            $vouchers[$n] = $n === 1 ? $vouchers[0] : $this->voucher('HELD-'.str_pad((string) $n, 2, '0', STR_PAD_LEFT));
            HeldWagePayment::query()->create(['company_id' => $this->company->id, 'payslip_id' => $slip->id, 'payroll_run_id' => $run->id,
                'voucher_id' => $vouchers[$n]->id, 'channel' => 'bank', 'amount' => '100', 'created_by' => $this->owner->id]);
        }

        $first = $this->get(route('hr.payroll.held.index', ['per_page' => 25]))->assertOk();
        $this->assertSame(27, $first->viewData('papers')->total(), '⛔ ভাউচার প্রতি একটা নয়।');
        $this->assertSame(2, $first->viewData('papers')->lastPage(), '⛔ কাগজের তালিকা পাতায় ভাগ হয়নি।');
        $oldest = route('hr.payroll.held.paper', $vouchers[0]->id);
        $first->assertDontSee('"'.$oldest.'"', false);

        $this->get(route('hr.payroll.held.index', ['per_page' => 25, 'page' => 2]))->assertOk()->assertSee('"'.$oldest.'"', false);

        $file = $this->get($oldest)->assertOk();
        // ⓘ শিরোনাম আর ঐ ভাউচারের দুই লাইন
        $this->assertSame(3, substr_count((string) $file->getContent(), "\r\n"), '⛔ পুরনো ভাউচারের ফাইলে তার দুই লাইন নেই।');
    }

    public function test_the_hr_law_command_never_marks_a_group_account(): void
    {
        $group = StandardChart::find(StandardChart::WPPF_PAYABLE);
        $plain = StandardChart::find(StandardChart::WPPF_EXPENSE);
        $this->assertNotNull($group);
        $this->assertNotNull($plain);
        Account::query()->whereKey($group->id)->update(['is_system' => false, 'is_group' => true]);
        Account::query()->whereKey($plain->id)->update(['is_system' => false, 'is_group' => false]);

        $this->artisan('abos:hr-law-accounts')->assertSuccessful();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->assertFalse((bool) Account::query()->whereKey($group->id)->value('is_system'), '⛔ দল-খাত সিস্টেমের চিহ্ন পেল।');
        $this->assertTrue((bool) Account::query()->whereKey($plain->id)->value('is_system'), '⛔ সাধারণ আইনি খাত চিহ্ন পেল না — দাবি কিছু মাপছে না।');
    }

    /** ⭐ খোঁজার খালি ঘর: দরজার সব চাবি না থাকলে কাজটা প্রস্তাবে নয়, আর বাছা কর্মী ছাড়া যে পাতা নেই সেটা কারও প্রস্তাবে নয় */
    public function test_the_search_box_offers_no_settlement_page_that_will_not_open(): void
    {
        $clerk = User::factory()->create(['current_company_id' => $this->company->id, 'is_active' => true]);
        $clerk->companies()->attach($this->company->id, ['is_active' => true]);
        $clerk->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate('hr.salary.view', 'web'));

        foreach ([$clerk->fresh(), $this->owner->fresh()] as $who) {
            $this->actingAs($who);
            $offered = array_column(app(\App\Core\Engines\Search\StartingPoints::class)->actions($who), 'url');
            $this->assertNotContains(route('hr.final_settlement.preview'), $offered, '⛔ কর্মী ছাড়া খোলে না এমন পাতা প্রস্তাবে।');
        }
    }

    public function test_a_settlement_is_never_made_in_another_companys_books(): void
    {
        $other = Company::query()->whereKeyNot($this->company->id)->firstOrFail();
        $theirs = $this->employee('FS-X', 'Other Company Leaver');
        $theirs->forceFill(['leaving_date' => now()->toDateString()])->save();
        app(\App\Core\Services\SettingsService::class)->set('hr.shared_across_companies', true);

        CompanyContext::set($other->id, $other->defaultBranch()?->id);
        $this->assertNotNull(Employee::query()->find($theirs->id), 'প্রস্তুতিটাই ভুল — এক খাতার সুইচে অন্য কোম্পানির কর্মী দেখা যায় না।');

        try {
            app(\App\Modules\Hr\Services\FinalSettlements::class)->prepare($theirs, 'resignation', null, $this->owner);
            $this->fail('⛔ অন্য কোম্পানির কর্মীর চূড়ান্ত পাওনা এই কোম্পানির খাতায়।');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertSame([__('hr::settlement.other_company')], $e->errors()['employee'] ?? null);
        }
    }

    public function test_the_owners_own_report_never_shows_the_workers_profit_fund_amount(): void
    {
        $amount = new \ReflectionMethod(\App\Core\Engines\Approval\OwnerSelfApproval::class, 'approvedAmountOf');

        $this->assertSame('NULL', $amount->invoke(app(\App\Core\Engines\Approval\OwnerSelfApproval::class), \App\Modules\Hr\Models\WppfProvision::class, ['amount', 'wppf']),
            '⛔ হিসাবের রিপোর্টে তহবিলের অঙ্ক — ৫% থেকে গোটা মুনাফা জানা যায়।');
    }

    private function employee(string $code, string $name): Employee
    {
        return app(EmployeeService::class)->create([
            'code' => $code, 'name_en' => $name, 'joining_date' => now()->subYears(2)->toDateString(), 'payment_method' => 'cash',
        ]);
    }

    private function settlement(Employee $employee, string $net, string $status): void
    {
        FinalSettlement::query()->create([
            'company_id' => $this->company->id, 'employee_id' => $employee->id, 'last_day' => now()->toDateString(), 'separation' => 'resignation',
            'wages' => $net, 'leave_encashment' => '0', 'gratuity' => '0', 'provident_fund' => '0', 'advance_recovered' => '0', 'net' => $net,
            'details' => [], 'status' => $status, 'requested_by' => $this->owner->id,
        ]);
    }

    private function voucher(string $no): Voucher
    {
        return Voucher::query()->create([
            'company_id' => $this->company->id, 'branch_id' => CompanyContext::branchId(), 'type' => Voucher::PAYMENT, 'document_no' => $no,
            'financial_year_id' => FinancialYear::forDate(now()->toDateString())?->id, 'trx_date' => now()->toDateString(),
            'amount' => '100', 'status' => 'draft', 'created_by' => $this->owner->id,
        ]);
    }
}
