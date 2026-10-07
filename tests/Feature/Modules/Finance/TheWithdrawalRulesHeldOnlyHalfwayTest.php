<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\Withdrawal;
use App\Modules\Finance\Models\WithdrawalLimit;
use App\Modules\Finance\Services\WithdrawalService;
use App\Modules\MasterData\Models\Person;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * উত্তোলনের নিয়মগুলো অর্ধেক পথে থামত — অডিট ম২৮, ছ৫ (৪ অক্টোবর ২০২৬)।
 *
 * ── ⛔ কী ছিল ─────────────────────────────────────────────────────────
 *   • মাসিক সীমা মাপা হত লেখা তারিখের মাসে — গত মাসের তারিখ লিখে এই মাসের সীমা পেরোনো যেত।
 *   • অনুরোধের দিন ছক না থাকলে পরে ছক বসলেও খসড়াটা সই ছাড়াই পোস্ট হত।
 *   • "না" পাওয়া অনুরোধ খসড়া হয়েই ঝুলে থাকত — সীমায় গোনা, তালিকায় "চলছে"।
 *   • টাকা বেরোনোর খাত যেকোনো খাত হতে পারত — আয়ের খাত থেকেও "উত্তোলন"।
 */
final class TheWithdrawalRulesHeldOnlyHalfwayTest extends TestCase
{
    use PutsMoneyInTheTill;
    use RefreshDatabase;

    private Person $partner;

    private User $signer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        $this->signer = User::query()->where('email', 'accounts@abos.test')->firstOrFail();

        app(StandardChart::class)->install();
        app(CashTillService::class)->ensurePrimaryTill();
        $this->putMoneyIn($this->cash(), '500000', now()->subMonths(2)->toDateString());

        $this->partner = Person::query()->create([
            'code' => 'WDR-P', 'name_en' => 'Withdrawing Partner', 'name_bn' => 'Withdrawing Partner', 'is_active' => true,
        ]);
    }

    public function test_a_back_dated_request_still_counts_against_this_months_cap(): void
    {
        WithdrawalLimit::query()->create(['person_id' => $this->partner->id, 'monthly_cap' => '10000']);

        $this->ask('8000', now()->toDateString());

        $this->assertRefused(fn () => $this->ask('5000', now()->subMonthNoOverflow()->toDateString()), 'amount',
            '⛔ এই মাসের সীমা ফুরানোর পরে গত মাসের তারিখ লিখে আরও ৫,০০০ তোলা গেল।');
    }

    public function test_a_draft_made_before_the_flow_does_not_post_without_a_signature(): void
    {
        $withdrawal = $this->ask('5000');

        $this->flow();

        $this->assertRefused(fn () => app(WithdrawalService::class)->post($withdrawal->fresh(), $this->cash()), 'status',
            '⛔ ছক বসার পরেও পুরনো খসড়াটা সই ছাড়াই খাতায় বসল।');

        $this->assertFalse($withdrawal->fresh()->isPosted());
        $this->assertSame(1, Approval::query()->where('action', 'withdrawal')->where('status', Approval::PENDING)->count(),
            'পোস্টের মুহূর্তে সই চাওয়া হয়নি — কাগজটা কারও ইনবক্সে নেই।');
    }

    public function test_a_refused_withdrawal_is_cancelled(): void
    {
        $this->flow();
        $withdrawal = $this->ask('5000');

        app(ApprovalEngine::class)->reject(
            Approval::query()->where('action', 'withdrawal')->where('status', Approval::PENDING)->latest('id')->firstOrFail(),
            $this->signer,
            'এখন নয়',
        );

        $this->assertSame(DocumentStatus::CANCELLED, $withdrawal->fresh()->status, '⛔ "না" পাওয়া উত্তোলন খসড়া হয়েই ঝুলে আছে।');
    }

    public function test_money_leaves_only_from_a_money_account(): void
    {
        $withdrawal = $this->ask('5000');

        $this->assertRefused(fn () => app(WithdrawalService::class)->post($withdrawal, StandardChart::find(StandardChart::INTEREST_INCOME)),
            'money_account_id', '⛔ আয়ের খাত থেকে "উত্তোলন" বসল — নগদ কমেনি, অথচ মালিকের নামে টাকা উঠল।');

        $this->assertFalse($withdrawal->fresh()->isPosted());
    }

    // ── সহায়ক ───────────────────────────────────────────────────────────

    private function ask(string $amount, ?string $on = null): Withdrawal
    {
        return app(WithdrawalService::class)->request([
            'person_id' => $this->partner->id,
            'amount' => $amount,
            'trx_date' => $on ?? now()->toDateString(),
        ]);
    }

    private function flow(): void
    {
        $flow = ApprovalFlow::query()->create(['module' => 'finance', 'action' => 'withdrawal', 'is_active' => true]);

        ApprovalFlowStep::query()->create([
            'approval_flow_id' => $flow->id,
            'level' => 1,
            'approver_type' => ApprovalFlowStep::BY_USER,
            'approver_id' => $this->signer->id,
        ]);

        $this->app->forgetInstance(ApprovalEngine::class);
    }

    private function assertRefused(callable $what, string $field, string $why): void
    {
        try {
            $what();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors(), $why.' (অন্য ঘরে আটকেছে: '.implode(', ', array_keys($e->errors())).')');

            return;
        }

        $this->fail($why);
    }

    private function cash(): Account
    {
        return Account::query()->money()->postable()->active()->orderBy('code')->firstOrFail();
    }
}
