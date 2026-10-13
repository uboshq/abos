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
use App\Modules\Documents\Models\DocumentSignature;
use App\Modules\Documents\Services\DocumentSignatures;
use App\Modules\Documents\Support\DocumentCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Modules\Documents\MakesDocuments;
use Tests\TestCase;

/**
 * ⭐ ফোনে ডকুমেন্টস — তালিকা, একটা কাগজ, তার ফাইল, আর সই (মালিকের আদেশ, ১৩ অক্টোবর ২০২৬; [[DocumentLibraryApiController]])।
 *
 * দাবি:
 *  - তালিকা আর কাগজ ওয়েবের তিন দেয়ালে: গোপন কাগজ গোপনের চাবি ছাড়া তালিকায় নেই, ঠিকানায় ৪০৪; সংখ্যার আইডি ৪০৪।
 *  - ফাইল ওয়েবের পথে: PDF পাতার ভিতরে দেখা যায়; নামানো নামানোর অধিকার চায় (৪০৩)।
 *  - সই: সইকারী কাগজে অনুমোদনের public_id পান, আর ফোনের অনুমোদনের দরজায় সই দিলে সইয়ের সারি বসে; যিনি সইকারী নন তিনি পান না।
 *  - চাবি ছাড়া ৪০৩; ফোনে ডকুমেন্টস বন্ধ ৪০৩ `module_off`।
 */
final class ThePhoneOpensAndSignsDocumentsTest extends TestCase
{
    use MakesDocuments;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDocuments();
        app(SettingsService::class)->set(PhoneModules::key('documents'), true);
    }

    public function test_the_list_and_the_paper_keep_the_webs_walls(): void
    {
        $open = $this->upload('Trade licence');
        $secret = $this->upload('Board minutes', DocumentCatalog::CONFIDENTIAL);
        $reader = $this->person('reader@abos.test', ['documents.view']);
        $this->useCompany();

        $mine = array_column($this->phone($this->owner, '/api/v1/documents/library')->assertOk()->json('rows'), 'id');
        $this->assertContains($secret->public_id, $mine, 'দাবির ভিত্তি নেই — মালিক গোপন কাগজ দেখেন না।');

        $theirs = array_column($this->phone($reader, '/api/v1/documents/library')->assertOk()->json('rows'), 'id');
        $this->assertContains($open->public_id, $theirs);
        $this->assertNotContains($secret->public_id, $theirs, '⛔ গোপনের চাবি ছাড়া গোপন কাগজ ফোনের তালিকায়।');

        $this->phone($reader, '/api/v1/documents/library/'.$open->public_id)->assertOk()->assertJsonPath('name', 'Trade licence');
        $this->phone($reader, '/api/v1/documents/library/'.$secret->public_id)->assertNotFound();
        $this->phone($this->owner, '/api/v1/documents/library/'.$open->id)->assertNotFound();
    }

    public function test_a_pdf_opens_in_place_and_a_download_needs_its_own_right(): void
    {
        $document = $this->upload('Price circular');
        $reader = $this->person('viewer@abos.test', ['documents.view']);
        $this->useCompany();

        $show = $this->phone($reader, '/api/v1/documents/library/'.$document->public_id)->assertOk()->json('file');
        $this->assertTrue($show['inline']);
        $this->assertFalse($show['download']);

        $preview = $this->phone($reader, '/api/v1/documents/library/'.$document->public_id.'/file?how=preview')->assertOk();
        $this->assertStringStartsWith('application/pdf', (string) $preview->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $preview->streamedContent());

        $this->phone($reader, '/api/v1/documents/library/'.$document->public_id.'/file?how=download')->assertForbidden();
        $this->phone($this->owner, '/api/v1/documents/library/'.$document->public_id.'/file?how=download')->assertOk();
    }

    public function test_the_signer_gets_the_approval_and_signs_through_the_phones_approval_door(): void
    {
        $this->useCompany();
        $flow = ApprovalFlow::query()->create(['company_id' => $this->company->id, 'code' => 'DOCSIGN', 'module' => 'documents',
            'action' => DocumentSignatures::ACTION, 'threshold_amount' => null, 'is_active' => true]);
        $signer = $this->person('phone-signer@abos.test', ['documents.view', 'approval.decide']);
        $bystander = $this->person('bystander@abos.test', ['documents.view', 'approval.decide']);
        $this->useCompany();
        ApprovalFlowStep::query()->create(['approval_flow_id' => $flow->id, 'level' => 1, 'step_name' => 'level 1',
            'approver_type' => ApprovalFlowStep::BY_USER, 'approver_id' => $signer->id, 'requires_all' => false]);

        $document = $this->upload('Dealer agreement');
        $this->actingAs($this->owner)->post(route('documents.signature.request', $document))->assertSessionHasNoErrors();
        $this->useCompany();
        $approval = Approval::query()->where('action', DocumentSignatures::ACTION)->where('approvable_id', $document->id)->firstOrFail();

        $asked = $this->phone($signer, '/api/v1/documents/library/'.$document->public_id)->assertOk()->json('signature');
        $this->assertSame(DocumentSignatures::PENDING, $asked['state']);
        $this->assertSame((string) $approval->public_id, $asked['approval_id'], '⛔ সইকারী ফোনে সইয়ের দরজা পাননি।');

        $this->assertNull($this->phone($bystander, '/api/v1/documents/library/'.$document->public_id)->assertOk()->json('signature.approval_id'),
            '⛔ যিনি সইকারী নন তিনিও সইয়ের দরজা পেলেন।');

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($signer->fresh(), [AuthController::APP]);
        $this->postJson('/api/v1/approvals/'.$approval->public_id.'/approve')->assertSuccessful();
        $this->useCompany();

        $this->assertSame(1, DocumentSignature::query()->where('document_id', $document->id)->where('user_id', $signer->id)->count(),
            '⛔ ফোনের সইয়ে সইয়ের সারি বসেনি।');
        $after = $this->phone($this->owner, '/api/v1/documents/library/'.$document->public_id)->assertOk()->json('signature');
        $this->assertSame(DocumentSignatures::SIGNED, $after['state']);
        $this->assertNull($after['approval_id']);
        $this->assertTrue($after['signed'][0]['current']);
    }

    public function test_without_the_key_or_with_documents_off_it_is_refused(): void
    {
        $nobody = $this->person('nobody@abos.test', []);
        $this->useCompany();
        $this->phone($nobody, '/api/v1/documents/library')->assertForbidden();

        app(SettingsService::class)->set(PhoneModules::key('documents'), false);
        $this->phone($this->owner, '/api/v1/documents/library')->assertForbidden()->assertJsonPath('reason', PhoneModules::REASON);
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
