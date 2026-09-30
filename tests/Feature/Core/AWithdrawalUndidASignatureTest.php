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
 * প্রত্যাহার এইমাত্র-হওয়া সইকে "বাতিল" করে দিত — ৩০ সেপ্টেম্বর ২০২৬।
 *
 * ── ⛔ কী ভাঙা ছিল ─────────────────────────────────────────────────────
 * [[ApprovalEngine::cancel()]] হাতে-থাকা কপিতে "অপেক্ষমাণ" দেখে শর্ত ছাড়াই CANCELLED
 * লিখত। অনুরোধকারীর পাতা পুরনো, আর ঠিক তখন অনুমোদনকারী সই দিলেন: সইয়ের পরের কাজ
 * (পোস্টিং, নিশ্চিত করা) হয়ে গেছে, অথচ অনুমোদনটা "প্রত্যাহার" দেখায়।
 * approve/reject আগেই তালা দিয়ে আবার পড়ত ([[ApprovalEngine::lockPending()]]),
 * প্রত্যাহার পড়ত না।
 *
 * ── ⭐ কীভাবে মাপা ──────────────────────────────────────────────────────
 * পুরনো কপি হাতে রেখে মাঝখানে সই — তারপর সেই কপিতে প্রত্যাহার। ফেরত আসতে হবে,
 * আর সইটা অক্ষত থাকতে হবে।
 */
final class AWithdrawalUndidASignatureTest extends TestCase
{
    use RefreshDatabase;

    private ApprovalEngine $engine;

    private User $salesman;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->engine = app(ApprovalEngine::class);

        $company = Company::create(['code' => 'WD', 'name_en' => 'Withdraw Co']);
        CompanyContext::set($company->id);

        $this->salesman = User::create(['name' => 'Salesman', 'email' => 'ws@t.test', 'password' => 'x']);
        $this->manager = User::create(['name' => 'Manager', 'email' => 'wm@t.test', 'password' => 'x']);

        $flow = ApprovalFlow::create(['module' => 'sales', 'action' => 'discount']);
        ApprovalFlowStep::create([
            'approval_flow_id' => $flow->id,
            'level' => 1,
            'approver_type' => ApprovalFlowStep::BY_USER,
            'approver_id' => $this->manager->id,
        ]);
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    public function test_withdrawing_a_stale_copy_does_not_undo_a_signature(): void
    {
        $document = Branch::create(['code' => 'WD1', 'name_en' => 'Doc']);
        $stale = $this->engine->request($document, 'sales', 'discount', '500', userId: $this->salesman->id);

        // ⓘ অন্য পর্দায় অনুমোদনকারী সই দিলেন — অনুরোধকারীর হাতে এখনো পুরনো কপি
        $this->engine->approve(Approval::query()->findOrFail($stale->id), $this->manager);

        $refused = null;

        try {
            $this->engine->cancel($stale, $this->salesman);
        } catch (RuntimeException $e) {
            $refused = $e;
        }

        $this->assertNotNull($refused, '⛔ সই-হয়ে-যাওয়া অনুমোদন পুরনো কপি দিয়ে প্রত্যাহার হয়ে গেল।');
        $this->assertStringContainsString('already', $refused->getMessage());

        $this->assertSame(Approval::APPROVED, $stale->fresh()->status, '⛔ সইটা "বাতিল" হয়ে গেছে।');
    }

    public function test_an_ordinary_withdrawal_still_works(): void
    {
        $document = Branch::create(['code' => 'WD2', 'name_en' => 'Doc']);
        $approval = $this->engine->request($document, 'sales', 'discount', '500', userId: $this->salesman->id);

        $this->assertSame(Approval::CANCELLED, $this->engine->cancel($approval, $this->salesman)->status);
    }

    public function test_asking_twice_for_the_same_paper_gives_one_request(): void
    {
        $document = Branch::create(['code' => 'WD3', 'name_en' => 'Doc']);

        $first = $this->engine->request($document, 'sales', 'discount', '500', userId: $this->salesman->id);
        $second = $this->engine->request($document, 'sales', 'discount', '500', userId: $this->salesman->id);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Approval::query()->where('approvable_id', $document->id)->count());
    }
}
