<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Documents;

use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\AuditTrail;
use App\Models\Notification;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Services\DocumentNotices;
use App\Modules\Documents\Services\DocumentSignatures;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ চলমান সই-অনুরোধ আবার চাওয়া যায় না — Documents লাইন-ধরে নিরীক্ষা, ১৪ অক্টোবর ২০২৬ ([[DocumentSignatures::request()]])।
 *
 * ⓘ কেবল "সই হয়ে গেছে" থামাত। অপেক্ষার মাঝে প্রতিটা চাপে আরেকটা "সই চাওয়া" অডিট সারি আর সব সইকারীর কাছে আবার খবর যেত।
 */
final class AnOpenSignatureRequestIsNotAskedTwiceTest extends TestCase
{
    use MakesDocuments;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDocuments();
    }

    public function test_a_second_ask_while_signatures_are_awaited_is_refused_and_tells_nobody_again(): void
    {
        $signer = $this->person('signer@abos.test', ['documents.view', 'approval.decide']);
        $this->useCompany();
        $flow = ApprovalFlow::query()->create(['company_id' => $this->company->id, 'code' => 'DOCSIGN', 'module' => 'documents',
            'action' => DocumentSignatures::ACTION, 'threshold_amount' => null, 'is_active' => true]);
        ApprovalFlowStep::query()->create(['approval_flow_id' => $flow->id, 'level' => 1, 'step_name' => 'level 1',
            'approver_type' => ApprovalFlowStep::BY_USER, 'approver_id' => $signer->id]);

        $document = $this->upload('Board resolution');

        $this->actingAs($this->owner)->post(route('documents.signature.request', $document))->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->post(route('documents.signature.request', $document))
            ->assertSessionHasErrors(['signature' => __('documents::message.signature_already_asked')]);
        $this->useCompany();

        $this->assertSame(1, Notification::query()->withoutGlobalScopes()->where('user_id', $signer->id)
            ->where('type', DocumentNotices::SIGNATURE_REQUIRED)->count(), '⛔ সইকারী একই অনুরোধের খবর দুইবার পেলেন');
        $this->assertSame(1, AuditTrail::query()->forRecord(Document::class, $document->id)->where('action', 'signature_requested')->count());
    }
}
