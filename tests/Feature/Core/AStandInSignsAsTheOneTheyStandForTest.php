<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Engines\Approval\DelegationService;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\ApprovalLimit;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ⛔ ভারপ্রাপ্ত সই দেন যাঁর হয়ে, তাঁরই নিয়মে — Approval লাইন-ধরে নিরীক্ষা, ১৪ অক্টোবর ২০২৬ ([[ApprovalEngine::canDecide()]])।
 *
 * ⓘ তিনটা ফাঁক, একই শিকড় — ভারপ্রাপ্তকে আলাদা মানুষ ধরা:
 *  · "দুজনেরই লাগবে" স্তরে A সই দিয়ে C-কে ভার দিলে C-র সই দ্বিতীয় মানুষ — B ছাড়াই পাশ (উল্টো ক্রমেও)।
 *  · ভারপ্রাপ্ত নিজের সীমায়: সীমাহীন C এক লাখের A-র হয়ে পাঁচ লাখে সই।
 *  · অনুরোধকারী R নিজেই স্তরের একমাত্র সইকারী — বন্ধু C-কে ভার দিয়ে নিজের কাগজ পাশ।
 */
final class AStandInSignsAsTheOneTheyStandForTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $clerk;

    private User $boss;

    private User $second;

    private User $deputy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['code' => 'SAS', 'name_en' => 'Stand As Co']);
        CompanyContext::set($this->company->id);

        foreach (['clerk', 'boss', 'second', 'deputy'] as $who) {
            $this->{$who} = User::create(['name' => ucfirst($who), 'email' => $who.'@sas.test', 'password' => 'x']);
            $this->{$who}->companies()->attach($this->company->id);
        }
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    public function test_a_stand_in_and_the_one_they_stand_for_count_as_one_signature_either_way_round(): void
    {
        $this->flow([$this->boss, $this->second], minApprovals: 2);
        $this->delegate($this->boss, $this->deputy);

        // ⓘ boss নিজে, তারপর তাঁর ভারপ্রাপ্ত
        $first = $this->ask();
        $this->engine()->approve($first, $this->boss);
        $this->assertFalse($this->engine()->canDecide($first->fresh(), $this->deputy), '⛔ boss-এর সইয়ের পরে তাঁর ভারপ্রাপ্ত আবার সই দিতে পারছেন');
        $this->assertSame(Approval::PENDING, $first->fresh()->status);

        // ⓘ উল্টো ক্রম — ভারপ্রাপ্ত আগে, তারপর boss নিজে
        $other = $this->ask();
        $this->engine()->approve($other, $this->deputy);
        $this->assertRefused(fn () => $this->engine()->approve($other->fresh(), $this->boss), '⛔ ভারপ্রাপ্তের পরে boss নিজে আবার সই দিলেন');
        $this->assertSame(Approval::PENDING, $other->fresh()->status, '⛔ দুজনের স্তর একজনের দুই হাতে পাশ');

        // ⓘ দ্বিতীয় মানুষ সই দিলে তবেই
        $this->engine()->approve($other->fresh(), $this->second);
        $this->assertSame(Approval::APPROVED, $other->fresh()->status);
    }

    public function test_a_stand_in_signs_within_the_limit_of_the_one_they_stand_for(): void
    {
        $this->flow([$this->boss]);
        $role = Role::query()->create(['name' => 'Small Signer', 'guard_name' => 'web', 'company_id' => $this->company->id]);
        CompanyContext::forCompany($this->company->id, fn () => $this->boss->assignRole($role));
        ApprovalLimit::query()->create(['company_id' => $this->company->id, 'role_id' => $role->id, 'max_amount' => '10000']);
        $this->delegate($this->boss, $this->deputy);

        $approval = $this->ask();   // ৫০,০০০

        $this->assertFalse($this->engine()->canDecide($approval, $this->boss->fresh()), 'দৃশ্যটাই বানানো যায়নি — boss নিজেই ৫০ হাজারে সই দিতে পারছেন');
        $this->assertFalse($this->engine()->canDecide($approval, $this->deputy), '⛔ ভারপ্রাপ্ত boss-এর ১০ হাজারের সীমা পেরিয়ে ৫০ হাজারে সই দিতে পারছেন');
    }

    public function test_a_requester_cannot_pass_their_own_paper_through_a_stand_in(): void
    {
        $this->flow([$this->clerk]);
        $this->delegate($this->clerk, $this->deputy);

        $approval = $this->ask();   // অনুরোধকারী clerk

        $this->assertFalse($this->engine()->canDecide($approval, $this->deputy), '⛔ অনুরোধকারীর ভারপ্রাপ্ত তাঁর নিজের কাগজ পাশ করাতে পারছেন');
        $this->assertRefused(fn () => $this->engine()->approve($approval, $this->deputy), '⛔ অনুরোধকারীর কাগজ তাঁর ভারপ্রাপ্তের হাতে পাশ');
    }

    /** @param  list<User>  $signers  সবাই স্তর ১-এ */
    private function flow(array $signers, int $minApprovals = 1): void
    {
        $flow = ApprovalFlow::create(['module' => 'sales', 'action' => 'discount']);

        foreach ($signers as $signer) {
            ApprovalFlowStep::create(['approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => ApprovalFlowStep::BY_USER,
                'approver_id' => $signer->id, 'min_approvals' => $minApprovals]);
        }
    }

    private function delegate(User $from, User $to): void
    {
        app(DelegationService::class)->grant(from: $from, to: $to, startsOn: now()->toDateString(), endsOn: now()->addDays(3)->toDateString());
    }

    private function ask(): Approval
    {
        $approval = $this->engine()->request(Branch::create(['code' => 'S'.uniqid(), 'name_en' => 'Doc']),
            'sales', 'discount', '50000', null, 'test', $this->clerk->id);
        $this->assertNotNull($approval, 'অনুরোধটাই বসেনি');

        return $approval;
    }

    private function assertRefused(callable $act, string $message): void
    {
        $refused = false;

        try {
            $act();
        } catch (RuntimeException) {
            $refused = true;
        }

        $this->assertTrue($refused, $message);
    }

    private function engine(): ApprovalEngine
    {
        return app()->make(ApprovalEngine::class);
    }
}
