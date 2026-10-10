<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Documents;

use App\Models\Attachment;
use App\Models\AuditTrail;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\DocumentOcr;
use App\Modules\Documents\Models\DocumentVersion;
use App\Modules\Documents\Support\DocumentCatalog;
use App\Modules\Supplier\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * স্ক্যান ও OCR — পাতা এক PDF, লেখা ব্রাউজারে পড়া, আর সব ফাইল আমাদের নিজের সার্ভারে
 * (পরিকল্পনা §৭, §১৬; পঞ্চম ধাপ)।
 *
 * ⓘ লেখা পড়া নিজে ব্রাউজারে চলে (tesseract.js), তাই এখানে তার ফল জমা দেওয়া হয় — মানুষের ব্রাউজার যেভাবে
 * দেয়। ⓘ পথের পাহারা (কোনো CDN নয়) জাভাস্ক্রিপ্টের নিজের পরীক্ষায় ([[document-scan.test.js]])।
 */
final class AScannedPageIsReadOnOurOwnServerTest extends TestCase
{
    use MakesDocuments;
    use RefreshDatabase;

    private const TEXT = "PADMA PACKAGING LTD\nInvoice No: INV-778/26\nDate: 05/10/2026\nItem cartons 500 pcs\nমোট: ১২,৫০০.০০ টাকা\nGrand Total: 12,500.00";

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDocuments();
    }

    public function test_pages_become_one_pdf_with_its_read_text_and_the_text_is_searchable(): void
    {
        $this->actingAs($this->owner)->post(route('documents.scan.store'), [
            'name' => 'Padma invoice', 'doc_type' => 'invoice', 'folder' => 'purchase',
            'branch_id' => $this->main->id, 'confidentiality' => DocumentCatalog::INTERNAL,
            'pages' => [UploadedFile::fake()->image('p1.png', 300, 400), UploadedFile::fake()->image('p2.jpg', 300, 400)],
            'ocr_text' => self::TEXT,
            'ocr_language' => 'ben+eng',
            'ocr_confidence' => '87.5',
            'ocr_fields' => ['invoice_no' => 'INV-778/26', 'date' => '2026-10-05', 'party' => 'Padma Packaging', 'amount' => '12500.00'],
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->useCompany();

        $document = Document::query()->where('name', 'Padma invoice')->firstOrFail();
        $version = DocumentVersion::query()->findOrFail($document->current_version_id);
        $file = Attachment::query()->findOrFail($version->attachment_id);

        $this->assertSame('application/pdf', $file->mime_type, 'পাতাগুলো PDF হয়নি।');
        $this->assertStringStartsWith('%PDF', Storage::disk('local')->get($file->stored_path));

        $ocr = DocumentOcr::query()->where('version_id', $version->id)->firstOrFail();
        $this->assertSame(self::TEXT, $ocr->text);
        $this->assertSame(2, $ocr->pages);
        $this->assertSame('INV-778/26', $ocr->fields['invoice_no']);
        $this->assertStringContainsString('tesseract.js', $ocr->engine);
        $this->assertTrue(AuditTrail::query()->forRecord(Document::class, $document->id)->where('action', 'document_ocr_saved')->exists());

        // ⭐ লেখার ভিতরের শব্দে খোঁজ — কেবল OCR-এ আছে, নাম বা বিবরণে নয়
        $found = (string) $this->actingAs($this->owner)->get(route('documents.search', ['content' => 'cartons']))->assertOk()->getContent();
        $this->assertStringContainsString(route('documents.show', $document), $found, 'OCR-এর লেখা দিয়ে খোঁজে কাগজ এল না।');

        // ⛔ অন্য শাখার মানুষ লেখা দিয়েও কাগজটা পান না
        $this->useCompany();
        $far = $this->person('ntk-ocr@abos.test', ['documents.view'], limitedTo: $this->netrakona);
        $hidden = (string) $this->actingAs($far)->get(route('documents.search', ['content' => 'cartons']))->assertOk()->getContent();
        $this->assertStringNotContainsString(route('documents.show', $document), $hidden, 'অন্য শাখার মানুষ OCR-এর লেখায় কাগজ পেলেন।');
    }

    public function test_a_page_that_is_not_a_picture_is_refused(): void
    {
        $this->actingAs($this->owner)->post(route('documents.scan.store'), [
            'doc_type' => 'invoice', 'folder' => 'purchase', 'branch_id' => $this->main->id,
            'confidentiality' => DocumentCatalog::INTERNAL,
            'pages' => [UploadedFile::fake()->createWithContent('page.png', '<html>not a picture</html>')],
        ])->assertSessionHasErrors('pages.0');
        $this->useCompany();

        $this->assertSame(0, Document::query()->count());
    }

    public function test_the_fields_are_found_by_rules_in_both_scripts_and_the_party_by_its_known_name(): void
    {
        Supplier::query()->create(['code' => 'SUP-PAD', 'name_en' => 'Padma Packaging Ltd', 'name_bn' => 'পদ্মা প্যাকেজিং', 'is_active' => true]);

        $fields = $this->actingAs($this->owner)->postJson(route('documents.ocr.fields'), ['text' => self::TEXT])->assertOk()->json();

        $this->assertSame('INV-778/26', $fields['invoice_no']);
        $this->assertSame('2026-10-05', $fields['date'], 'দিন/মাস/বছর ঠিক পড়া হয়নি।');
        $this->assertSame('12500.00', $fields['amount'], 'বাংলা অঙ্কের মোট পড়া হয়নি।');
        // ⓘ লেখায় ইংরেজি নাম, ফল পর্দার ভাষায় — দুইটার যেকোনোটা
        $this->assertContains($fields['party'], ['Padma Packaging Ltd', 'পদ্মা প্যাকেজিং'], 'চেনা সরবরাহকারী মেলেনি।');

        $bangla = $this->actingAs($this->owner)->postJson(route('documents.ocr.fields'), [
            'text' => "চালান নং: ৪৫৬-ক\nতারিখ: ১৫/০৯/২০২৬\nসর্বমোট ৭,২৫০ টাকা",
        ])->assertOk()->json();
        $this->assertSame('2026-09-15', $bangla['date']);
        $this->assertSame('7250.00', $bangla['amount']);
        $this->assertSame('456', substr((string) $bangla['invoice_no'], 0, 3));
    }

    /**
     * ⛔ যে পক্ষ দেখার অধিকার নেই, তার নাম প্রস্তাবে আসে না (১১ অক্টোবর ২০২৬, documents রিভিউ ⚠️৮; [[DocumentFieldExtractor::party()]])।
     * ⓘ আগে নামের তালিকা পেস্ট করে জানা যেত দেয়ালের বাইরে কোন ডিলার বা সরবরাহকারী আছে।
     */
    public function test_a_party_outside_the_readers_reach_is_not_named(): void
    {
        Supplier::query()->create(['code' => 'SUP-PAD', 'name_en' => 'Padma Packaging Ltd', 'name_bn' => 'পদ্মা প্যাকেজিং', 'is_active' => true]);

        $owner = $this->actingAs($this->owner)->postJson(route('documents.ocr.fields'), ['text' => self::TEXT])->assertOk()->json();
        $this->assertNotNull($owner['party'], 'ⓘ মালিকের কাছেও নাম আসেনি — দাবিটা কিছু মাপছে না।');
        $this->useCompany();

        $outsider = $this->person('ocr-outsider@abos.test', ['documents.view']);
        $fields = $this->actingAs($outsider)->postJson(route('documents.ocr.fields'), ['text' => self::TEXT])->assertOk()->json();
        $this->assertNull($fields['party'], '⛔ সরবরাহকারী দেখার চাবি ছাড়াই তাঁর নাম মিলিয়ে ফেরত এল।');
        $this->assertSame('12500.00', $fields['amount'], 'বাকি ঘর আগের মতোই প্রস্তাব হয়।');
    }

    public function test_read_text_on_an_existing_picture_respects_the_walls_and_the_edit_key(): void
    {
        $document = $this->upload('Shop sign photo', file: UploadedFile::fake()->image('sign.png', 200, 200));
        $version = DocumentVersion::query()->findOrFail($document->current_version_id);

        $page = (string) $this->actingAs($this->owner)->get(route('documents.show', $document))->assertOk()->getContent();
        $this->assertStringContainsString('data-image-url="'.route('documents.preview', $document).'"', $page, 'ছবির কাগজে "লেখা পড়ুন" নেই।');
        $this->useCompany();

        $far = $this->person('ntk-ocr2@abos.test', ['documents.view', 'documents.edit'], limitedTo: $this->netrakona);
        $this->assertContains($this->actingAs($far)->post(route('documents.version.ocr', [$document, $version]), ['ocr_text' => 'x'])->getStatusCode(), [403, 404]);
        $this->useCompany();

        $reader = $this->person('ocr-reader@abos.test', ['documents.view']);
        $this->actingAs($reader)->post(route('documents.version.ocr', [$document, $version]), ['ocr_text' => 'x'])->assertForbidden();
        $this->useCompany();

        $this->actingAs($this->owner)->post(route('documents.version.ocr', [$document, $version]), [
            'ocr_text' => 'OPEN 9 TO 9', 'ocr_fields' => ['invoice_no' => ''],
        ])->assertSessionHasNoErrors();
        $this->useCompany();
        $this->assertSame('OPEN 9 TO 9', DocumentOcr::query()->where('version_id', $version->id)->value('text'));
    }

    /**
     * ⛔ মালিকের প্রথম বাঁধন — tesseract.js-এর চারটা জিনিস আমাদের সার্ভারে আছে, আর স্ক্যানের পাতা কেবল নিজের
     * ঠিকানা থেকে স্ক্রিপ্ট নেয়।
     */
    public function test_every_ocr_file_is_served_from_our_own_server(): void
    {
        foreach (['tesseract.min.js', 'worker.min.js', 'core/tesseract-core-lstm.wasm.js', 'core/tesseract-core-simd-lstm.wasm.js',
            'lang/ben.traineddata.gz', 'lang/eng.traineddata.gz'] as $file) {
            $this->assertFileExists(public_path('vendor/tesseract/'.$file), $file.' নিজের সার্ভারে নেই।');
        }

        $page = (string) $this->actingAs($this->owner)->get(route('documents.scan'))->assertOk()->getContent();
        $base = rtrim(url('/'), '/');

        $this->assertStringContainsString('src="'.$base.'/vendor/tesseract/tesseract.min.js"', $page);
        $this->assertStringContainsString('data-ocr-base="'.$base.'/vendor/tesseract"', $page);

        preg_match_all('/<script[^>]+src="([^"]+)"/i', $page, $scripts);
        foreach ($scripts[1] as $src) {
            $this->assertStringStartsWith($base, $src, 'স্ক্যানের পাতা বাইরের ঠিকানা থেকে স্ক্রিপ্ট নেয়: '.$src);
        }

        $this->assertFalse(str_contains(File::get(resource_path('js/document-scan.js')), 'cdn.'), 'স্ক্যানের জাভাস্ক্রিপ্টে CDN-এর ঠিকানা।');
    }
}
