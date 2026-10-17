<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Documents;

use App\Core\Engines\Approval\SignedButNotPosted;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Modules\Documents\Services\DocumentSignatures;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * ⭐ সই-হওয়া দলিল "সই হয়েছে, পোস্ট হয়নি" তালিকায় ওঠে না — দলিলের পোস্ট বলে কিছু নেই (হিসাবের নিরীক্ষা, ১৩ অক্টোবর ২০২৬)।
 *
 * ⛔ আগে: খসড়া দলিলে সই পুরো হলে সইটা APPROVED, দলিল খসড়া — তালিকার সংজ্ঞায় হুবহু মিলত, তাই দলিলটা টপবারের সংখ্যায় আর
 * তালিকায় চিরকাল থাকত, অথচ কারো কিছু করার নেই। ⓘ এখন দলিল নিজে বলে সইয়ের পরে তার পোস্ট নেই ([[NothingToPostAfterSigning]])।
 */
final class ASignedDocumentIsNotAPaperWaitingToBePostedTest extends TestCase
{
    use MakesDocuments;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDocuments();
    }

    public function test_a_fully_signed_draft_document_is_not_listed(): void
    {
        $this->useCompany();
        $flow = ApprovalFlow::query()->create(['company_id' => $this->company->id, 'code' => 'DOCSIGN', 'module' => 'documents',
            'action' => DocumentSignatures::ACTION, 'threshold_amount' => null, 'is_active' => true]);
        $signer = $this->person('lone-signer@abos.test', ['documents.view', 'approval.decide']);
        $this->useCompany();
        ApprovalFlowStep::query()->create(['approval_flow_id' => $flow->id, 'level' => 1, 'step_name' => 'one',
            'approver_type' => ApprovalFlowStep::BY_USER, 'approver_id' => $signer->id]);

        $document = $this->upload('Signed lease');
        $this->actingAs($this->owner)->post(route('documents.signature.request', $document))->assertSessionHasNoErrors();
        $this->useCompany();

        $approval = Approval::query()->where('action', DocumentSignatures::ACTION)->where('approvable_id', $document->id)->firstOrFail();
        $this->actingAs($signer)->post(route('approval.inbox.approve', $approval->id))->assertRedirect();
        $this->useCompany();

        $this->assertSame(Approval::APPROVED, $approval->fresh()->status, 'প্রস্তুতি — সই পুরো হওয়ার কথা');
        $this->assertSame(DocumentSignatures::SIGNED, app(DocumentSignatures::class)->state($document->fresh()));

        Cache::flush();
        $this->assertArrayNotHasKey((int) $approval->id, app(SignedButNotPosted::class)->papers($this->owner->fresh()),
            '⛔ সই-হওয়া দলিল "সই হয়েছে, পোস্ট হয়নি" তালিকায় — দলিলের পোস্ট নেই, তাই চিরকাল থেকে যেত।');
    }
}
