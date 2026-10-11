<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Documents;

use App\Models\Attachment;
use App\Models\AuditTrail;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\DocumentVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * প্রথম ধাপের বাকি দাবিগুলো — প্রতিটা কাজ অডিটে, অনেক ফাইল একসাথে, মেয়াদের ছাঁকনি,
 * ভার্সনের হ্যাশ, আর মডিউল কোনো বাইরের সার্ভারকে ডাকে না (পরিকল্পনা §৬, §৯, §১২, §১৮, §২১)।
 */
final class EveryDocumentActionIsWrittenDownTest extends TestCase
{
    use MakesDocuments;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDocuments();
    }

    public function test_preview_print_version_and_restore_each_write_their_own_row(): void
    {
        $document = $this->upload(file: $this->pdf('deed.pdf', 'first'));
        $first = DocumentVersion::query()->withoutGlobalScopes()->findOrFail($document->current_version_id);

        $this->actingAs($this->owner)->get(route('documents.preview', $document))->assertOk();
        $this->actingAs($this->owner)->get(route('documents.print', $document))->assertOk();
        $this->actingAs($this->owner)
            ->post(route('documents.version.store', $document), ['file' => $this->pdf('deed.pdf', 'second')])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->post(route('documents.version.restore', [$document, $first]))->assertRedirect();
        $this->useCompany();

        $actions = AuditTrail::query()->forRecord(Document::class, $document->id)->pluck('action')->all();

        foreach (['document_previewed', 'document_printed', 'document_version_added', 'doc_version_restored'] as $action) {
            $this->assertContains($action, $actions, $action.' অডিটে নেই।');
        }
    }

    public function test_many_files_at_once_make_many_documents_each_with_v1(): void
    {
        $this->actingAs($this->owner)
            ->post(route('documents.store'), [
                'name' => 'Batch',
                'doc_type' => 'certificate',
                'folder' => 'compliance',
                'branch_id' => $this->main->id,
                'confidentiality' => 'internal',
                'files' => [$this->pdf('a.pdf', 'a'), $this->pdf('b.pdf', 'b'), $this->pdf('c.pdf', 'c')],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('documents.mine'));
        $this->useCompany();

        $made = Document::query()->where('name', 'like', 'Batch — %')->get();
        $this->assertCount(3, $made, 'তিনটা ফাইলে তিনটা ডকুমেন্ট হয়নি।');
        $this->assertCount(3, $made->pluck('document_no')->unique(), 'নম্বর আলাদা হয়নি।');

        foreach ($made as $document) {
            $this->assertSame('1.0', $document->currentVersion->label());
        }
    }

    public function test_every_version_keeps_the_sha256_of_its_own_bytes(): void
    {
        $document = $this->upload(file: $this->pdf('hash.pdf', 'bytes-to-hash'));
        $version = DocumentVersion::query()->withoutGlobalScopes()->findOrFail($document->current_version_id);
        $file = Attachment::query()->withoutGlobalScopes()->findOrFail($version->attachment_id);

        $this->assertSame(64, strlen((string) $version->file_hash), 'ভার্সনে SHA-256 নেই।');
        $this->assertSame($file->checksum, $version->file_hash, 'ভার্সনের হ্যাশ ফাইলের হ্যাশের সাথে মেলে না।');
    }

    public function test_the_expiry_filter_finds_exactly_the_window(): void
    {
        Carbon::setTestNow('2026-10-09 10:00:00');

        $this->upload('Expired permit', extra: ['expiry_date' => '2026-10-01']);
        $this->upload('Week permit', extra: ['expiry_date' => '2026-10-14']);
        $this->upload('Month permit', extra: ['expiry_date' => '2026-11-01']);
        $this->upload('Quarter permit', extra: ['expiry_date' => '2026-12-20']);
        $this->upload('Far permit', extra: ['expiry_date' => '2027-06-01']);

        $expect = [
            'expired' => ['Expired permit'],
            '7' => ['Week permit'],
            '30' => ['Week permit', 'Month permit'],
            '90' => ['Week permit', 'Month permit', 'Quarter permit'],
        ];
        $all = ['Expired permit', 'Week permit', 'Month permit', 'Quarter permit', 'Far permit'];

        foreach ($expect as $window => $names) {
            $html = (string) $this->actingAs($this->owner)
                ->get(route('documents.index', ['expiry' => $window]))->assertOk()->getContent();

            foreach ($all as $name) {
                in_array($name, $names, true)
                    ? $this->assertStringContainsString($name, $html, $window.' ছাঁকনিতে '.$name.' নেই।')
                    : $this->assertStringNotContainsString($name, $html, $window.' ছাঁকনিতে '.$name.' এসেছে।');
            }
        }

        Carbon::setTestNow();
    }

    /**
     * ⛔ মালিকের প্রথম বাঁধন — কোনো কাগজ বাইরের সার্ভারে যায় না। মডিউলের কোনো ফাইল HTTP
     * ক্লায়েন্ট, curl, সকেট বা বাইরের ঠিকানা থেকে স্ক্রিপ্ট নামায় না।
     */
    public function test_the_module_never_calls_an_outside_host(): void
    {
        $forbidden = [
            'Illuminate\\Support\\Facades\\Http', 'Http::', 'GuzzleHttp', 'curl_init', 'fsockopen',
            'stream_socket_client', "file_get_contents('http", 'file_get_contents("http',
            'cdn.jsdelivr', 'unpkg.com', 'cdnjs.', 'googleapis.com', 'openai', 'anthropic.com',
        ];

        $hits = [];

        foreach (File::allFiles(app_path('Modules/Documents')) as $file) {
            $body = $file->getContents();

            foreach ($forbidden as $needle) {
                if (stripos($body, $needle) !== false) {
                    $hits[] = $file->getRelativePathname().' → '.$needle;
                }
            }
        }

        $this->assertSame([], $hits, "ডকুমেন্ট মডিউল বাইরের সার্ভার ছোঁয়:\n".implode("\n", $hits));
    }
}
