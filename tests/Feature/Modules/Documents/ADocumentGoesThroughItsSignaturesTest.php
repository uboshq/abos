<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Documents;

use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\AuditTrail;
use App\Models\Notification;
use App\Models\User;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Services\DocumentNotices;
use App\Modules\Documents\Support\DocumentCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * অনুমোদনের ধারা — ABOS-এর অনুমোদন ইঞ্জিন দিয়ে (পরিকল্পনা §১০, §২২, §২৩; তৃতীয় ধাপ)।
 *
 * ⓘ সই দেওয়া হয় ইনবক্সের নিজের দরজা দিয়ে (`approval.inbox.approve` / `reject`), মানুষ যেভাবে দেন —
 * ⚠️ ইঞ্জিন সরাসরি ডাকলে শেষ সইয়ের খবর আর কাগজের নড়া কখনো পরীক্ষায় আসত না।
 */
final class ADocumentGoesThroughItsSignaturesTest extends TestCase
{
    use MakesDocuments;
    use RefreshDatabase;

    private const SIGNER = ['documents.view', 'approval.decide'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDocuments();
    }

    /**
     * ⭐ ধারা বন্ধ থাকলে জমা দিলেই "অনুমোদন ছাড়া প্রকাশিত" — কখনো "অনুমোদিত" নয় (fe, ১১ অক্টোবর ২০২৬; মালিকের "সব সইয়ে চালু/বন্ধ সুইচ")।
     * ⓘ আগে কেউ অনুমোদন না করলেও কাগজ "অনুমোদিত" দেখাত, অডিটে "document_approved" — UB-তে সব ধারা বন্ধ, তাই প্রতিটা কাগজে মিথ্যা।
     */
    public function test_without_a_flow_a_submitted_document_is_published_without_approval_and_never_called_approved(): void
    {
        $document = $this->upload('House rules');

        $this->actingAs($this->owner)->post(route('documents.submit', $document))->assertSessionHasNoErrors();
        $this->useCompany();
        $this->assertSame(DocumentCatalog::PUBLISHED_UNAPPROVED, $document->fresh()->status, '⛔ ধারা ছাড়া জমা "অনুমোদিত" দেখাচ্ছে, অথচ কেউ অনুমোদন করেননি।');

        $actions = AuditTrail::query()->forRecord(Document::class, $document->id)->pluck('action')->all();
        $this->assertContains('document_submitted', $actions);
        $this->assertContains('document_published_without_approval', $actions, 'অডিটে "অনুমোদন ছাড়া প্রকাশিত" নেই।');
        $this->assertNotContains('document_approved', $actions, '⛔ অডিটে মিথ্যা "অনুমোদিত"।');

        $page = (string) $this->actingAs($this->owner)->get(route('documents.show', $document))->assertOk()->getContent();
        $this->assertStringContainsString(__('documents::catalog.status.published_unapproved'), $page, 'পাতায় অবস্থার লেখা নেই।');
    }

    public function test_two_signatures_move_it_from_submitted_through_review_to_approved(): void
    {
        [$first, $second] = $this->twoLevelFlow();
        $owner = $this->person('paper-owner@abos.test', ['documents.view']);

        $document = $this->upload('Vendor master agreement', DocumentCatalog::CONFIDENTIAL, extra: ['owner_id' => $owner->id]);

        // ⛔ জমার আগে সইকারী গোপন কাগজটা খুলতে পারেন না
        $this->assertContains($this->actingAs($first)->get(route('documents.show', $document))->getStatusCode(), [403, 404]);
        $this->useCompany();

        $this->actingAs($this->owner)->post(route('documents.submit', $document), ['note' => 'Please sign'])
            ->assertSessionHasNoErrors();
        $this->useCompany();

        $this->assertSame(DocumentCatalog::SUBMITTED, $document->fresh()->status);
        $approval = Approval::query()->where('approvable_type', Document::class)->where('approvable_id', $document->id)->firstOrFail();

        $this->assertTrue($this->told($first, DocumentNotices::APPROVAL_REQUIRED), 'প্রথম সইকারী খবর পাননি।');
        $this->assertFalse($this->told($second, DocumentNotices::APPROVAL_REQUIRED), 'দ্বিতীয় স্তরের সইকারী আগেই খবর পেলেন।');

        // ⭐ এখন সইকারী কাগজটা খোলেন, আর অনুমোদনের সারিতে দেখেন
        $this->actingAs($first)->get(route('documents.show', $document))->assertOk();
        $this->actingAs($first)->get(route('documents.preview', $document))->assertOk();
        $queue = (string) $this->actingAs($first)->get(route('documents.approval'))->assertOk()->getContent();
        $this->assertStringContainsString('Vendor master agreement', $queue, 'অনুমোদনের সারিতে কাগজ নেই।');
        $this->assertStringNotContainsString('Vendor master agreement',
            (string) $this->actingAs($second)->get(route('documents.approval'))->assertOk()->getContent(),
            'দ্বিতীয় স্তরের সারিতে আগেই কাগজ।');

        $this->actingAs($first)->post(route('approval.inbox.approve', $approval->id))->assertRedirect();
        $this->useCompany();

        $this->actingAs($this->owner)->get(route('documents.show', $document))->assertOk();
        $this->useCompany();
        $this->assertSame(DocumentCatalog::UNDER_REVIEW, $document->fresh()->status, 'প্রথম সইয়ের পরে "পর্যালোচনায়" হয়নি।');

        // ⛔ পর্যালোচনার মাঝে কাগজ নিজের জায়গায় বদলায় না
        $this->actingAs($this->owner)
            ->post(route('documents.version.store', $document), ['file' => $this->pdf('x.pdf', 'sneak')])
            ->assertForbidden();

        $this->actingAs($second)->post(route('approval.inbox.approve', $approval->id))->assertRedirect();
        $this->useCompany();

        $this->assertSame(DocumentCatalog::APPROVED, $document->fresh()->status, 'শেষ সইয়ে কাগজ অনুমোদিত হয়নি।');
        $this->assertTrue($this->told($owner, DocumentNotices::APPROVED), 'কাগজের মালিক অনুমোদনের খবর পাননি।');

        // ⓘ সই শেষ — সইকারী আবার নিজের ধাপে
        $this->assertContains($this->actingAs($first)->get(route('documents.show', $document))->getStatusCode(), [403, 404]);
    }

    public function test_sent_back_for_changes_is_not_the_same_as_rejected_and_needs_a_change_to_go_again(): void
    {
        [$first] = $this->twoLevelFlow(levels: 1);
        $document = $this->upload('Price list');

        $this->actingAs($this->owner)->post(route('documents.submit', $document))->assertSessionHasNoErrors();
        $this->useCompany();
        $approval = Approval::query()->where('approvable_id', $document->id)->where('module', 'documents')->firstOrFail();

        $this->actingAs($first)->post(route('approval.inbox.reject', $approval->id), [
            'remarks' => 'Page 2 is missing', 'reason_code' => 'document',
        ])->assertRedirect();
        $this->useCompany();
        $this->assertSame(DocumentCatalog::CHANGES_REQUESTED, $document->fresh()->status, 'সংশোধনে ফেরত "বদল চাওয়া" হয়নি।');

        // ⛔ কিছু না বদলে আবার পাঠানো যায় না
        $this->actingAs($this->owner)->post(route('documents.submit', $document))->assertSessionHasErrors('status');
        $this->useCompany();

        $this->actingAs($this->owner)
            ->post(route('documents.version.store', $document), ['file' => $this->pdf('p.pdf', 'with page two')])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->post(route('documents.submit', $document))->assertSessionHasNoErrors();
        $this->useCompany();
        $this->assertSame(DocumentCatalog::SUBMITTED, $document->fresh()->status);

        $second = Approval::query()->where('approvable_id', $document->id)->where('module', 'documents')->pending()->firstOrFail();
        $this->actingAs($first)->post(route('approval.inbox.reject', $second->id), [
            'remarks' => 'Too expensive', 'reason_code' => 'price',
        ])->assertRedirect();
        $this->useCompany();
        $this->assertSame(DocumentCatalog::REJECTED, $document->fresh()->status, 'সাধারণ "না" বাতিল হয়নি।');
    }

    public function test_the_requester_can_withdraw_before_the_decision(): void
    {
        $this->twoLevelFlow(levels: 1);
        $document = $this->upload('Draft memo');

        $this->actingAs($this->owner)->post(route('documents.submit', $document))->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->post(route('documents.withdraw', $document))->assertSessionHasNoErrors();
        $this->useCompany();

        $this->assertSame(DocumentCatalog::DRAFT, $document->fresh()->status);
        $this->assertSame(Approval::CANCELLED, Approval::query()->where('approvable_id', $document->id)->where('module', 'documents')->value('status'));
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    /** @return list<User> প্রতিটা স্তরে একজন সইকারী */
    private function twoLevelFlow(int $levels = 2): array
    {
        $this->useCompany();

        $flow = ApprovalFlow::query()->create([
            'company_id' => $this->company->id,
            'code' => 'DOCS',
            'module' => 'documents',
            'action' => 'document',
            'threshold_amount' => null,
            'is_active' => true,
        ]);

        $signers = [];

        foreach (range(1, $levels) as $level) {
            $signer = $this->person('signer'.$level.'@abos.test', self::SIGNER);
            $this->useCompany();

            ApprovalFlowStep::query()->create([
                'approval_flow_id' => $flow->id,
                'level' => $level,
                'step_name' => 'level '.$level,
                'approver_type' => ApprovalFlowStep::BY_USER,
                'approver_id' => $signer->id,
            ]);

            $signers[] = $signer;
        }

        return $signers;
    }

    private function told(User $user, string $type): bool
    {
        return Notification::query()->withoutGlobalScopes()->where('user_id', $user->id)->where('type', $type)->exists();
    }
}
