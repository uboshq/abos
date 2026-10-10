<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Documents;

use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Support\DocumentCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ কাগজ আর্কাইভ বা বিনে গেলে তার চলমান অনুরোধ বাতিল; আর মেয়াদের রান জমা থাকা কাগজের অবস্থা বদলায় না
 * (১১ অক্টোবর ২০২৬, documents রিভিউ ⚠️১৪, ⚠️১৫)।
 */
final class APendingRequestEndsWithItsDocumentTest extends TestCase
{
    use MakesDocuments;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDocuments();
    }

    public function test_archiving_or_binning_a_submitted_paper_cancels_its_request_and_brings_it_back_as_a_draft(): void
    {
        $this->flow();

        foreach (['archive', 'delete'] as $how) {
            $document = $this->upload('Pending '.$how);
            $this->actingAs($this->owner)->post(route('documents.submit', $document))->assertSessionHasNoErrors();
            $this->useCompany();

            $approval = Approval::query()->where('approvable_id', $document->id)->where('approvable_type', Document::class)->firstOrFail();
            $this->assertSame(Approval::PENDING, $approval->status, 'ⓘ অনুরোধই ঝোলেনি — দাবিটা কিছু মাপছে না।');

            $how === 'archive'
                ? $this->actingAs($this->owner)->post(route('documents.archive', $document))->assertSessionHasNoErrors()
                : $this->actingAs($this->owner)->delete(route('documents.destroy', $document))->assertSessionHasNoErrors();
            $this->useCompany();

            $this->assertSame(Approval::CANCELLED, $approval->fresh()->status, "⛔ {$how}: অনুরোধ সইকারীর ইনবক্সে ঝুলে রইল।");

            $fresh = Document::query()->withoutGlobalScopes()->withTrashed()->findOrFail($document->id);
            $back = $how === 'archive' ? $fresh->archived_from_status : $fresh->deleted_from_status;
            $this->assertSame(DocumentCatalog::DRAFT, $back, "⛔ {$how}: ফেরালে কাগজ অনুমোদন ছাড়াই \"জমা\" অবস্থায় ঝুলত।");
        }
    }

    public function test_the_expiry_run_leaves_a_paper_under_review_as_it_is(): void
    {
        $this->flow();

        $document = $this->upload('Expiring under review', extra: ['expiry_date' => now()->toDateString()]);
        $this->actingAs($this->owner)->post(route('documents.submit', $document))->assertSessionHasNoErrors();
        $this->useCompany();
        $this->assertSame(DocumentCatalog::SUBMITTED, $document->fresh()->status);

        $this->artisan('abos:documents-expiry')->assertSuccessful();
        $this->useCompany();

        $this->assertSame(DocumentCatalog::SUBMITTED, $document->fresh()->status, '⛔ পর্যালোচনায় থাকা কাগজ "মেয়াদোত্তীর্ণ" হলো — অনুমোদন আর কাগজ দুই কথা বলবে।');
    }

    private function flow(): void
    {
        $this->useCompany();
        $flow = ApprovalFlow::query()->create(['company_id' => $this->company->id, 'code' => 'DOCS', 'module' => 'documents',
            'action' => 'document', 'threshold_amount' => null, 'is_active' => true]);
        $signer = $this->person('reviewer@abos.test', ['documents.view', 'approval.decide']);
        $this->useCompany();
        ApprovalFlowStep::query()->create(['approval_flow_id' => $flow->id, 'level' => 1, 'step_name' => 'level 1',
            'approver_type' => ApprovalFlowStep::BY_USER, 'approver_id' => $signer->id]);
    }
}
