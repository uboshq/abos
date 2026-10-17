<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Documents;

use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Services\DocumentLibrary;
use App\Modules\Documents\Services\DocumentSignatures;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⛔ সই চাওয়ার পরে নতুন ভার্সন ঢোকে না, পলিসি যখনই দেখা হয়ে থাকুক — Documents লাইন-ধরে নিরীক্ষা, ১৪ অক্টোবর ২০২৬
 * ([[DocumentLibrary::assertTakesAVersion()]])।
 *
 * ⓘ "সইয়ের মাঝে নতুন ভার্সন নয়" কেবল পলিসিতে, তালার আগে দেখা হত। একই মুহূর্তে সই চাওয়া আগে বসলে, আপলোডটা তালা পেয়ে নতুন বাইট
 * বসিয়ে দিত — সইকারী যা দেখেননি তাতে সই। এখানে সেই মুহূর্তটা সেবাকে সরাসরি ডেকে দেখানো (পলিসি আগেই পেরিয়েছে ধরে)।
 */
final class AVersionCannotSlipInAfterTheRequestTest extends TestCase
{
    use MakesDocuments;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDocuments();
    }

    public function test_a_version_is_refused_inside_the_lock_once_signatures_are_awaited(): void
    {
        $signer = $this->person('signer@abos.test', ['documents.view', 'approval.decide']);
        $this->useCompany();
        $flow = ApprovalFlow::query()->create(['company_id' => $this->company->id, 'code' => 'DOCSIGN', 'module' => 'documents',
            'action' => DocumentSignatures::ACTION, 'threshold_amount' => null, 'is_active' => true]);
        ApprovalFlowStep::query()->create(['approval_flow_id' => $flow->id, 'level' => 1, 'step_name' => 'level 1',
            'approver_type' => ApprovalFlowStep::BY_USER, 'approver_id' => $signer->id]);

        $document = $this->upload('Lease deed');
        $this->actingAs($this->owner);
        $this->useCompany();

        // ⓘ সইয়ের আগে — নতুন ভার্সন চলে
        app(DocumentLibrary::class)->addVersion(Document::query()->findOrFail($document->id), $this->pdf('lease.pdf', 'second'), false, null);
        $before = (int) Document::query()->findOrFail($document->id)->current_version_id;

        $this->post(route('documents.signature.request', $document))->assertSessionHasNoErrors();
        $this->useCompany();

        try {
            app(DocumentLibrary::class)->addVersion(Document::query()->findOrFail($document->id), $this->pdf('lease.pdf', 'slipped-in'), false, null);
            $this->fail('⛔ সই চলার মাঝে নতুন ভার্সন বসল');
        } catch (ValidationException $e) {
            $this->assertSame(__('documents::message.version_closed'), $e->errors()['file'][0] ?? null);
        }

        $this->assertSame($before, (int) Document::query()->findOrFail($document->id)->current_version_id, '⛔ সইয়ের কাগজের ফাইল বদলে গেল');
    }
}
