<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Documents;

use App\Modules\Documents\Support\DocumentCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * ⛔ বিশ্লেষণের পাতা সেই লেখাই দেখায় যা আপনি পড়তে পারেন — Documents লাইন-ধরে নিরীক্ষা, ১৪ অক্টোবর ২০২৬
 * ([[DocumentIntelligenceController::workbench()]])।
 *
 * ⓘ txt/csv পাতায় খোলে না, তাই নামানোর চাবি ছাড়া প্রিভিউ ৪০৩ ([[APreviewIsNotADownloadTest]])। কিন্তু বিশ্লেষণের পাতার সারাংশ, প্রশ্ন আর
 * তুলনা পুরো লেখা ছাপত — কেবল-দেখার মানুষ বেতনের CSV গোটাটাই পড়ে ফেলতেন।
 */
final class TheAnalysisPageReadsOnlyWhatYouMayTest extends TestCase
{
    use MakesDocuments;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDocuments();
    }

    public function test_a_viewer_without_the_download_key_does_not_read_a_text_file_on_the_analysis_page(): void
    {
        $text = $this->upload('Salary sheet', DocumentCatalog::INTERNAL, UploadedFile::fake()->createWithContent('pay.txt', "SECRET-SALARY-LINE Rahim was paid 85000 taka this month\n"));

        $viewer = $this->person('viewer@abos.test', ['documents.view']);
        $this->actingAs($viewer)->get(route('documents.preview', $text))->assertForbidden();
        $this->useCompany();

        $this->actingAs($viewer)->get(route('documents.intelligence', ['document' => $text->id, 'tool' => 'summarize']))
            ->assertOk()->assertDontSee('SECRET-SALARY-LINE');
        $this->useCompany();

        // ⓘ নামানোর চাবি থাকলে আগের মতোই
        $downloader = $this->person('downloader@abos.test', ['documents.view', 'documents.download']);
        $this->actingAs($downloader)->get(route('documents.intelligence', ['document' => $text->id, 'tool' => 'summarize']))
            ->assertOk()->assertSee('SECRET-SALARY-LINE');
    }
}
