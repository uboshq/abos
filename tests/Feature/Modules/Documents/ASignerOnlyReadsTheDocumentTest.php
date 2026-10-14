<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Documents;

use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Services\DocumentSignatures;
use App\Modules\Documents\Support\DocumentCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ সইকারী গোপন কাগজটা পড়েন, কিন্তু তাতে আর কিছু করতে পারেন না — Documents লাইন-ধরে নিরীক্ষা, ১৪ অক্টোবর ২০২৬ ([[DocumentPolicy::reaches()]])।
 *
 * ⓘ সই চাওয়া হলে সইকারী শাখা আর গোপনীয়তার দেয়াল পেরিয়ে কাগজ পড়েন। কিন্তু বদল, শেয়ার, অধিকার, মোছা সবই "দেখা"-র উপর দাঁড়িয়ে ছিল —
 * তাই অন্য শাখার, গোপন ধাপের চাবিহীন সইকারী সই চলার মাঝে কাগজটা নিজেকে শেয়ার দিতে বা বিনে ফেলতে পারতেন।
 */
final class ASignerOnlyReadsTheDocumentTest extends TestCase
{
    use MakesDocuments;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDocuments();
    }

    public function test_a_signer_beyond_the_walls_reads_but_cannot_share_or_bin_the_paper(): void
    {
        // ⓘ নেত্রকোনার সইকারী — গোপন ধাপের চাবি নেই, কিন্তু শেয়ার আর মোছার চাবি আছে
        $signer = $this->person('far-signer@abos.test', ['documents.view', 'approval.decide', 'documents.share', 'documents.delete'], $this->netrakona);
        $this->useCompany();
        $flow = ApprovalFlow::query()->create(['company_id' => $this->company->id, 'code' => 'DOCSIGN', 'module' => 'documents',
            'action' => DocumentSignatures::ACTION, 'threshold_amount' => null, 'is_active' => true]);
        ApprovalFlowStep::query()->create(['approval_flow_id' => $flow->id, 'level' => 1, 'step_name' => 'level 1',
            'approver_type' => ApprovalFlowStep::BY_USER, 'approver_id' => $signer->id]);

        $document = $this->upload('Secret lease', DocumentCatalog::CONFIDENTIAL);
        $this->actingAs($this->owner)->post(route('documents.signature.request', $document))->assertSessionHasNoErrors();

        // ⓘ পড়েন — সইয়ের নিয়ম
        $this->actingAs($signer)->get(route('documents.show', $document))->assertOk();

        // ⛔ কিন্তু আর কিছু নয়
        $this->actingAs($signer)->post(route('documents.share.store', $document), [])->assertForbidden();
        $this->actingAs($signer)->delete(route('documents.destroy', $document))->assertForbidden();

        $this->useCompany();
        $this->assertNotNull(Document::query()->withoutGlobalScopes()->find($document->id)?->getKey(), '⛔ সইকারী কাগজটা বিনে ফেললেন');
        $this->assertNull(Document::query()->withoutGlobalScopes()->find($document->id)->deleted_at);
    }
}
