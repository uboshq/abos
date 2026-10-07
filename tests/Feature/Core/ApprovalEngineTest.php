<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * অনুমোদন — বহু-স্তর, সীমা, আর সবচেয়ে জরুরি নিয়ম: নিজের অনুরোধ নিজে
 * অনুমোদন করা যায় না।
 *
 * Branch-কে অনুমোদনযোগ্য ডকুমেন্ট হিসেবে ব্যবহার করা হয়েছে, কারণ ইঞ্জিন
 * polymorphic — কোন ডকুমেন্ট সেটা তার জানার কথা নয়, আর সেটাই এখানে প্রমাণ।
 */
class ApprovalEngineTest extends TestCase
{
    use RefreshDatabase;

    private ApprovalEngine $engine;

    private Company $company;

    private User $salesman;

    private User $manager;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->engine = app(ApprovalEngine::class);

        $this->company = Company::create(['code' => 'AP', 'name_en' => 'Approval Co']);
        CompanyContext::set($this->company->id);

        $this->salesman = User::create(['name' => 'Salesman', 'email' => 's@t.test', 'password' => 'x']);
        $this->manager = User::create(['name' => 'Manager', 'email' => 'm@t.test', 'password' => 'x']);
        $this->owner = User::create(['name' => 'Owner', 'email' => 'o@t.test', 'password' => 'x']);
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    private function flow(array $approvers, ?string $threshold = null, string $action = 'discount'): ApprovalFlow
    {
        $flow = ApprovalFlow::create([
            'module' => 'sales',
            'action' => $action,
            'threshold_amount' => $threshold,
        ]);

        foreach ($approvers as $level => $user) {
            ApprovalFlowStep::create([
                'approval_flow_id' => $flow->id,
                'level' => $level,
                'approver_type' => ApprovalFlowStep::BY_USER,
                'approver_id' => $user->id,
            ]);
        }

        return $flow;
    }

    private function document(): Branch
    {
        return Branch::create(['code' => 'D'.uniqid(), 'name_en' => 'Doc']);
    }

    public function test_no_flow_means_no_approval_is_needed(): void
    {
        $result = $this->engine->request($this->document(), 'sales', 'discount', '500', userId: $this->salesman->id);

        // null মানে "এগিয়ে যাও" — কোম্পানি এই কাজে অনুমোদন চায় না।
        $this->assertNull($result);
    }

    public function test_a_request_is_created_when_a_flow_exists(): void
    {
        $this->flow([1 => $this->manager]);

        $approval = $this->engine->request($this->document(), 'sales', 'discount', '500', userId: $this->salesman->id);

        $this->assertNotNull($approval);
        $this->assertSame(Approval::PENDING, $approval->status);
        $this->assertSame(1, $approval->current_level);
    }

    public function test_amounts_below_the_threshold_skip_approval_entirely(): void
    {
        $this->flow([1 => $this->manager], threshold: '1000');

        $this->assertNull(
            $this->engine->request($this->document(), 'sales', 'discount', '999', userId: $this->salesman->id),
            'A small discount should not need the owner — nobody follows a rule like that.',
        );

        $this->assertNotNull(
            $this->engine->request($this->document(), 'sales', 'discount', '1000', userId: $this->salesman->id),
        );
    }

    public function test_a_person_cannot_approve_their_own_request(): void
    {
        $this->flow([1 => $this->salesman]);

        $approval = $this->engine->request($this->document(), 'sales', 'discount', '5000', userId: $this->salesman->id);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/cannot decide/');

        // এটা না থাকলে পুরো ব্যবস্থাটাই সাজানো — যে ছাড় চায় সে নিজেই দেয়।
        $this->engine->approve($approval, $this->salesman);
    }

    public function test_two_levels_must_both_agree(): void
    {
        $this->flow([1 => $this->manager, 2 => $this->owner]);

        $approval = $this->engine->request($this->document(), 'sales', 'discount', '5000', userId: $this->salesman->id);

        $approval = $this->engine->approve($approval, $this->manager, 'ঠিক আছে');

        $this->assertSame(Approval::PENDING, $approval->status);
        $this->assertSame(2, $approval->current_level, 'It should have moved up to the owner.');

        $approval = $this->engine->approve($approval, $this->owner, 'অনুমোদিত');

        $this->assertSame(Approval::APPROVED, $approval->status);
        $this->assertNotNull($approval->decided_at);
        $this->assertCount(2, $approval->decisions);
    }

    public function test_one_rejection_ends_it(): void
    {
        $this->flow([1 => $this->manager, 2 => $this->owner]);

        $approval = $this->engine->request($this->document(), 'sales', 'discount', '5000', userId: $this->salesman->id);
        $approval = $this->engine->reject($approval, $this->manager, 'ছাড় বেশি হয়ে যাচ্ছে');

        $this->assertSame(Approval::REJECTED, $approval->status);

        // উপরের স্তরে পাঠানো হয়নি — নিচের স্তর না চাইলে উপরের মতামতের অর্থ নেই।
        $this->expectException(RuntimeException::class);
        $this->engine->approve($approval, $this->owner);
    }

    public function test_asking_twice_for_the_same_thing_returns_the_same_request(): void
    {
        $this->flow([1 => $this->manager]);
        $document = $this->document();

        $first = $this->engine->request($document, 'sales', 'discount', '5000', userId: $this->salesman->id);
        $second = $this->engine->request($document, 'sales', 'discount', '5000', userId: $this->salesman->id);

        // দুইটা অনুরোধ থাকলে অনুমোদনকারী একই জিনিস দুইবার দেখত।
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Approval::query()->count());
    }

    public function test_the_queue_only_shows_what_this_person_can_decide(): void
    {
        $this->flow([1 => $this->manager, 2 => $this->owner]);

        $this->engine->request($this->document(), 'sales', 'discount', '5000', userId: $this->salesman->id);

        $this->assertCount(1, $this->engine->pendingFor($this->manager));
        $this->assertCount(0, $this->engine->pendingFor($this->owner), 'The owner should not see it until level 2.');
        $this->assertCount(0, $this->engine->pendingFor($this->salesman));
    }

    public function test_a_flow_with_no_steps_is_refused_rather_than_leaving_documents_stuck(): void
    {
        ApprovalFlow::create(['module' => 'sales', 'action' => 'discount']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/has no steps/');

        $this->engine->request($this->document(), 'sales', 'discount', '5000', userId: $this->salesman->id);
    }

    public function test_the_requester_can_withdraw_but_nobody_else_can(): void
    {
        $this->flow([1 => $this->manager]);

        $approval = $this->engine->request($this->document(), 'sales', 'discount', '5000', userId: $this->salesman->id);

        try {
            $this->engine->cancel($approval, $this->manager);
            $this->fail('Only the requester may withdraw.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('who asked for an approval', $e->getMessage());
        }

        $approval = $this->engine->cancel($approval, $this->salesman);
        $this->assertSame(Approval::CANCELLED, $approval->status);
    }

    public function test_a_decided_request_cannot_be_decided_again(): void
    {
        $this->flow([1 => $this->manager]);

        $approval = $this->engine->request($this->document(), 'sales', 'discount', '5000', userId: $this->salesman->id);
        $approval = $this->engine->approve($approval, $this->manager);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/already approved/');

        $this->engine->approve($approval, $this->manager);
    }

    public function test_the_payload_carries_what_was_asked_for(): void
    {
        $this->flow([1 => $this->manager]);

        $approval = $this->engine->request(
            $this->document(),
            'sales',
            'discount',
            '5000',
            payload: ['discount_percent' => 12.5, 'reason_code' => 'BULK'],
            userId: $this->salesman->id,
        );

        // অনুমোদনের আগে পরিবর্তনটা প্রয়োগ হয় না, তাই প্রস্তাবটা কোথাও রাখতে হয়।
        $this->assertSame(12.5, $approval->payload['discount_percent']);
        $this->assertSame('BULK', $approval->payload['reason_code']);
    }
}
