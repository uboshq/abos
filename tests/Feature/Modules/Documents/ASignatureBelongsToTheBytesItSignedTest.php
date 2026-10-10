<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Documents;

use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Attachment;
use App\Models\AuditTrail;
use App\Models\Notification;
use App\Models\User;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\DocumentSignature;
use App\Modules\Documents\Models\DocumentVersion;
use App\Modules\Documents\Services\DocumentNotices;
use App\Modules\Documents\Services\DocumentSignatures;
use App\Modules\Documents\Support\DocumentCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * সই কেন্দ্র — একজন, অনেকে, পরপর; সই একটা ভার্সনের একটা হ্যাশের (পরিকল্পনা §১১; চতুর্থ ধাপ)।
 *
 * ⓘ সই দেওয়া হয় ইনবক্সের নিজের দরজা দিয়ে, মানুষ যেভাবে দেন।
 */
final class ASignatureBelongsToTheBytesItSignedTest extends TestCase
{
    use MakesDocuments;
    use RefreshDatabase;

    private const SIGNER = ['documents.view', 'approval.decide'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDocuments();
    }

    public function test_without_a_signature_flow_nobody_pretends_it_was_signed(): void
    {
        $document = $this->upload('Unsigned lease');

        $this->actingAs($this->owner)->post(route('documents.signature.request', $document))
            ->assertSessionHasErrors('signature');
        $this->useCompany();

        $this->assertSame(0, DocumentSignature::query()->count());
        $this->assertSame(0, Approval::query()->where('action', DocumentSignatures::ACTION)->count());
    }

    public function test_sequential_signers_sign_exactly_the_version_and_hash_that_was_asked(): void
    {
        [$first, $second] = $this->flow([[1], [2]]);
        $document = $this->upload('Joint venture deed', DocumentCatalog::CONFIDENTIAL, file: $this->pdf('jv.pdf', 'signed-bytes'));
        $version = DocumentVersion::query()->findOrFail($document->current_version_id);

        $this->actingAs($this->owner)->post(route('documents.signature.request', $document), ['note' => 'Sign please'])
            ->assertSessionHasNoErrors();
        $this->useCompany();

        $approval = Approval::query()->where('action', DocumentSignatures::ACTION)->where('approvable_id', $document->id)->firstOrFail();
        $this->assertSame($version->file_hash, $approval->payload['file_hash'], 'অনুরোধে ভার্সনের হ্যাশ বাঁধা হয়নি।');
        $this->assertTrue($this->told($first, DocumentNotices::SIGNATURE_REQUIRED), 'প্রথম সইকারী খবর পাননি।');
        $this->assertFalse($this->told($second, DocumentNotices::SIGNATURE_REQUIRED), 'দ্বিতীয়জন আগেই খবর পেলেন।');

        /*
         * ⛔ সই চলাকালীন ফাইল নড়ে না, আর সই একসাথে অনেকগুলোয় যায় না (১১ অক্টোবর ২০২৬, documents রিভিউ ⚠️১১, ⚠️১২)।
         * ⓘ আগে সইয়ের মাঝে নতুন ভার্সন দিলে সইকারী নতুন ফাইল দেখতেন, অথচ সই বসত পুরনো hash-এ।
         */
        $this->actingAs($this->owner)->post(route('documents.version.store', $document), ['file' => $this->pdf('jv.pdf', 'swapped-mid-signing')])
            ->assertForbidden();
        $this->useCompany();
        $this->assertSame($version->id, (int) $document->fresh()->current_version_id, '⛔ সই চলার মাঝে ফাইল বদলে গেল।');
        $this->assertTrue(app(\App\Core\Engines\Approval\BulkApproval::class)->movesMoney($approval), '⛔ সই না খুলে একসাথে অনেকগুলোয় দেওয়া যায়।');

        // ⭐ সইকারী গোপন কাগজটা পড়েই সই দেন; সই কেন্দ্রে তাঁর সারিতে কাগজ
        $this->actingAs($first)->get(route('documents.show', $document))->assertOk();
        $center = (string) $this->actingAs($first)->get(route('documents.signatures'))->assertOk()->getContent();
        $this->assertStringContainsString('Joint venture deed', $center, 'সই কেন্দ্রে অপেক্ষার কাগজ নেই।');

        $this->actingAs($first)->post(route('approval.inbox.approve', $approval->id))->assertRedirect();
        $this->useCompany();
        $this->assertSame(0, DocumentSignature::query()->count(), 'শেষ সইয়ের আগেই সই বসেছে।');

        $this->actingAs($second)->post(route('approval.inbox.approve', $approval->id))->assertRedirect();
        $this->useCompany();

        $rows = DocumentSignature::query()->where('document_id', $document->id)->orderBy('level')->get();
        $this->assertSame([$first->id, $second->id], $rows->pluck('user_id')->map(fn ($id) => (int) $id)->all(), 'সইকারী আর ক্রম মিলল না।');
        $this->assertSame([1, 2], $rows->pluck('level')->all());
        foreach ($rows as $row) {
            $this->assertSame($version->id, (int) $row->version_id);
            $this->assertSame($version->file_hash, $row->file_hash, 'সই অন্য হ্যাশে বসেছে।');
        }

        $this->assertSame(DocumentSignatures::SIGNED, app(DocumentSignatures::class)->state($document->fresh()));
        $this->assertTrue($this->told($this->owner, DocumentNotices::SIGNATURE_COMPLETED), 'চাওয়াকারী "সব সই হলো" খবর পাননি।');
        $this->assertTrue(AuditTrail::query()->forRecord(Document::class, $document->id)->where('action', 'document_signed')->exists());

        $this->actingAs($this->owner)->post(route('documents.signature.verify', [$document, $rows->first()]))
            ->assertSessionHas('saved', __('documents::message.verify_ok'));

        // ⛔ নতুন ভার্সন — পুরনো সই পুরনোই থাকে, নতুনটায় আবার সই লাগে
        $this->actingAs($this->owner)
            ->post(route('documents.version.store', $document), ['file' => $this->pdf('jv.pdf', 'amended-bytes')])
            ->assertSessionHasNoErrors();
        $this->useCompany();
        $this->assertSame(DocumentSignatures::STALE, app(DocumentSignatures::class)->state($document->fresh()));
        $this->actingAs($this->owner)->post(route('documents.signature.verify', [$document, $rows->first()]))
            ->assertSessionHas('saved', __('documents::message.verify_old_version'));
    }

    public function test_a_file_changed_on_disk_after_signing_is_caught(): void
    {
        [$signer] = $this->flow([[1]]);
        $document = $this->upload('Board resolution', file: $this->pdf('r.pdf', 'original-resolution'));

        $this->actingAs($this->owner)->post(route('documents.signature.request', $document))->assertSessionHasNoErrors();
        $this->useCompany();
        $approval = Approval::query()->where('action', DocumentSignatures::ACTION)->firstOrFail();
        $this->actingAs($signer)->post(route('approval.inbox.approve', $approval->id))->assertRedirect();
        $this->useCompany();

        $signature = DocumentSignature::query()->firstOrFail();
        $file = Attachment::query()->findOrFail(DocumentVersion::query()->findOrFail($signature->version_id)->attachment_id);
        Storage::disk('local')->put($file->stored_path, '%PDF-1.4 forged');

        $this->actingAs($this->owner)->post(route('documents.signature.verify', [$document, $signature]))
            ->assertSessionHas('warning', __('documents::message.verify_broken'));
        $this->useCompany();

        $this->assertSame('mismatch', AuditTrail::query()->forRecord(Document::class, $document->id)
            ->where('action', 'signature_verified')->latest('id')->value('reason'), 'বদলানো ফাইলের যাচাই অডিটে নেই।');
    }

    public function test_many_signers_at_one_level_all_have_to_sign(): void
    {
        [$a, $b] = $this->flow([[1, 1]], requiresAll: true);
        $document = $this->upload('Partnership letter');

        $this->actingAs($this->owner)->post(route('documents.signature.request', $document))->assertSessionHasNoErrors();
        $this->useCompany();
        $approval = Approval::query()->where('action', DocumentSignatures::ACTION)->firstOrFail();

        $this->actingAs($a)->post(route('approval.inbox.approve', $approval->id))->assertRedirect();
        $this->useCompany();
        $this->assertSame(0, DocumentSignature::query()->count(), 'একজনের সইয়েই "সবার সই" হয়ে গেল।');

        $this->actingAs($b)->post(route('approval.inbox.approve', $approval->id))->assertRedirect();
        $this->useCompany();
        $this->assertSame(2, DocumentSignature::query()->where('document_id', $document->id)->count());
    }

    public function test_a_refused_signature_leaves_no_signature_and_says_why(): void
    {
        [$signer] = $this->flow([[1]]);
        $document = $this->upload('Disputed invoice');

        $this->actingAs($this->owner)->post(route('documents.signature.request', $document))->assertSessionHasNoErrors();
        $this->useCompany();
        $approval = Approval::query()->where('action', DocumentSignatures::ACTION)->firstOrFail();

        $this->actingAs($signer)->post(route('approval.inbox.reject', $approval->id), ['remarks' => 'Amount is wrong'])->assertRedirect();
        $this->useCompany();

        $this->assertSame(0, DocumentSignature::query()->count());
        $this->assertSame('Amount is wrong', AuditTrail::query()->forRecord(Document::class, $document->id)
            ->where('action', 'signature_refused')->value('reason'));
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    /**
     * ⛔ অন্য শাখার সইকারী যে কাগজে সই চাওয়া তা খুলতে পারেন — ৪০৪ নয় (১১ অক্টোবর ২০২৬, documents রিভিউ ⚠️১৩)।
     * ⓘ ঠিকানার শাখা-দেয়াল সইকারীর ছাড়ের আগেই কাগজটা ফেলে দিত; আর দরজাটা কেবল সইকারীর জন্য — অন্য শাখার সাধারণ মানুষ আগের মতো ৪০৪।
     */
    public function test_a_signer_in_another_branch_can_open_the_paper_they_are_asked_to_sign(): void
    {
        $this->useCompany();
        $flow = ApprovalFlow::query()->create(['company_id' => $this->company->id, 'code' => 'DOCSIGN', 'module' => 'documents',
            'action' => DocumentSignatures::ACTION, 'threshold_amount' => null, 'is_active' => true]);
        $signer = $this->person('far-signer@abos.test', self::SIGNER, $this->netrakona);
        $this->useCompany();
        ApprovalFlowStep::query()->create(['approval_flow_id' => $flow->id, 'level' => 1, 'step_name' => 'level 1',
            'approver_type' => ApprovalFlowStep::BY_USER, 'approver_id' => $signer->id, 'requires_all' => false]);

        $document = $this->upload('Main branch lease');   // ⓘ প্রধান শাখায়
        $stranger = $this->person('far-stranger@abos.test', self::SIGNER, $this->netrakona);

        $this->actingAs($this->owner)->post(route('documents.signature.request', $document))->assertSessionHasNoErrors();
        $this->useCompany();

        $this->actingAs($signer)->get(route('documents.show', $document))->assertOk();
        $this->useCompany();
        $this->assertContains($this->actingAs($stranger)->get(route('documents.show', $document))->getStatusCode(), [403, 404],
            '⛔ সই চাওয়া হয়নি এমন অন্য শাখার মানুষও কাগজ খুললেন।');
    }

    /**
     * সইয়ের ধারা — প্রতিটা ভিতরের তালিকা একটা স্তর, তাতে যতজন সইকারী।
     *
     * @param  list<list<int>>  $levels
     * @return list<User>
     */
    private function flow(array $levels, bool $requiresAll = false): array
    {
        $this->useCompany();

        $flow = ApprovalFlow::query()->create([
            'company_id' => $this->company->id, 'code' => 'DOCSIGN', 'module' => 'documents',
            'action' => DocumentSignatures::ACTION, 'threshold_amount' => null, 'is_active' => true,
        ]);

        $signers = [];
        $n = 0;

        foreach ($levels as $i => $people) {
            foreach ($people as $ignored) {
                $n++;
                $signer = $this->person('sig'.$n.'@abos.test', self::SIGNER);
                $this->useCompany();

                ApprovalFlowStep::query()->create([
                    'approval_flow_id' => $flow->id, 'level' => $i + 1, 'step_name' => 'level '.($i + 1),
                    'approver_type' => ApprovalFlowStep::BY_USER, 'approver_id' => $signer->id,
                    'requires_all' => $requiresAll,
                ]);

                $signers[] = $signer;
            }
        }

        return $signers;
    }

    private function told(User $user, string $type): bool
    {
        return Notification::query()->withoutGlobalScopes()->where('user_id', $user->id)->where('type', $type)->exists();
    }
}
