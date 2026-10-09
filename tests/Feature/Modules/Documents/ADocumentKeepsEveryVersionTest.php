<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Documents;

use App\Models\AuditTrail;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\DocumentVersion;
use App\Modules\Documents\Support\DocumentCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * একটা কাগজ তার প্রতিটা ভার্সন রাখে — পরিকল্পনা §৬, §৯ (৮ অক্টোবর ২০২৬)।
 *
 * ── ⓘ দাবি ─────────────────────────────────────────────────────────────
 * তোলা মানেই v1.0 আর অডিটে একটা সারি। নতুন ভার্সন নম্বর বাড়ায় (ছোট বদলে v1.1,
 * বড় বদলে v2.0), আর ⛔ আগের ফাইল কখনো হারায় না — পুরনো ভার্সন নামালে পুরনো বাইটই আসে।
 * পুরনো ফেরানো মানে নতুন ভার্সন, ইতিহাস পেছনে হাঁটে না।
 *
 * ⚠️ অনুমোদিত কাগজ নিজের জায়গায় বদলায় না — বদলের পথ কেবল নতুন ভার্সন।
 */
final class ADocumentKeepsEveryVersionTest extends TestCase
{
    use MakesDocuments;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDocuments();
    }

    public function test_an_upload_makes_version_one_and_writes_the_audit_trail(): void
    {
        $document = $this->upload();

        $versions = DocumentVersion::query()->withoutGlobalScopes()->where('document_id', $document->id)->get();

        $this->assertCount(1, $versions, 'একটা ফাইল তুলে একটার বেশি বা কম ভার্সন।');
        $this->assertSame('1.0', $versions->first()->label(), 'প্রথম ভার্সন v1.0 নয়।');
        $this->assertSame($versions->first()->id, (int) $document->current_version_id, 'চলতি ভার্সন প্রথমটায় বসেনি।');
        $this->assertNotEmpty($document->document_no, 'কাগজ নম্বর ছাড়া বসেছে।');

        $this->assertTrue(
            AuditTrail::query()->forRecord(Document::class, $document->id)->where('action', AuditTrail::CREATED)->exists(),
            'তোলার কথা অডিটে নেই।',
        );

        // ⓘ দেখা আর নামানোও অডিটে, নিজের নামে
        $this->actingAs($this->owner)->get(route('documents.show', $document))->assertOk();
        $this->actingAs($this->owner)->get(route('documents.download', $document))->assertOk();
        $this->useCompany();

        $actions = AuditTrail::query()->forRecord(Document::class, $document->id)->pluck('action')->all();
        $this->assertContains('document_viewed', $actions, 'বিস্তারিত দেখা অডিটে নেই।');
        $this->assertContains('document_downloaded', $actions, 'ফাইল নামানো অডিটে নেই।');
    }

    public function test_a_new_version_bumps_the_number_and_the_old_file_stays_downloadable(): void
    {
        $document = $this->upload(file: $this->pdf('contract.pdf', 'the-first-signed-copy'));
        $first = DocumentVersion::query()->withoutGlobalScopes()->findOrFail($document->current_version_id);

        $this->actingAs($this->owner)
            ->post(route('documents.version.store', $document), [
                'file' => $this->pdf('contract.pdf', 'the-corrected-copy'),
                'comment' => 'Typo fixed',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();
        $this->useCompany();

        $minor = DocumentVersion::query()->withoutGlobalScopes()->findOrFail($document->fresh()->current_version_id);
        $this->assertSame('1.1', $minor->label(), 'ছোট বদলে v1.1 হয়নি।');
        $this->assertSame('Typo fixed', $minor->comment);
        $this->assertSame($this->owner->id, (int) $minor->created_by, 'ভার্সনের লেখক নেই।');

        $this->actingAs($this->owner)
            ->post(route('documents.version.store', $document), [
                'file' => $this->pdf('contract.pdf', 'the-new-term'),
                'major' => '1',
            ])
            ->assertSessionHasNoErrors();
        $this->useCompany();

        $major = DocumentVersion::query()->withoutGlobalScopes()->findOrFail($document->fresh()->current_version_id);
        $this->assertSame('2.0', $major->label(), 'বড় বদলে v2.0 হয়নি।');

        // ⛔ পুরনো ফাইলটা পুরনো বাইটসহই থাকে
        $old = $this->actingAs($this->owner)
            ->get(route('documents.version.download', [$document, $first]))
            ->assertOk();
        $this->assertStringContainsString('the-first-signed-copy', $old->streamedContent(), 'v1.0 নামালে পুরনো ফাইল আসেনি।');

        $current = $this->actingAs($this->owner)->get(route('documents.download', $document))->assertOk();
        $this->assertStringContainsString('the-new-term', $current->streamedContent(), 'চলতি নামালে v2.0 আসেনি।');

        $this->useCompany();
        $this->assertTrue(
            AuditTrail::query()->forRecord(Document::class, $document->id)->where('action', 'document_version_added')->exists(),
            'নতুন ভার্সন অডিটে নেই।',
        );
    }

    public function test_restoring_an_old_version_makes_a_new_one_and_keeps_the_middle(): void
    {
        $document = $this->upload(file: $this->pdf('contract.pdf', 'original'));
        $first = DocumentVersion::query()->withoutGlobalScopes()->findOrFail($document->current_version_id);

        $this->actingAs($this->owner)
            ->post(route('documents.version.store', $document), ['file' => $this->pdf('contract.pdf', 'mistake')])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->owner)
            ->post(route('documents.version.restore', [$document, $first]))
            ->assertRedirect();
        $this->useCompany();

        $versions = DocumentVersion::query()->withoutGlobalScopes()->where('document_id', $document->id)->get();
        $this->assertCount(3, $versions, 'ফেরানো নতুন ভার্সন বানায়নি, বা মাঝেরটা মুছে গেছে।');

        $restored = $versions->firstWhere('id', $document->fresh()->current_version_id);
        $this->assertSame('1.2', $restored->label(), 'ফেরানো ভার্সন v1.2 নয়।');
        $this->assertSame($first->attachment_id, $restored->attachment_id, 'ফেরানো ভার্সন v1.0-র ফাইল ধরেনি।');
        $this->assertSame($first->id, (int) $restored->restored_from_id);
    }

    public function test_an_approved_document_is_never_edited_in_place(): void
    {
        $document = $this->upload();
        $document->forceFill(['status' => DocumentCatalog::APPROVED])->saveQuietly();

        $this->actingAs($this->owner)
            ->put(route('documents.update', $document), [
                'name' => 'Quietly changed',
                'doc_type' => 'contract',
                'folder' => 'contracts',
                'branch_id' => $this->main->id,
                'confidentiality' => DocumentCatalog::INTERNAL,
            ])
            ->assertForbidden();

        $this->useCompany();
        $this->assertSame('Supply contract', $document->fresh()->name, 'অনুমোদিত কাগজ নিজের জায়গায় বদলে গেছে।');

        // ⓘ বদলের পথ খোলা — নতুন ভার্সন
        $this->actingAs($this->owner)
            ->post(route('documents.version.store', $document), ['file' => $this->pdf('contract.pdf', 'amended')])
            ->assertSessionHasNoErrors()
            ->assertRedirect();
    }

    public function test_a_file_dressed_as_a_pdf_is_refused(): void
    {
        $this->actingAs($this->owner)
            ->post(route('documents.store'), [
                'name' => 'Not a pdf',
                'doc_type' => 'contract',
                'folder' => 'contracts',
                'branch_id' => $this->main->id,
                'confidentiality' => DocumentCatalog::INTERNAL,
                'files' => [UploadedFile::fake()->createWithContent(
                    'contract.pdf', '<html><script>alert(1)</script></html>',
                )],
            ])
            ->assertSessionHasErrors('files.0');

        $this->useCompany();
        $this->assertSame(0, Document::query()->where('name', 'Not a pdf')->count(), 'PDF নামের HTML তাকে উঠেছে।');
    }
}
