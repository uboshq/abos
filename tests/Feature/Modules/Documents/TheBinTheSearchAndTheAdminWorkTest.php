<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Documents;

use App\Core\Services\SettingsService;
use App\Models\Attachment;
use App\Models\AuditTrail;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\DocumentType;
use App\Modules\Documents\Models\DocumentVersion;
use App\Modules\Documents\Models\MetadataField;
use App\Modules\Documents\Support\DocumentCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * দ্বিতীয় ধাপের পর্দা — রিসাইকেল বিন, বিস্তারিত খোঁজ, প্রশাসন আর ফাইলের সীমা
 * (পরিকল্পনা §১৬, §১৯, §২০, §২২)।
 */
final class TheBinTheSearchAndTheAdminWorkTest extends TestCase
{
    use MakesDocuments;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDocuments();
    }

    public function test_a_deleted_document_waits_in_the_bin_and_comes_back_as_it_was(): void
    {
        $document = $this->upload('Warehouse lease');
        $document->forceFill(['status' => DocumentCatalog::PUBLISHED])->saveQuietly();

        $this->actingAs($this->owner)->delete(route('documents.destroy', $document))->assertRedirect();
        $this->useCompany();

        $gone = Document::withTrashed()->findOrFail($document->id);
        $this->assertSame(DocumentCatalog::DELETED, $gone->status, 'মোছা কাগজের অবস্থা "মোছা" নয়।');
        $this->assertSame($this->owner->id, (int) $gone->deleted_by);

        $this->assertStringNotContainsString('Warehouse lease', $this->page(route('documents.index')), 'মোছা কাগজ সেন্টারে।');
        $bin = $this->page(route('documents.bin'));
        $this->assertStringContainsString('Warehouse lease', $bin, 'মোছা কাগজ বিনে নেই।');
        $this->assertStringContainsString($this->owner->name, $bin, 'বিনে কে মুছলেন লেখা নেই।');

        $this->actingAs($this->owner)->post(route('documents.bin.restore', $document->id))->assertRedirect();
        $this->useCompany();

        $back = $document->fresh();
        $this->assertNull($back->deleted_at, 'ফেরানোর পরেও কাগজ মোছা।');
        $this->assertSame(DocumentCatalog::PUBLISHED, $back->status, 'ফেরানো কাগজ আগের অবস্থায় ফেরেনি।');
        $this->assertTrue(
            AuditTrail::query()->forRecord(Document::class, $document->id)->where('action', 'document_restored')->exists(),
            'বিন থেকে ফেরানো অডিটে নেই।',
        );
    }

    public function test_only_the_purge_key_deletes_for_good_and_the_audit_stays(): void
    {
        $document = $this->upload('Old quotation', file: $this->pdf('q.pdf', 'purge-me'));
        $version = DocumentVersion::query()->findOrFail($document->current_version_id);
        $file = Attachment::query()->findOrFail($version->attachment_id);

        $this->actingAs($this->owner)->delete(route('documents.destroy', $document))->assertRedirect();
        $this->useCompany();

        $clerk = $this->person('bin-clerk@abos.test', ['documents.view', 'documents.delete', 'documents.restore']);
        $this->actingAs($clerk)->delete(route('documents.bin.purge', $document->id))->assertForbidden();
        $this->useCompany();
        $this->assertNotNull(Document::withTrashed()->find($document->id), 'চিরতরে মোছার চাবি ছাড়াই কাগজ মুছে গেল।');

        $this->actingAs($this->owner)->delete(route('documents.bin.purge', $document->id))->assertRedirect(route('documents.bin'));
        $this->useCompany();

        $this->assertNull(Document::withTrashed()->find($document->id), 'চিরতরে মোছা কাগজ রয়ে গেছে।');
        $this->assertSame(0, DB::table('dms_document_versions')->where('document_id', $document->id)->count(), 'ভার্সনের সারি রয়ে গেছে।');
        $this->assertFalse(Storage::disk('local')->exists($file->stored_path), 'ফাইল ডিস্কে রয়ে গেছে।');
        $this->assertTrue(
            AuditTrail::query()->forRecord(Document::class, $document->id)->where('action', 'document_purged')->exists(),
            'চিরতরে মোছার কথা অডিটে নেই — নাকি অডিটও মুছে গেল?',
        );
    }

    public function test_the_bin_and_its_doors_stay_behind_the_branch_wall(): void
    {
        $document = $this->upload('Branch only memo');
        $this->actingAs($this->owner)->delete(route('documents.destroy', $document))->assertRedirect();
        $this->useCompany();

        $clerk = $this->person('ntk-bin@abos.test', ['documents.view', 'documents.restore', 'documents.purge'], limitedTo: $this->netrakona);

        $bin = (string) $this->actingAs($clerk)->get(route('documents.bin'))->assertOk()->getContent();
        $this->assertStringNotContainsString('Branch only memo', $bin, 'অন্য শাখার মোছা কাগজ বিনে দেখা যায়।');
        $this->actingAs($clerk)->post(route('documents.bin.restore', $document->id))->assertNotFound();
        $this->actingAs($clerk)->delete(route('documents.bin.purge', $document->id))->assertNotFound();
    }

    public function test_the_advanced_search_reads_every_filter_and_the_archive_too(): void
    {
        $a = $this->upload('Fire licence', extra: ['document_date' => '2026-01-10', 'tags' => 'fire, safety']);
        $b = $this->upload('Trade licence', extra: ['document_date' => '2026-06-10']);
        $this->actingAs($this->owner)->post(route('documents.archive', $b))->assertRedirect();
        $this->actingAs($this->owner)
            ->post(route('documents.version.store', $a), ['file' => $this->pdf('f.pdf', 'v2'), 'comment' => 'Renewed by city corporation'])
            ->assertSessionHasNoErrors();
        $this->useCompany();

        $this->assertFinds(['content' => 'city corporation'], [$a], [$b], 'ভার্সনের মন্তব্যের লেখা');
        $this->assertFinds(['date_from' => '2026-05-01'], [$b], [$a], 'তারিখের সীমা');
        $this->assertFinds(['tag' => 'safety'], [$a], [$b], 'ট্যাগ');
        $this->assertFinds(['status' => DocumentCatalog::ARCHIVED], [$b], [$a], 'অবস্থা');
        $this->assertFinds(['q' => 'licence'], [$a, $b], [], 'আর্কাইভসহ নাম');
    }

    public function test_the_admin_adds_a_type_and_a_required_field_that_the_upload_obeys(): void
    {
        $this->actingAs($this->owner)
            ->post(route('documents.admin.store', 'types'), ['code' => 'permit', 'name_en' => 'Permit', 'name_bn' => 'অনুমতিপত্র'])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->owner)
            ->post(route('documents.admin.store', 'fields'), [
                'code' => 'permit_no', 'name_en' => 'Permit number', 'name_bn' => 'অনুমতিপত্র নম্বর',
                'kind' => 'text', 'doc_type' => 'permit', 'is_required' => '1',
            ])
            ->assertSessionHasNoErrors();
        // ⛔ মালিকের তালিকার কোড নেওয়া যায় না
        $this->actingAs($this->owner)
            ->post(route('documents.admin.store', 'types'), ['code' => 'contract', 'name_en' => 'X', 'name_bn' => 'X'])
            ->assertSessionHasErrors('code');
        $this->useCompany();

        $field = MetadataField::query()->where('code', 'permit_no')->firstOrFail();
        $details = [
            'name' => 'Boiler permit', 'doc_type' => 'permit', 'folder' => 'compliance',
            'branch_id' => $this->main->id, 'confidentiality' => DocumentCatalog::INTERNAL,
        ];

        $this->actingAs($this->owner)
            ->post(route('documents.store'), [...$details, 'files' => [$this->pdf()]])
            ->assertSessionHasErrors('meta.'.$field->id);

        $this->actingAs($this->owner)
            ->post(route('documents.store'), [...$details, 'files' => [$this->pdf()], 'meta' => [$field->id => 'BP-778']])
            ->assertSessionHasNoErrors();
        $this->useCompany();

        $document = Document::query()->where('name', 'Boiler permit')->firstOrFail();
        $page = $this->page(route('documents.show', $document));
        $this->assertStringContainsString('BP-778', $page, 'বাড়তি ঘরের মান বিস্তারিত পাতায় নেই।');
        $this->assertStringContainsString('অনুমতিপত্র', $page, 'নিজের ধরনের নাম বিস্তারিত পাতায় নেই।');

        // ⓘ ধরন বন্ধ করলে নতুন কাগজে আর চলে না, পুরনো কাগজে নাম থাকে
        $type = DocumentType::query()->where('code', 'permit')->firstOrFail();
        $this->actingAs($this->owner)->post(route('documents.admin.toggle', ['types', $type->id]))->assertRedirect();
        $this->actingAs($this->owner)
            ->post(route('documents.store'), [...$details, 'name' => 'Another', 'files' => [$this->pdf()], 'meta' => [$field->id => 'X']])
            ->assertSessionHasErrors('doc_type');
        $this->useCompany();
        $this->assertStringContainsString('অনুমতিপত্র', $this->page(route('documents.show', $document)));
    }

    public function test_the_admin_screen_needs_its_own_key(): void
    {
        $reader = $this->person('no-admin@abos.test', ['documents.view']);
        $this->actingAs($reader)->get(route('documents.admin'))->assertForbidden();
        $this->actingAs($reader)->post(route('documents.admin.store', 'tags'), ['name' => 'x'])->assertForbidden();
        $this->useCompany();

        $this->actingAs($this->owner)->get(route('documents.admin'))->assertOk();
    }

    public function test_the_control_panel_switches_shape_what_can_be_uploaded(): void
    {
        $png = UploadedFile::fake()->image('photo.png', 20, 20);

        app(SettingsService::class)->set('documents.allow_images', false);

        $this->actingAs($this->owner)
            ->post(route('documents.store'), [
                'name' => 'Photo', 'doc_type' => 'other', 'folder' => 'company',
                'branch_id' => $this->main->id, 'confidentiality' => DocumentCatalog::INTERNAL,
                'files' => [$png],
            ])
            ->assertSessionHasErrors('files.0');
        $this->useCompany();

        app(SettingsService::class)->set('documents.allow_images', true);
        app(SettingsService::class)->set('documents.max_upload_mb', 1);

        $big = UploadedFile::fake()->createWithContent('big.pdf', "%PDF-1.4\n".str_repeat('a', 1024 * 1024 + 10)."\n%%EOF\n");

        $this->actingAs($this->owner)
            ->post(route('documents.store'), [
                'name' => 'Big', 'doc_type' => 'other', 'folder' => 'company',
                'branch_id' => $this->main->id, 'confidentiality' => DocumentCatalog::INTERNAL,
                'files' => [$big],
            ])
            ->assertSessionHasErrors('files.0');
        $this->useCompany();

        $this->assertSame(0, Document::query()->whereIn('name', ['Photo', 'Big'])->count());
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    private function page(string $url): string
    {
        $html = (string) $this->actingAs($this->owner)->get($url)->assertOk()->getContent();
        $this->useCompany();

        return $html;
    }

    /**
     * @param  array<string, string>  $filters
     * @param  list<Document>  $in
     * @param  list<Document>  $out
     */
    private function assertFinds(array $filters, array $in, array $out, string $what): void
    {
        $html = $this->page(route('documents.search', $filters));

        foreach ($in as $d) {
            $this->assertStringContainsString(route('documents.show', $d), $html, $what.' দিয়ে '.$d->name.' পাওয়া গেল না।');
        }

        foreach ($out as $d) {
            $this->assertStringNotContainsString(route('documents.show', $d), $html, $what.' দিয়ে '.$d->name.' এল, আসার কথা নয়।');
        }
    }
}
