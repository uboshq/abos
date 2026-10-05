<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\CashTill;
use App\Modules\Accounts\Services\AccountsSignature;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * গ১ — খোলা জেরসহ নতুন ক্যাশবাক্স: জেরটা সই ছাড়া খাতায় ওঠে না, শেষ সইয়ে ওঠে (Accounts-Finance অডিট, ৪ অক্টোবর ২০২৬)।
 *
 * ⛔ অডিটের উদাহরণ: হিসাবরক্ষক নগদ ঘাটতি ঢাকতে ঘাটতির সমান "খোলা জের" দিয়ে নতুন বাক্স বানিয়ে ব্যাংকে পাঠাতে পারতেন —
 * কোনো সই নেই। ⓘ ছক বন্ধে (UB) আগের মতোই এখনই।
 */
final class ANewTillsOpeningBalanceWaitsForItsSignatureTest extends TestCase
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
    }

    public function test_with_the_flow_on_the_opening_balance_waits_for_the_last_signature(): void
    {
        $flow = ApprovalFlow::create(['company_id' => $this->company->id, 'module' => AccountsSignature::MODULE,
            'action' => AccountsSignature::TILL_OPENING, 'is_active' => true]);
        ApprovalFlowStep::create(['approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => 'user', 'approver_id' => $this->owner->id]);

        $till = $this->open('25000');

        $this->assertSame(0, bccomp($till->balance(), '0', 4), '⛔ সইয়ের আগেই খোলা জের খাতায় উঠল — ঘাটতি ঢাকার পথ খোলা।');
        $this->assertSame(0, bccomp((string) $till->account->opening_balance, '25000', 4), 'ঘোষণাটা খাতে নেই — সইকারী কী দেখবেন?');

        $approval = app(ApprovalEngine::class)->latestFor($till, AccountsSignature::TILL_OPENING);
        $this->assertSame(Approval::PENDING, $approval?->status, 'সই চাওয়াই হয়নি।');

        app(ApprovalEngine::class)->approve($approval, $this->owner);

        $this->assertSame(0, bccomp($till->fresh()->balance(), '25000', 4), '⛔ শেষ সই পড়ল, তবু খোলা জের খাতায় উঠল না।');
    }

    public function test_with_the_flow_off_the_opening_balance_lands_at_once(): void
    {
        $till = $this->open('25000');

        $this->assertSame(0, bccomp($till->balance(), '25000', 4), '⛔ ছক বন্ধ, তবু খোলা জের আটকে গেল।');
    }

    public function test_a_till_without_an_opening_balance_asks_nobody(): void
    {
        $flow = ApprovalFlow::create(['company_id' => $this->company->id, 'module' => AccountsSignature::MODULE,
            'action' => AccountsSignature::TILL_OPENING, 'is_active' => true]);
        ApprovalFlowStep::create(['approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => 'user', 'approver_id' => $this->owner->id]);

        $till = $this->open('0');

        $this->assertNull(app(ApprovalEngine::class)->latestFor($till, AccountsSignature::TILL_OPENING), '⛔ শূন্য জেরের বাক্সেও সই চাওয়া হলো।');
    }

    private function open(string $opening): CashTill
    {
        return app(CashTillService::class)->create([
            'code' => 'G1T-'.random_int(100, 999),
            'name_en' => 'G1 Till',
            'name_bn' => 'গ১ বাক্স',
            'opening_balance' => $opening,
            'opening_date' => now()->toDateString(),
        ])->fresh(['account']);
    }
}
