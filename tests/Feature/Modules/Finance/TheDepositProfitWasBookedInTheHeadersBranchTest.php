<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\PeriodLock;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\Deposit;
use App\Modules\Finance\Models\DepositAccrual;
use App\Modules\Finance\Models\DepositKind;
use App\Modules\Finance\Models\DepositMovement;
use App\Modules\Finance\Services\DepositAccrualService;
use App\Modules\Finance\Services\DepositKindInstaller;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⛔ আমানতের মাসিক অর্জিত মুনাফা ব্যাংক ঋণের সুদ জমার ৯ অক্টোবরের সারাইগুলো পায়নি — লাইন-ধরে নিরীক্ষা, ১৩ অক্টোবর ২০২৬
 * ([[DepositAccrualService]]; সুদের জোড়া [[TheReversalWasStuckInAClosedMonthTest]], [[MonthEndCoversTheSameBranchesItReversesTest]])।
 *
 * ⓘ আগে:
 *   · জমার ভাউচার আর তার উল্টোটা হেডারের শাখায় বসত, জমার শাখায় নয় — শাখার আয় ভুল; আর অন্য শাখা হেডারে থাকলে আগের মাসের
 *     ভাউচার "নেই" হয়ে উল্টো দাখিলা চুপচাপ বাদ পড়ত — মুনাফা দুবার আয়ে
 *   · উল্টানোর দিন বন্ধ মাসে পড়লে গোটা চাল থামত — পরের কোনো মাসই বসত না
 *   · চালানো মাসটাই বন্ধ হলে আগে উল্টো দাখিলা বসে তারপর থামত
 *   · এক শাখায় সীমিত মানুষ চালালে জমা বসত কেবল তাঁর শাখায়, অথচ উল্টাত সব শাখার — অন্য শাখার মুনাফা সে মাসে উধাও
 */
final class TheDepositProfitWasBookedInTheHeadersBranchTest extends TestCase
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
        CompanyContext::set($this->company->id, $this->branch('MMS')->id);
        $this->actingAs($this->owner);
        app(StandardChart::class)->install();
        app(DepositKindInstaller::class)->install();

        Carbon::setTestNow('2026-11-15 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_accrual_and_its_reversal_sit_in_the_deposits_branch(): void
    {
        $there = $this->deposit('FDR-NTK', 'NTK');

        app(DepositAccrualService::class)->run(Carbon::parse('2026-08-01'));
        $august = DepositAccrual::query()->where('deposit_id', $there->id)->sole();
        $this->assertSame((int) $this->branch('NTK')->id, (int) Voucher::acrossBranches()->findOrFail($august->voucher_id)->branch_id,
            '⛔ নেত্রকোনার জমার মুনাফা হেডারের শাখায় বসল');

        app(DepositAccrualService::class)->run(Carbon::parse('2026-09-01'));
        $august->refresh();
        $this->assertNotNull($august->reversal_voucher_id, '⛔ আগস্টের মুনাফা উল্টাল না — আয় দুবার');
        $this->assertSame((int) $this->branch('NTK')->id, (int) Voucher::acrossBranches()->findOrFail($august->reversal_voucher_id)->branch_id,
            '⛔ উল্টো দাখিলা জমার শাখায় নয়');
        $this->assertSame(1, DepositAccrual::query()->whereNull('reversal_voucher_id')->count(), '⛔ খাতায় ঠিক একটা চলতি জমা নেই');
    }

    public function test_a_reversal_due_in_a_closed_month_lands_on_the_first_day_of_the_month_being_run(): void
    {
        $this->deposit('FDR-MMS', 'MMS');
        app(DepositAccrualService::class)->run(Carbon::parse('2026-08-01'));
        $this->closeMonth(9);

        $done = app(DepositAccrualService::class)->run(Carbon::parse('2026-10-01'));

        $august = DepositAccrual::query()->where('for_month', '2026-08-01')->sole();
        $this->assertNotNull($august->reversal_voucher_id, '⛔ আগস্টের মুনাফা উল্টাল না');
        $this->assertSame('2026-10-01', Voucher::acrossBranches()->findOrFail($august->reversal_voucher_id)->trx_date->toDateString(),
            '⛔ উল্টো দাখিলা বন্ধ মাসের বাইরে প্রথম খোলা দিনে নয়');
        $this->assertSame(1, $done['accrued'], '⛔ অক্টোবরের মুনাফা বসেনি');
    }

    public function test_a_closed_month_is_refused_before_anything_is_written(): void
    {
        $this->deposit('FDR-MMS', 'MMS');
        app(DepositAccrualService::class)->run(Carbon::parse('2026-08-01'));
        $this->closeMonth(10);
        $vouchers = Voucher::acrossBranches()->count();

        try {
            app(DepositAccrualService::class)->run(Carbon::parse('2026-10-01'));
            $this->fail('⛔ বন্ধ মাসে মুনাফা বসল');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('month', $e->errors());
        }

        $this->assertSame($vouchers, Voucher::acrossBranches()->count(), '⛔ থামার আগে উল্টো দাখিলা বসে গেল');
        $this->assertNull(DepositAccrual::query()->where('for_month', '2026-08-01')->value('reversal_voucher_id'));
    }

    public function test_a_clerk_of_one_branch_reverses_only_what_he_can_book(): void
    {
        $here = $this->deposit('FDR-MMS', 'MMS');
        $there = $this->deposit('FDR-NTK', 'NTK');
        app(DepositAccrualService::class)->run(Carbon::parse('2026-08-01'));
        $this->assertSame(2, DepositAccrual::query()->count(), 'দৃশ্যটাই বানানো যায়নি — আগস্টে দুই জমায় মুনাফা বসেনি');

        $this->actingAs($this->clerkOf('MMS'));
        app(DepositAccrualService::class)->run(Carbon::parse('2026-09-01'));

        $this->assertSame(1, DepositAccrual::query()->where('deposit_id', $here->id)->where('for_month', '2026-09-01')->count());
        $this->assertSame(0, DepositAccrual::query()->where('deposit_id', $there->id)->where('for_month', '2026-09-01')->count());

        foreach ([$here, $there] as $deposit) {
            $this->assertSame(1, DepositAccrual::query()->where('deposit_id', $deposit->id)->whereNull('reversal_voucher_id')->count(),
                "⛔ {$deposit->document_no}-এর খাতায় ঠিক একটা চলতি জমা নেই — নাগালের বাইরের মুনাফা উল্টে গেল, নতুনটা বসল না");
        }
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    private function deposit(string $no, string $branch): Deposit
    {
        $deposit = Deposit::query()->create([
            'company_id' => $this->company->id, 'branch_id' => $this->branch($branch)->id,
            'document_no' => $no, 'kind_id' => DepositKind::query()->where('code', 'FDR')->value('id'),
            'institution' => 'সোনালী ব্যাংক', 'held_by' => Deposit::BUSINESS, 'principal' => '73000',
            'profit_rate' => '10', 'tax_rate' => '10', 'return_word' => 'interest',
            'opened_on' => '2026-07-01', 'matures_on' => '2027-07-01',
            'account_id' => StandardChart::find(StandardChart::DEPOSITS_AND_INVESTMENTS)->id, 'status' => Deposit::ACTIVE,
        ]);

        DepositMovement::query()->create([
            'company_id' => $this->company->id, 'deposit_id' => $deposit->id, 'kind' => DepositMovement::OPENED,
            'amount' => '73000', 'moved_on' => '2026-07-01',
        ]);

        return $deposit;
    }

    private function clerkOf(string $code): User
    {
        $clerk = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id, 'current_branch_id' => $this->branch($code)->id]);
        $clerk->companies()->attach($this->company->id, ['is_active' => true]);

        // ⓘ পেছনের মাস বসাতে পেছনের তারিখের ছাড় — নইলে সাত দিনের জানালা আগেই থামাত
        CompanyContext::forCompany($this->company->id, fn () => $clerk->givePermissionTo(Permission::findOrCreate('accounts.backdate.override', 'web')));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        UserDataScope::query()->create([
            'company_id' => $this->company->id, 'user_id' => $clerk->id,
            'scope_type' => UserDataScope::BRANCH, 'scope_id' => $this->branch($code)->id,
        ]);
        app(DataScope::class)->forget();

        return $clerk->fresh();
    }

    private function closeMonth(int $month): void
    {
        PeriodLock::query()->create([
            'company_id' => $this->company->id, 'year' => 2026, 'month' => $month,
            'reason' => 'রিপোর্ট পাঠানো হয়ে গেছে', 'locked_by' => auth()->id(), 'locked_at' => now(),
        ]);
    }

    private function branch(string $code): Branch
    {
        return Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', $code)->firstOrFail();
    }
}
