<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Documents;

use App\Models\AuditTrail;
use App\Modules\Documents\Models\AbeRule;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\DocumentVersion;
use App\Modules\Documents\Services\DocumentIntelligence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Document Intelligence (ABE) — নিয়ম আর প্যাটার্নে, নিজের সার্ভারে, প্রতিটা ফলের কারণসহ
 * (পরিকল্পনা §৮, §২০; ষষ্ঠ ধাপ)।
 *
 * ⓘ লেখা আসে সাদা লেখার ফাইল থেকে — OCR-এর লেখাও একই পথে আসে ([[DocumentIntelligence::textOf()]])।
 */
final class TheIntelligenceIsRulesOnOurOwnServerTest extends TestCase
{
    use MakesDocuments;
    use RefreshDatabase;

    private const INVOICE = "Padma Packaging Ltd\nTax Invoice No: INV-55\nDate: 01/10/2026\nItem: cartons qty 500 unit price 25\nVAT 15%\nGrand Total: 14,375.00\nThank you for your business";

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDocuments();
    }

    public function test_classify_says_why_and_its_suggestion_can_be_applied(): void
    {
        $document = $this->textDocument('Scanned paper 1', self::INVOICE, ['doc_type' => 'other', 'folder' => 'company']);

        $page = $this->tool($document, 'classify');
        $this->assertStringContainsString('data-abe-type="invoice"', $page, 'বিলের লেখায় "বিল" শ্রেণি এল না।');
        $this->assertStringContainsString('invoice', $page, 'কেন — মিলের শব্দ নেই।');
        $this->assertStringContainsString(__('documents::message.abe_promise'), $page, 'পর্দায় "বাইরে যায় না" কথাটা নেই।');

        $this->actingAs($this->owner)->post(route('documents.classify', $document), ['doc_type' => 'invoice', 'folder' => 'purchase'])
            ->assertRedirect();
        $this->useCompany();

        $fresh = $document->fresh();
        $this->assertSame(['invoice', 'purchase'], [$fresh->doc_type, $fresh->folder]);
        $this->assertTrue(AuditTrail::query()->forRecord(Document::class, $document->id)->where('action', 'document_abe_used')->exists());
    }

    public function test_company_rules_classify_and_extract_and_a_broken_pattern_is_refused(): void
    {
        $this->actingAs($this->owner)->post(route('documents.admin.store', 'classify'), [
            'doc_type' => 'license', 'keywords' => 'boiler permit, inspector of boilers', 'weight' => 10,
        ])->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->post(route('documents.admin.store', 'extract'), [
            'doc_type' => 'license', 'label' => 'Permit number', 'pattern' => 'Permit No[:\s]*([A-Z0-9-]+)',
        ])->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->post(route('documents.admin.store', 'extract'), [
            'doc_type' => 'license', 'label' => 'Broken', 'pattern' => '([unclosed',
        ])->assertSessionHasErrors('pattern');
        $this->useCompany();
        $this->assertSame(2, AbeRule::query()->count());

        $document = $this->textDocument('Boiler paper', "Office of the Inspector of Boilers\nBoiler Permit No: BP-2026-77\nValid until 31/12/2027", ['doc_type' => 'license']);

        $classified = $this->tool($document, 'classify');
        $this->assertStringContainsString('data-abe-type="license"', $classified);
        // ⓘ মূল শব্দেও "লাইসেন্স" আসে — তাই দাবি কোম্পানির নিজের শব্দে, যা কেবল তার নিয়মেই মেলে
        $this->assertStringContainsString('inspector of boilers', $classified, 'কোম্পানির শ্রেণির নিয়ম গোনায় আসেনি।');
        $extracted = $this->tool($document, 'extract');
        $this->assertStringContainsString('BP-2026-77', $extracted, 'কোম্পানির প্যাটার্নে তথ্য এল না।');
    }

    public function test_the_summary_is_lines_taken_from_the_paper_and_says_so(): void
    {
        $document = $this->textDocument('Invoice text', self::INVOICE);

        $page = $this->tool($document, 'summarize');
        $this->assertStringContainsString(__('documents::message.abe_extractive'), $page);

        preg_match_all('/<li data-abe-line>(.*?)<\/li>/s', $page, $m);
        $this->assertNotEmpty($m[1], 'সারাংশে কোনো লাইন নেই।');

        foreach ($m[1] as $line) {
            $this->assertStringContainsString(html_entity_decode(trim($line)), self::INVOICE, 'সারাংশে এমন লাইন যা কাগজে নেই।');
        }

        $this->assertStringContainsString('Grand Total', $page, 'মোটের লাইন সারাংশে আসেনি।');
    }

    public function test_compare_shows_changed_lines_and_the_changed_hash(): void
    {
        $document = $this->textDocument('Terms', "Clause 1: pay in 30 days\nClause 2: delivery free");
        $first = (int) $document->current_version_id;

        $this->actingAs($this->owner)->post(route('documents.version.store', $document), [
            'file' => UploadedFile::fake()->createWithContent('terms.txt', "Clause 1: pay in 45 days\nClause 2: delivery free"),
        ])->assertSessionHasNoErrors();
        $this->useCompany();

        $page = (string) $this->actingAs($this->owner)->get(route('documents.intelligence', [
            'document' => $document->id, 'tool' => 'compare', 'from' => $first, 'to' => $document->fresh()->current_version_id,
        ]))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/data-diff="del"[^>]*>− Clause 1: pay in 30 days/u', $page);
        $this->assertMatchesRegularExpression('/data-diff="add"[^>]*>\+ Clause 1: pay in 45 days/u', $page);
        $this->assertStringContainsString('data-abe-meta="changed"', $page, 'হ্যাশ বদল তুলনায় দেখা যায় না।');
    }

    public function test_asking_marks_the_hits_and_never_lets_the_paper_inject_markup(): void
    {
        // ⓘ <script> লেখা ফাইল ফাইলের দরজাতেই HTML হিসেবে ফেরে; এখানে সাদা লেখার ভিতরের < > &
        $document = $this->textDocument('Letter', "Subject: delivery delay\nIf qty a<b & c>d then delivery tomorrow\nRegards");

        $page = (string) $this->actingAs($this->owner)->get(route('documents.intelligence', [
            'document' => $document->id, 'tool' => 'ask', 'ask' => 'delivery',
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('<mark>delivery</mark>', $page);
        $this->assertStringNotContainsString('a<b & c>d', $page, 'কাগজের লেখা পাতায় কাঁচা চিহ্ন হয়ে বসল।');
        $this->assertStringContainsString('a&lt;b &amp; c&gt;d', $page);
    }

    public function test_the_glossary_is_only_field_names_and_says_so(): void
    {
        $document = $this->textDocument('Invoice text', self::INVOICE);
        $page = $this->tool($document, 'translate');

        $this->assertStringContainsString(__('documents::message.abe_glossary_only'), $page);
        $this->assertStringContainsString('সর্বমোট', $page, 'শব্দকোষে "grand total" নেই।');
    }

    public function test_intelligence_stays_behind_the_walls(): void
    {
        $document = $this->textDocument('Branch secret text', self::INVOICE);
        $far = $this->person('ntk-abe@abos.test', ['documents.view'], limitedTo: $this->netrakona);

        $this->actingAs($far)->get(route('documents.intelligence', ['document' => $document->id]))->assertNotFound();
        $picker = (string) $this->actingAs($far)->get(route('documents.intelligence'))->assertOk()->getContent();
        $this->assertStringNotContainsString('Branch secret text', $picker);
    }

    public function test_the_engine_never_names_an_outside_model_or_host(): void
    {
        $source = file_get_contents((new \ReflectionClass(DocumentIntelligence::class))->getFileName());

        foreach (['openai', 'anthropic', 'gemini', 'huggingface', 'Http::', 'curl_'] as $needle) {
            $this->assertStringNotContainsStringIgnoringCase($needle, (string) $source);
        }
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    /** @param array<string, mixed> $extra */
    private function textDocument(string $name, string $text, array $extra = []): Document
    {
        $document = $this->upload($name, file: UploadedFile::fake()->createWithContent('paper.txt', $text), extra: $extra);
        $this->assertNotNull(DocumentVersion::query()->find($document->current_version_id));

        return $document;
    }

    private function tool(Document $document, string $tool): string
    {
        $html = (string) $this->actingAs($this->owner)
            ->get(route('documents.intelligence', ['document' => $document->id, 'tool' => $tool]))
            ->assertOk()->getContent();
        $this->useCompany();

        return $html;
    }
}
