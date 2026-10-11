<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Documents;

use App\Modules\Documents\Support\DocumentCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * ⛔ প্রিভিউ নামানো নয় — নামানোর চাবি ছাড়া পাতার ভিতরে খোলে না এমন ফাইল প্রিভিউ বা ছাপার দরজায় আসে না
 * (১১ অক্টোবর ২০২৬, documents রিভিউ ⛔১; [[DocumentLibrary::stream()]])।
 *
 * ⓘ প্রিভিউ চাইত কেবল "দেখা"। docx, xlsx, txt পাতার ভিতরে খোলে না, তাই প্রিভিউ সেগুলো পুরো ফাইল হিসেবে নামিয়ে দিত — নামানোর
 * চাবি, কাগজের অধিকার আর শেয়ারের "নামানো না" টিক কিছুই আটকাত না। ⭐ PDF আর ছবি পাতার ভিতরে দেখা — "দেখা"-র মানেই সেটা।
 */
final class APreviewIsNotADownloadTest extends TestCase
{
    use MakesDocuments;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDocuments();
    }

    public function test_a_viewer_without_the_download_key_cannot_take_a_text_file_through_the_preview(): void
    {
        $text = $this->upload('Price list', DocumentCatalog::INTERNAL, UploadedFile::fake()->createWithContent('prices.txt', "Biscuit 40gm 12.50\n"));
        $pdf = $this->upload('Contract', DocumentCatalog::INTERNAL);

        $viewer = $this->person('viewer@abos.test', ['documents.view']);

        foreach (['documents.preview', 'documents.print'] as $door) {
            $this->actingAs($viewer)->get(route($door, $text))->assertForbidden();
            $this->useCompany();
        }
        $this->actingAs($viewer)->get(route('documents.download', $text))->assertForbidden();
        $this->useCompany();

        // ⭐ PDF পাতার ভিতরে — দেখা চলে, নামানোর চাবি ছাড়াই
        $this->actingAs($viewer)->get(route('documents.preview', $pdf))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->useCompany();

        // ⓘ নামানোর চাবি থাকলে লেখার ফাইলের প্রিভিউ আগের মতোই (নামানো হিসেবে) চলে
        $downloader = $this->person('downloader@abos.test', ['documents.view', 'documents.download']);
        $this->actingAs($downloader)->get(route('documents.preview', $text))->assertOk();
    }
}
