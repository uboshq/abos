<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Services\PhoneModules;
use App\Core\Services\SettingsService;
use App\Http\Controllers\Api\AuthController;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\User;
use App\Modules\Documents\Services\DocumentSignatures;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Modules\Documents\MakesDocuments;
use Tests\TestCase;

/**
 * ⭐ দলিলের অনুমোদনের সারি আর সই কেন্দ্র ফোনের নিজের পর্দায় — মালিক, ১৭ অক্টোবর ২০২৬: *"direct webpage app e bujajayna"*
 * ([[DocumentQueueApiController]])।
 *
 * দাবি: সইকারীর "আমার সইয়ের অপেক্ষায়"-তে কাগজটা, অনুমোদনের public_id আর সিদ্ধান্তের অধিকারসহ; যিনি চেয়েছেন তাঁর "আমার পাঠানো"/
 * "আমার চাওয়া"-তে, সিদ্ধান্ত ছাড়া; অন্যজনের কিছুই নয়; ফোনের অনুমোদন-দরজায় সই দিলে সারি সরে "সইয়ের ইতিহাস"-এ ওঠে, সইকারীর নাম,
 * সংস্করণ আর ছাপসহ; চাবি ছাড়া ৪০৩।
 */
final class ThePhoneShowsTheDocumentQueueAndSignatureCentreTest extends TestCase
{
    use MakesDocuments;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDocuments();
        app(SettingsService::class)->set(PhoneModules::key('documents'), true);
    }

    public function test_the_signer_the_asker_and_a_bystander_each_see_their_own_rows_and_a_signature_moves_to_history(): void
    {
        $this->useCompany();
        $flow = ApprovalFlow::query()->create(['company_id' => $this->company->id, 'code' => 'DOCSIGN', 'module' => 'documents',
            'action' => DocumentSignatures::ACTION, 'threshold_amount' => null, 'is_active' => true]);
        $signer = $this->person('queue-signer@abos.test', ['documents.view', 'approval.decide']);
        $bystander = $this->person('queue-bystander@abos.test', ['documents.view', 'approval.decide']);
        $this->useCompany();
        ApprovalFlowStep::query()->create(['approval_flow_id' => $flow->id, 'level' => 1, 'step_name' => 'level 1',
            'approver_type' => ApprovalFlowStep::BY_USER, 'approver_id' => $signer->id, 'requires_all' => false]);

        $document = $this->upload('Distributor agreement');
        $this->actingAs($this->owner)->post(route('documents.signature.request', $document))->assertSessionHasNoErrors();
        $this->useCompany();
        $approval = Approval::query()->where('action', DocumentSignatures::ACTION)->where('approvable_id', $document->id)->firstOrFail();

        foreach (['/api/v1/documents/approvals', '/api/v1/documents/signatures?tab=to_sign'] as $url) {
            $rows = $this->phone($signer, $url)->assertOk()->json('rows');
            $this->assertSame([(string) $approval->public_id], array_column($rows, 'id'), "⛔ {$url}: সইকারীর সারিতে কাগজটা নেই।");
            $this->assertSame('Distributor agreement', $rows[0]['name']);
            $this->assertSame($document->public_id, $rows[0]['document_id']);
            $this->assertTrue($rows[0]['can_decide']);

            $this->assertSame([], $this->phone($bystander, $url)->assertOk()->json('rows'), "⛔ {$url}: যিনি সইকারী নন তাঁর সারিতে কাগজ।");
        }

        foreach (['/api/v1/documents/approvals?mine=1', '/api/v1/documents/signatures?tab=asked'] as $url) {
            $rows = $this->phone($this->owner, $url)->assertOk()->json('rows');
            $this->assertSame([(string) $approval->public_id], array_column($rows, 'id'), "⛔ {$url}: যিনি চেয়েছেন তাঁর সারিতে নেই।");
            $this->assertFalse($rows[0]['can_decide'], "⛔ {$url}: নিজের চাওয়া কাগজে সিদ্ধান্তের বোতাম।");
        }

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($signer->fresh(), [AuthController::APP]);
        $this->postJson('/api/v1/approvals/'.$approval->public_id.'/approve')->assertSuccessful();
        $this->useCompany();

        $this->assertSame([], $this->phone($signer, '/api/v1/documents/signatures?tab=to_sign')->assertOk()->json('rows'),
            '⛔ সই দেওয়ার পরও কাগজ সইয়ের অপেক্ষায়।');
        $history = $this->phone($this->owner, '/api/v1/documents/signatures?tab=signed')->assertOk();
        $this->assertSame(1, $history->json('count'));
        $this->assertSame($signer->name, $history->json('rows.0.signer'), '⛔ সইয়ের ইতিহাসে সইকারীর নাম নেই।');
        $this->assertSame($document->public_id, $history->json('rows.0.document_id'));
        $this->assertSame(12, strlen((string) $history->json('rows.0.hash')), '⛔ সংস্করণের ছাপ নেই।');
        $this->assertStringStartsWith('v', (string) $history->json('rows.0.version'));
    }

    public function test_without_the_documents_key_both_doors_refuse(): void
    {
        $nobody = $this->person('queue-nobody@abos.test', ['approval.decide']);
        $this->useCompany();

        $this->phone($nobody, '/api/v1/documents/approvals')->assertForbidden();
        $this->phone($nobody, '/api/v1/documents/signatures')->assertForbidden();
    }

    private function phone(User $user, string $url): TestResponse
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user->fresh(), [AuthController::APP]);

        $response = $this->getJson($url);
        $this->useCompany();

        return $response;
    }
}
