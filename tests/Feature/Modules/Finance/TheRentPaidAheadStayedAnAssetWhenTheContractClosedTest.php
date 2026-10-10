<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\RentalContract;
use App\Modules\Finance\Services\RentalAccrualService;
use App\Modules\Finance\Services\RentalContractService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * ⛔ আগাম দেওয়া ভাড়া চুক্তি আগে শেষ হলে চিরকাল সম্পদ হয়ে থাকত — পুরো-ERP পুনঃঅডিট, ৯ অক্টোবর ২০২৬ (সারাই ১)।
 *
 * ⓘ সামনের মাসের ভাড়া ১১৩৭ অগ্রিম ভাড়ায় বসে, আর মাসটা এলে মাসের জমা সেটা খরচে সরায় — কিন্তু জমা কেবল চালু চুক্তি দেখে।
 * আগে চুক্তি শেষ করলে আগাম মাসগুলোর টাকা ১১৩৭-এ রয়ে যেত: না খরচ, না ফেরত। মালিকের নিয়ম: নীরবে টাকা হারানো চলবে না।
 *
 * ⭐ দাবি:
 *   · শেষের পরের আগাম মাস — টাকার খাত না দিলে চুক্তি শেষ হয় না, কারণসহ; ১১৩৭ যেমন ছিল
 *   · টাকার খাত দিলে আগাম ভাড়া জামানতের সাথে একই রসিদে ফেরত — ১১৩৭ শূন্য, জামানত শূন্য, চুক্তি শেষ
 *   · শেষের মাস বা আগের আগাম মাস, যা এখনো খরচে যায়নি — শেষ হয় না, বলে আগে মাসের জমা চালাতে
 */
final class TheRentPaidAheadStayedAnAssetWhenTheContractClosedTest extends TestCase
{
    use PutsMoneyInTheTill;
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
        app(CashTillService::class)->ensurePrimaryTill();

        Carbon::setTestNow(now()->startOfMonth()->addDays(9));
        $this->putMoneyIn($this->cash(), '1000000', now()->startOfMonth()->subMonths(2)->toDateString());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_months_paid_ahead_come_back_with_the_deposit_and_never_stay_in_prepaid_rent(): void
    {
        $contract = $this->contractWithTwoMonthsAhead();
        $this->assertMoney('20000', $this->net(StandardChart::PREPAID_RENT), 'দৃশ্যটাই বানানো যায়নি — দুই মাসের আগাম ভাড়া ১১৩৭-এ নেই');

        try {
            app(RentalContractService::class)->close($contract->fresh());
            $this->fail('⛔ আগাম দেওয়া দুই মাস রেখেই চুক্তি শেষ হলো — ২০,০০০ টাকা ১১৩৭-এ চিরকাল পড়ে থাকত');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('money_account_id', $e->errors(), 'অন্য কারণে থেমেছে: '.implode(', ', array_keys($e->errors())));
            $this->assertStringContainsString(Carbon::now()->addMonthNoOverflow()->translatedFormat('F Y'), $e->errors()['money_account_id'][0],
                '⛔ বার্তায় কোন মাস তা নেই');
        }

        $this->assertSame(RentalContract::ACTIVE, $contract->fresh()->status);
        $this->assertMoney('20000', $this->net(StandardChart::PREPAID_RENT), '⛔ থামার পরেও ১১৩৭ নড়েছে');

        app(RentalContractService::class)->close($contract->fresh(), ['money_account_id' => $this->cash()->id]);

        $this->assertSame(RentalContract::CLOSED, $contract->fresh()->status);
        $this->assertMoney('0', $this->net(StandardChart::PREPAID_RENT), '⛔ চুক্তি শেষ, অথচ আগাম ভাড়া ১১৩৭-এ রয়ে গেল');
        $this->assertMoney('0', $this->net(StandardChart::SECURITY_DEPOSIT), '⛔ জামানত ফেরত আসেনি');
    }

    public function test_a_month_paid_ahead_that_has_come_must_go_to_expense_before_the_contract_closes(): void
    {
        $contract = $this->contractWithTwoMonthsAhead();
        $last = now()->addMonthNoOverflow()->endOfMonth();

        // ⓘ শেষ হচ্ছে পরের মাসের শেষে — ঐ মাসের আগাম ভাড়া ব্যবহার হয়েছে, অথচ জমা এখনো সেটা খরচে সরায়নি
        try {
            app(RentalContractService::class)->close($contract->fresh(), [
                'closed_on' => $last->toDateString(), 'money_account_id' => $this->cash()->id,
            ]);
            $this->fail('⛔ খরচে না-যাওয়া আগাম মাস রেখে চুক্তি শেষ হলো');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('closed_on', $e->errors(), 'অন্য কারণে থেমেছে: '.implode(', ', array_keys($e->errors())));
        }

        $this->assertSame(RentalContract::ACTIVE, $contract->fresh()->status);
        $this->assertMoney('20000', $this->net(StandardChart::PREPAID_RENT), '⛔ থামার পরেও ১১৩৭ নড়েছে');
    }

    /**
     * ⛔ বন্ধের ফেরত সইয়ের অপেক্ষায়, এর মাঝে মাসের জমা আগাম মাসটা ১১৩৭ থেকে খরচে সরাত — সই পড়লে ফেরতও ১১৩৭-এ জমা, একই
     * টাকা দুবার, ১১৩৭ ঋণাত্মক (cloud/finance-fixes রিভিউ ⛔২, ১০ অক্টোবর ২০২৬; [[RentalAccrualService::assertNothingWaiting()]])।
     */
    public function test_the_month_waits_while_the_closing_refund_waits_for_its_signature(): void
    {
        $contract = $this->contractWithTwoMonthsAhead();

        $flow = \App\Models\ApprovalFlow::query()->create(['module' => 'finance', 'action' => 'rental', 'is_active' => true]);
        \App\Models\ApprovalFlowStep::query()->create([
            'approval_flow_id' => $flow->id, 'level' => 1,
            'approver_type' => \App\Models\ApprovalFlowStep::BY_USER,
            'approver_id' => User::query()->where('email', 'accounts@abos.test')->value('id'),
        ]);
        $this->app->forgetInstance(\App\Core\Engines\Approval\ApprovalEngine::class);

        app(RentalContractService::class)->close($contract->fresh(), ['money_account_id' => $this->cash()->id]);
        $this->assertTrue(app(RentalContractService::class)->isWaiting($contract->fresh()), 'দৃশ্যটাই বানানো যায়নি — বন্ধের ফেরত সইয়ের অপেক্ষায় নেই।');
        $this->assertMoney('20000', $this->net(StandardChart::PREPAID_RENT), 'দৃশ্যটাই বানানো যায়নি — ফেরত খাতায় বসে গেছে');

        // ⓘ পরের মাস এলো — আগাম দেওয়া মাসটা জমার পালা, অথচ ফেরত এখনো সইয়ের অপেক্ষায়
        Carbon::setTestNow(now()->startOfMonth()->addMonthNoOverflow()->addDays(9));
        $result = app(RentalAccrualService::class)->run(now()->startOfMonth());

        $this->assertMoney('20000', $this->net(StandardChart::PREPAID_RENT), '⛔ ফেরত সইয়ের অপেক্ষায়, তবু আগাম মাস ১১৩৭ থেকে খরচে সরল');
        $this->assertNotEmpty($result['failed'], '⛔ থেমে থাকা চুক্তি "বসেনি"-র তালিকায় নেই — মালিক জানবেন না কেন');

        $pending = \App\Models\Approval::query()->where('status', \App\Models\Approval::PENDING)->latest('id')->firstOrFail();
        app(\App\Core\Engines\Approval\ApprovalEngine::class)->approve($pending, User::query()->where('email', 'accounts@abos.test')->firstOrFail());

        $this->assertMoney('0', $this->net(StandardChart::PREPAID_RENT), '⛔ সই পড়ার পরে ১১৩৭ শূন্যে নেই — আগাম ভাড়া দুবার গোনা হলো');
    }

    /** চুক্তি গত মাস থেকে, এই মাস জমা ও দেওয়া, পরের দুই মাস আগাম দেওয়া */
    private function contractWithTwoMonthsAhead(): RentalContract
    {
        $contract = app(RentalContractService::class)->open([
            'counterparty' => 'Ahead Landlord', 'subject' => 'Ahead godown', 'deposit_amount' => '30000', 'monthly_rent' => '10000',
            'monthly_adjustment' => '0', 'term_months' => 24, 'starts_on' => now()->startOfMonth()->subMonth()->toDateString(), 'rent_day' => 5,
            'money_account_id' => $this->cash()->id,
        ]);

        // ⓘ গত মাসটা দেওয়া — বসানো অথচ না-দেওয়া মাস যেন না থাকে (সেটা আলাদা দাবি)
        app(RentalContractService::class)->adjustMonth($contract->fresh(), [
            'for_month' => now()->startOfMonth()->subMonth()->toDateString(), 'rent' => '10000', 'from_deposit' => '0', 'money_account_id' => $this->cash()->id,
        ]);
        app(RentalAccrualService::class)->run(now()->startOfMonth());

        foreach ([0, 1, 2] as $ahead) {
            app(RentalContractService::class)->adjustMonth($contract->fresh(), [
                'for_month' => now()->startOfMonth()->addMonthsNoOverflow($ahead)->toDateString(),
                'rent' => '10000', 'from_deposit' => '0', 'money_account_id' => $this->cash()->id,
            ]);
        }

        return $contract->fresh();
    }

    private function net(string $code): string
    {
        return (string) LedgerEntry::query()->where('company_id', $this->company->id)->where('account_id', StandardChart::find($code)->id)
            ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as n')->value('n');
    }

    private function cash(): Account
    {
        return Account::query()->money()->postable()->active()->orderBy('code')->firstOrFail();
    }

    private function assertMoney(string $expected, mixed $actual, string $message): void
    {
        $this->assertSame(0, bccomp($expected, (string) ($actual ?? '0'), 2), "{$message}: চাই {$expected}, এল ".var_export($actual, true));
    }
}
