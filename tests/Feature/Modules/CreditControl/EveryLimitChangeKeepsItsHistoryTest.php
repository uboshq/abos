<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\CreditControl;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Models\CustomerCreditChange;
use App\Modules\Customer\Services\CustomerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⭐ সীমা বদলের ইতিহাস — প্রতিটা বদল এক সারি: কে, কখন, আগে, পরে, কেন, কোন সইয়ে (বাকি নিয়ন্ত্রণ ধাপ ১.৪, ১১ অক্টোবর ২০২৬)।
 *
 *   · সই পড়ে সীমা নিজে বসল → সারিতে আগে-পরে, অনুরোধের কারণ আর সইয়ের সূত্র।
 *   · সই না পড়া অনুরোধ → কোনো সারি নয় (সীমা বদলায়নি)।
 *   · কমানো (সই লাগে না) আর বাকির দিন বদল → সারি।
 *   · অন্য ঘর বদলালে বা একই অঙ্ক আবার লিখলে → সারি নয়।
 *   · সারি বদলানো যায় না।
 */
final class EveryLimitChangeKeepsItsHistoryTest extends TestCase
{
    use RefreshDatabase;

    private User $clerk;

    private User $manager;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $company = Company::create(['code' => 'HIST', 'name_en' => 'History Traders']);
        $branch = Branch::create(['company_id' => $company->id, 'code' => 'MAIN', 'name_en' => 'Main', 'is_default' => true]);
        CompanyContext::set($company->id, $branch->id);

        $this->clerk = User::create(['name' => 'Clerk', 'email' => 'clerk@hist.test', 'password' => 'x']);
        $this->manager = User::create(['name' => 'Manager', 'email' => 'manager@hist.test', 'password' => 'x']);
        $this->actingAs($this->clerk);

        $this->customer = Customer::create([
            'company_id' => $company->id, 'branch_id' => $branch->id, 'code' => 'CUS-HIST', 'name_en' => 'History Shop',
            'credit_limit' => '100000', 'credit_days' => 15, 'status' => DocumentStatus::CONFIRMED, 'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    public function test_a_signed_raise_is_written_with_its_reason_and_its_signature(): void
    {
        $this->aFlow();
        $this->ask('200000');

        $this->assertSame(0, $this->history()->count(), '⛔ সই পড়ার আগেই ইতিহাসে বদল — অথচ সীমা বদলায়নি।');

        $pending = Approval::query()->where('status', Approval::PENDING)->firstOrFail();
        app(ApprovalEngine::class)->approve($pending, $this->manager);

        $row = $this->history()->sole();
        $this->assertSame('credit_limit', $row->field);
        $this->assertSame(0, bccomp((string) $row->old_value, '100000', 4));
        $this->assertSame(0, bccomp((string) $row->new_value, '200000', 4));
        $this->assertSame((int) $pending->id, (int) $row->approval_id, 'কোন সইয়ে বদলাল — সূত্র নেই।');
        $this->assertSame($pending->requested_reason, $row->reason);
        $this->assertNotNull($row->created_at);
    }

    public function test_a_lowering_and_a_change_of_days_are_written(): void
    {
        app(CustomerService::class)->update($this->customer->fresh(), ['credit_limit' => '50000', 'credit_days' => 30]);

        $rows = $this->history()->orderBy('field')->get()->keyBy('field');
        $this->assertSame(['credit_days', 'credit_limit'], $rows->keys()->all());
        $this->assertSame(['15', '30'], [$rows['credit_days']->old_value, $rows['credit_days']->new_value]);
        $this->assertSame(0, bccomp((string) $rows['credit_limit']->new_value, '50000', 4));
        $this->assertNull($rows['credit_limit']->approval_id, 'কমাতে সই লাগে না — সূত্রও নেই।');
        $this->assertSame($this->clerk->id, (int) $rows['credit_limit']->changed_by);
    }

    public function test_nothing_is_written_when_the_limit_did_not_move(): void
    {
        app(CustomerService::class)->update($this->customer->fresh(), ['name_en' => 'Renamed Shop', 'credit_limit' => '100000.00']);

        $this->assertSame(0, $this->history()->count());
    }

    public function test_a_history_row_cannot_be_changed(): void
    {
        app(CustomerService::class)->update($this->customer->fresh(), ['credit_days' => 20]);

        $this->expectException(\RuntimeException::class);
        $this->history()->sole()->update(['new_value' => '99']);
    }

    private function history()
    {
        return CustomerCreditChange::query()->where('customer_id', $this->customer->id);
    }

    private function ask(string $limit): void
    {
        try {
            app(CustomerService::class)->update($this->customer->fresh(), ['credit_limit' => $limit]);
            $this->fail('সই ছাড়া সীমা বেড়ে গেল।');
        } catch (ValidationException) {
        }
    }

    private function aFlow(): void
    {
        $flow = ApprovalFlow::create(['module' => 'customer', 'action' => 'credit_limit', 'threshold_amount' => null]);

        ApprovalFlowStep::create([
            'approval_flow_id' => $flow->id, 'level' => 1,
            'approver_type' => ApprovalFlowStep::BY_USER, 'approver_id' => $this->manager->id,
        ]);
    }
}
