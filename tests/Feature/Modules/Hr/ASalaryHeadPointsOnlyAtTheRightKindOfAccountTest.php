<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Hr;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Hr\Models\SalaryHead;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ বেতনের খাত কেবল ঠিক ধরনের হিসাব-খাতে — পুরো-ERP পুনঃঅডিট, ৯ অক্টোবর ২০২৬ (HR ৯; [[SalaryHeadController]])।
 *
 * ⓘ আগে কেবল "এই কোম্পানির খাত" দেখা হত। আয়ের খাত নগদে বসালে বেতন নিশ্চিত করলেই নগদ বাড়ত (টাকা না এসেও), কর্তন আয়ে বসালে
 * কর্মীর কাছ থেকে কাটা টাকা বিক্রির আয় হয়ে যেত।
 */
final class ASalaryHeadPointsOnlyAtTheRightKindOfAccountTest extends TestCase
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

    public function test_an_earning_goes_only_to_an_expense_and_a_deduction_only_to_a_liability_or_the_advance(): void
    {
        $cash = (int) app(CashTillService::class)->ensurePrimaryTill()->account_id;
        $income = (int) Account::query()->postable()->active()->where('type', Account::INCOME)->value('id');
        $expense = (int) StandardChart::find(StandardChart::SALARY_EXPENSE)->id;
        $payable = (int) StandardChart::find(StandardChart::SALARY_PAYABLE)->id;
        $advance = (int) StandardChart::find(StandardChart::EMPLOYEE_ADVANCE)->id;

        // ⛔ ভুল ধরনের খাত — ফেরত, কারণসহ
        foreach ([[SalaryHead::EARNING, $cash], [SalaryHead::EARNING, $payable], [SalaryHead::DEDUCTION, $cash], [SalaryHead::DEDUCTION, $income]] as $i => [$kind, $account]) {
            $this->post(route('hr.salary_head.store'), $this->headRow("BAD{$i}", $kind, $account))->assertSessionHasErrors('account_id');
        }

        $this->assertSame(0, SalaryHead::query()->where('code', 'like', 'BAD%')->count(), '⛔ বেতনের খাত নগদ বা আয়ের খাতে বসল');

        // ⓘ ঠিক ধরনের — আগের মতোই
        foreach ([['OK1', SalaryHead::EARNING, $expense], ['OK2', SalaryHead::DEDUCTION, $payable], ['OK3', SalaryHead::DEDUCTION, $advance]] as [$code, $kind, $account]) {
            $this->post(route('hr.salary_head.store'), $this->headRow($code, $kind, $account))->assertSessionHasNoErrors();
        }

        $this->assertSame(3, SalaryHead::query()->where('code', 'like', 'OK%')->count());
    }

    /** @return array<string, mixed> */
    private function headRow(string $code, string $kind, int $account): array
    {
        return ['code' => $code, 'name_en' => 'Head '.$code, 'kind' => $kind, 'calculation' => SalaryHead::FIXED, 'account_id' => $account];
    }
}
