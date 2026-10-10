<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Documents;

use App\Models\Attachment;
use App\Models\AuditTrail;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\DocumentVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * আর্কাইভ সেন্টার থেকে সরায়, মোছে না; আর ফাইল কখনো `public/`-এ নয় (পরিকল্পনা §১, §৪, §৬)।
 *
 * ── ⓘ আর্কাইভ ─────────────────────────────────────────────────────────
 * আর্কাইভ করা কাগজ সেন্টারের তালিকা থেকে সরে, "আর্কাইভ করা" ছাঁকনিতে থাকে, আর
 * ফেরালে আবার সেন্টারে আসে। দুই কাজই অডিটে নিজের নামে।
 *
 * ── ⛔ ফাইল কোথায় ─────────────────────────────────────────────────────
 * ফাইল ভিতরের ডিস্কে (`storage/app/private`)। ⚠️ `public/`-এ গেলে ঠিকানা আন্দাজ
 * করেই যে কেউ লগইন ছাড়া খুলতে পারতেন — কোম্পানি, শাখা, গোপনীয়তা কিছুই লাগত না।
 * ⓘ সারি গোনা নয়, ফাইলটা কোন ডিস্কে আছে সেটাই দেখা হয়।
 */
final class AnArchivedDocumentLeavesTheCenterTest extends TestCase
{
    use MakesDocuments;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDocuments();
    }

    public function test_archive_hides_the_document_from_the_center_and_restore_brings_it_back(): void
    {
        $document = $this->upload('Old trade licence');

        $this->assertInCenter('Old trade licence', 'তোলার পরেই');

        $this->actingAs($this->owner)
            ->post(route('documents.archive', $document), ['reason' => 'Renewed'])
            ->assertRedirect();
        $this->useCompany();

        $this->assertNotNull($document->fresh()->archived_at, 'আর্কাইভ করার পরেও কাগজ চলতি।');
        $this->assertNotInCenter('Old trade licence', 'আর্কাইভ করার পরেও');

        $archived = (string) $this->actingAs($this->owner)
            ->get(route('documents.index', ['archived' => 1]))->assertOk()->getContent();
        $this->assertStringContainsString('Old trade licence', $archived, 'আর্কাইভ করা কাগজ আর্কাইভের ছাঁকনিতেও নেই — হারিয়ে গেছে।');

        $this->actingAs($this->owner)
            ->post(route('documents.unarchive', $document))
            ->assertRedirect();
        $this->useCompany();

        $this->assertNull($document->fresh()->archived_at);
        $this->assertInCenter('Old trade licence', 'ফেরানোর পরে');

        $actions = AuditTrail::query()->forRecord(Document::class, $document->id)->pluck('action')->all();
        $this->assertContains('document_archived', $actions, 'আর্কাইভ অডিটে নেই।');
        $this->assertContains('document_unarchived', $actions, 'ফেরানো অডিটে নেই।');
    }

    public function test_the_file_sits_on_the_private_disk_and_never_under_public(): void
    {
        $document = $this->upload('Deed copy');

        $version = DocumentVersion::query()->withoutGlobalScopes()->findOrFail($document->current_version_id);
        $file = Attachment::query()->withoutGlobalScopes()->findOrFail($version->attachment_id);

        $this->assertTrue(Storage::disk('local')->exists($file->stored_path), 'ফাইলটা ভিতরের ডিস্কে নেই — সারি আছে, ফাইল নেই।');
        $this->assertFalse(Storage::disk('public')->exists($file->stored_path), 'ফাইলটা public ডিস্কে গেছে।');
        $this->assertSame([], Storage::disk('public')->allFiles(), 'তোলার পর public ডিস্কে কিছু নেমেছে।');
        $this->assertFalse(File::exists(public_path($file->stored_path)), 'ফাইলটা public/-এর নিচে।');
        $this->assertFalse(File::exists(public_path('storage/'.$file->stored_path)), 'ফাইলটা public/storage-এর নিচে।');

        // ⛔ লগইন ছাড়া ফাইলের দরজা বন্ধ
        auth()->logout();
        $this->get(route('documents.download', $document))->assertRedirect(route('login'));
        $this->get('/storage/'.$file->stored_path)->assertNotFound();
    }

    private function assertInCenter(string $name, string $when): void
    {
        $list = (string) $this->actingAs($this->owner)->get(route('documents.index'))->assertOk()->getContent();
        $this->assertStringContainsString($name, $list, $when.' কাগজটা সেন্টারে নেই।');
        $this->useCompany();
    }

    private function assertNotInCenter(string $name, string $when): void
    {
        $list = (string) $this->actingAs($this->owner)->get(route('documents.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString($name, $list, $when.' কাগজটা সেন্টারে।');
        $this->useCompany();
    }
}
