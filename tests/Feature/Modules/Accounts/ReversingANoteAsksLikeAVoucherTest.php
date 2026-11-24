<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Note;
use App\Modules\Accounts\Models\NoteLine;
use App\Modules\Accounts\Models\Reversal;
use App\Modules\Accounts\Services\AccountsSignature;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Models\PurchaseReturn;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesReturn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\WritesNotesAgainstBills;
use Tests\TestCase;

/**
 * ⭐ নোট উল্টাতে অনুমোদন, SV/PUV-এর একই ইঞ্জিনে (২৫ নভেম্বর ২০২৬); আর নোটের ফেরতের কাগজ অন্য শাখা থেকেও বাতিল হয়, অন্য কোম্পানির
 * কাগজ কখনো নয় ([[ReturnsGoodsForNotes::noteCancelReturn()]])।
 */
final class ReversingANoteAsksLikeAVoucherTest extends TestCase
{
    use RefreshDatabase;
    use WritesNotesAgainstBills;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTheNoteScene();
    }

    public function test_a_posted_note_is_reversed_only_after_its_flow_says_yes_and_a_no_leaves_it(): void
    {
        $bill = $this->purchaseBill('10', '100');
        $note = $this->postNote($this->draftNote(Note::TYPE_DN, (int) $this->supplier->id, [
            ['type' => NoteLine::PRODUCT, 'source_line_id' => $bill->lines->first()->id, 'qty' => '2', 'rate' => '10'],
        ], ['against_no' => $bill->document_no]));

        // ⓘ ছকটা নোট পোস্টের পরে বসে — পোস্টের অনুমোদন নেই, উল্টানোর লাগে
        $signer = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id]);
        $signer->companies()->attach($this->company->id, ['is_active' => true]);
        $flow = ApprovalFlow::query()->create(['company_id' => $this->company->id, 'module' => AccountsSignature::MODULE,
            'action' => AccountsSignature::NOTE, 'document_type' => '', 'code' => 'ZQ-NOTE', 'is_active' => true]);
        ApprovalFlowStep::query()->create(['approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => 'user', 'approver_id' => $signer->id]);
        $this->app->forgetInstance(ApprovalEngine::class);
        $this->app->forgetScopedInstances();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $ask = function () use ($note): Approval {
            $this->actingAs($this->owner)->post(route('accounts.note.cancel', $note), ['cancel_reason' => 'ভুল বিল'])
                ->assertSessionHasNoErrors()->assertSessionHas('saved', __('accounts::reversal.awaiting', ['no' => $note->document_no]));
            $this->assertTrue($note->fresh()->isConfirmed(), '⛔ অনুমোদনের আগেই নোট উল্টে গেল।');

            return Approval::query()->where('approvable_type', $note->getMorphClass())->where('approvable_id', $note->id)
                ->where('status', Approval::PENDING)->sole();
        };

        $first = $ask();
        $this->assertTrue(($first->payload['reverse'] ?? false) === true);
        app(ApprovalEngine::class)->reject($first, $signer->fresh(), 'নোট ঠিক আছে');
        $this->assertTrue($note->fresh()->isConfirmed(), '⛔ "না"-র পরেও নোট উল্টাল।');
        $this->assertNull(Reversal::of(Reversal::NOTE, (int) $note->id));

        $second = $ask();
        app(ApprovalEngine::class)->approve($second, $signer->fresh());
        $this->assertTrue($note->fresh()->isCancelled(), '⛔ অনুমোদনের পরেও নোট উল্টায়নি।');
        $paper = Reversal::of(Reversal::NOTE, (int) $note->id);
        $this->assertSame((int) $this->owner->id, (int) $paper->created_by, '⛔ উল্টো কাগজ যিনি চাইলেন তাঁর নামে নয়।');
    }

    public function test_the_sales_return_of_a_note_is_cancelled_from_another_branch_and_never_from_another_company(): void
    {
        $invoice = $this->salesInvoice('4', '200');
        $note = $this->postNote($this->draftNote(Note::TYPE_CN, (int) $this->customer->id, [
            ['type' => NoteLine::PRODUCT, 'source_line_id' => $invoice->lines->first()->id, 'qty' => '1', 'goods_moved' => true],
        ], ['against_no' => $invoice->document_no]))->load('lines');
        $line = $note->lines->firstWhere('goods_moved', true);

        $this->cancelsFromAfar(SalesInvoice::class, SalesReturn::class, (string) $line->return_type, (int) $line->return_id);
    }

    public function test_the_purchase_return_of_a_note_is_cancelled_from_another_branch_and_never_from_another_company(): void
    {
        $bill = $this->purchaseBill('10', '100');
        $note = $this->postNote($this->draftNote(Note::TYPE_DN, (int) $this->supplier->id, [
            ['type' => NoteLine::PRODUCT, 'source_line_id' => $bill->lines->first()->id, 'qty' => '2', 'goods_moved' => true],
        ], ['against_no' => $bill->document_no]))->load('lines');
        $line = $note->lines->firstWhere('goods_moved', true);

        $this->cancelsFromAfar(PurchaseBill::class, PurchaseReturn::class, (string) $line->return_type, (int) $line->return_id);
    }

    /**
     * @param  class-string<\App\Core\Contracts\ReturnsGoodsForNotes>  $bill
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $return
     */
    private function cancelsFromAfar(string $bill, string $return, string $type, int $id): void
    {
        $status = fn (): string => (string) $return::query()->withoutGlobalScopes()->findOrFail($id)->status;
        $this->assertNotSame(DocumentStatus::CANCELLED, $status(), 'দৃশ্যটাই ভুল — ফেরত আগেই বাতিল।');

        $other = Company::query()->whereKeyNot($this->company->id)->firstOrFail();
        CompanyContext::forCompany($other->id, fn () => $bill::noteCancelReturn($type, $id, 'x'));
        $this->assertNotSame(DocumentStatus::CANCELLED, $status(), '⛔ অন্য কোম্পানি থেকে ফেরত বাতিল হলো।');

        $far = Branch::query()->where('company_id', $this->company->id)->whereKeyNot($this->company->defaultBranch()?->id)->first()
            ?? Branch::query()->create(['company_id' => $this->company->id, 'code' => 'NFAR', 'name_en' => 'Far Branch', 'is_active' => true]);
        CompanyContext::set($this->company->id, $far->id);
        $bill::noteCancelReturn($type, $id, 'নোট উল্টানো');
        $this->assertSame(DocumentStatus::CANCELLED, $status(), '⛔ অন্য শাখা বাছা থাকলে নোটের ফেরত বাতিল হয় না।');
    }
}
