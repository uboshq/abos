<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\CostCenter;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Finance\Models\Budget;
use App\Modules\Finance\Services\BudgetService;
use App\Modules\Finance\Services\CashForecast;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * ⭐ মাসের পরিকল্পনা এখন খাতার সাথে মেলে — ফিন্যান্সের মানচিত্র §১, §৮,
 * §১৬, §২৯; ২০ সেপ্টেম্বর ২০২৬।
 *
 * ⓘ প্রতিটা পর্দা আসল রুট দিয়ে খোলা হয়, আর "প্রকৃত" মাপা হয় সত্যিকারের
 * পোস্ট করা ভাউচার দিয়ে — খতিয়ানে হাতে সারি বসিয়ে নয়।
 */
final class TheMonthsPlanNowMeetsTheBooksTest extends TestCase
{
    use RefreshDatabase;

    private Account $expense;

    private Account $cash;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->expense = Account::query()->postable()->where('type', Account::EXPENSE)->orderBy('code')->firstOrFail();
        $this->cash = Account::query()->money()->postable()->active()->orderBy('code')->firstOrFail();
    }

    private function spend(string $amount, string $date, ?int $centerId = null): void
    {
        $voucher = app(VoucherService::class)->create(
            ['type' => Voucher::JOURNAL, 'trx_date' => $date, 'narration' => 'budget test'],
            [
                ['account_id' => $this->expense->id, 'debit' => $amount, 'credit' => '0', 'cost_center_id' => $centerId],
                ['account_id' => $this->cash->id, 'debit' => '0', 'credit' => $amount],
            ],
        );

        app(VoucherService::class)->post($voucher);
    }

    private function center(string $code): CostCenter
    {
        return CostCenter::query()->create([
            'company_id' => CompanyContext::id(), 'code' => $code, 'name_en' => $code, 'is_active' => true,
        ]);
    }

    /**
     * ⭐ বারো মাস এক জমায়; খালি মাস = বাজেট নেই; আবার জমায় বদলায়, দ্বিগুণ হয় না।
     */
    public function test_a_year_is_written_in_one_save_and_rewritten_in_place(): void
    {
        $this->post(route('finance.budget.store'), [
            'year' => 2026, 'account_id' => $this->expense->id,
            'months' => [1 => '5000', 2 => '5000', 3 => ''],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(2, Budget::query()->count());

        $this->post(route('finance.budget.store'), [
            'year' => 2026, 'account_id' => $this->expense->id,
            'months' => [1 => '6000', 2 => ''],
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, Budget::query()->count(), 'খালি মাস মোছেনি, বা সারি দ্বিগুণ হয়েছে।');
        $this->assertSame('6000.0000', Budget::query()->sole()->amount);

        $this->get(route('finance.budget.index', ['year' => 2026]))
            ->assertOk()
            ->assertSee($this->expense->code);
    }

    /**
     * ⛔ সম্পদ বা গ্রুপ খাতে বাজেট নয় — কেবল আয় আর খরচ।
     */
    public function test_a_budget_goes_only_on_income_or_expense(): void
    {
        $this->post(route('finance.budget.store'), [
            'year' => 2026, 'account_id' => $this->cash->id, 'months' => [1 => '100'],
        ])->assertSessionHasErrors('account_id');

        $this->assertSame(0, Budget::query()->count());
    }

    /**
     * ⭐ বাজেট বনাম প্রকৃত — প্রকৃত খতিয়ান থেকে, সময়ের বাইরের খরচ বাদ।
     */
    public function test_actual_comes_from_the_ledger_for_the_months_asked(): void
    {
        // ⓘ আগস্ট–সেপ্টেম্বর: ডেমোর অর্থবছর ২০২৬-০৭-০১ থেকে ২০২৭-০৬-৩০, আর তার
        // বাইরের তারিখে ভাউচার বসে না ("কোনো অর্থবছরের মধ্যে পড়ে না")
        app(BudgetService::class)->saveYear(2026, $this->expense->id, null, [8 => '10000']);

        $this->spend('7000', '2026-08-10');
        $this->spend('9999', '2026-09-02'); // সেপ্টেম্বর — আগস্টের তুলনায় আসে না

        $row = app(BudgetService::class)->vsActual(2026, 8, 8)->sole();

        $this->assertSame('10000.0000', $row['budget']);
        $this->assertSame('7000.0000', $row['actual']);
        $this->assertSame('-3000.0000', $row['variance']);
        $this->assertSame('70.0', $row['used_pct']);
        $this->assertTrue($row['over_is_bad']);

        $this->get(route('finance.budget.actual', ['year' => 2026, 'month' => 8]))->assertOk();
        $this->get(route('finance.budget.report', ['year' => 2026]))->assertOk();
    }

    /**
     * ⭐ বিভাগভিত্তিক — প্রতিটা বিভাগের প্রকৃত কেবল তার নিজের সারি থেকে।
     */
    public function test_by_department_each_center_sees_only_its_own_spending(): void
    {
        $sales = $this->center('TSALES');
        $depot = $this->center('TDEPOTC');

        app(BudgetService::class)->saveYear(2026, $this->expense->id, $sales->id, [9 => '1000']);
        app(BudgetService::class)->saveYear(2026, $this->expense->id, $depot->id, [9 => '2000']);

        $this->spend('1500', '2026-09-05', $sales->id);
        $this->spend('300', '2026-09-06', $depot->id);

        $rows = app(BudgetService::class)->vsActual(2026, 9, 9, null, byCenter: true)
            ->keyBy(fn ($r) => $r['center']?->code);

        $this->assertSame('1500.0000', $rows['TSALES']['actual']);
        $this->assertTrue($rows['TSALES']['over'], 'বেশি খরচ হয়েও "বেশি" বলেনি।');
        $this->assertSame('300.0000', $rows['TDEPOTC']['actual']);

        $this->get(route('finance.budget.centers', ['year' => 2026, 'month' => 9]))
            ->assertOk()
            ->assertSee('TSALES');
    }

    /**
     * ⭐ নগদের পূর্বাভাস — আজকের টাকা থেকে শুরু, আর চার ঝুড়ির শেষ সংখ্যা
     * চলতি যোগফল।
     */
    public function test_the_cash_forecast_runs_from_todays_money(): void
    {
        $forecast = app(CashForecast::class)->build();

        $this->assertCount(4, $forecast['rows']);

        $running = $forecast['opening'];

        foreach ($forecast['rows'] as $row) {
            $running = bcadd($running, $row['net'], 4);
            $this->assertSame($running, $row['closing'], "{$row['bucket']}-এর শেষ সংখ্যা চলতি যোগফল নয়।");
        }

        $this->get(route('finance.forecast.cash'))->assertOk();
    }

    /**
     * ⭐ CFO ড্যাশবোর্ড খোলে, আর বাজেট থাকলে বাজেটের অবস্থার কার্ডও দেখায়।
     */
    public function test_the_cfo_dashboard_opens_and_shows_the_budget_status(): void
    {
        app(BudgetService::class)->saveYear((int) now()->year, $this->expense->id, null, [(int) now()->month => '1000']);

        $this->get(route('finance.cfo'))
            ->assertOk()
            ->assertSee(__('finance::forecast.current_ratio'))
            ->assertSee(__('finance::budget.status'));
    }

    /**
     * ⛔ অনুমতি: বাজেট দেখার চাবি ছাড়া পাতাটা খোলে না, আর লেখার চাবি ছাড়া
     * জমা হয় না।
     */
    public function test_the_doors_need_their_keys(): void
    {
        $clerk = User::factory()->create();
        $company = Company::query()->whereKey(CompanyContext::id())->firstOrFail();
        $clerk->companies()->attach($company, ['is_active' => true]);
        $clerk->forceFill(['current_company_id' => $company->id])->save();
        $clerk->givePermissionTo(Permission::findOrCreate('finance.budget.view', 'web'));

        $this->actingAs($clerk);

        $this->get(route('finance.budget.index'))->assertOk();
        $this->get(route('finance.cfo'))->assertForbidden();
        $this->post(route('finance.budget.store'), [
            'year' => 2026, 'account_id' => $this->expense->id, 'months' => [1 => '1'],
        ])->assertForbidden();
    }
}
