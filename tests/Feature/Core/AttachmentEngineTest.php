<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Attachment\AttachmentEngine;
use App\Core\Engines\Attachment\AttachmentException;
use App\Core\Support\CompanyContext;
use App\Models\Attachment;
use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * সংযুক্তি — সেকশন ২১।
 *
 * সবচেয়ে গুরুত্বপূর্ণ টেস্টগুলো নিরাপত্তার: বিপজ্জনক ফাইল ঠেকানো, নাম দিয়ে
 * পথ তৈরি না করা, আর অন্য কোম্পানির কাগজ না দেখা।
 */
class AttachmentEngineTest extends TestCase
{
    use RefreshDatabase;

    private AttachmentEngine $engine;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->engine = new AttachmentEngine('local');

        $this->company = Company::create(['code' => 'AT', 'name_en' => 'Attach Co']);
        CompanyContext::set($this->company->id);
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    public function test_a_file_is_stored_on_disk_and_only_its_details_go_to_the_database(): void
    {
        $file = UploadedFile::fake()->create('চুক্তিপত্র.pdf', 120, 'application/pdf');

        $attachment = $this->engine->store($file, 'customer', 'Customer', 42);

        Storage::disk('local')->assertExists($attachment->stored_path);

        $this->assertSame('চুক্তিপত্র.pdf', $attachment->original_name);
        $this->assertSame('pdf', $attachment->extension);
        $this->assertSame(42, (int) $attachment->source_entity_id);
        $this->assertNotEmpty($attachment->checksum);

        // ফাইলের বিষয়বস্তু ডাটাবেজে নেই — এটাই DMS থেকে মূল পার্থক্য।
        $this->assertArrayNotHasKey('file_data', $attachment->getAttributes());
    }

    public function test_the_stored_name_is_generated_so_a_crafted_filename_cannot_escape(): void
    {
        $file = UploadedFile::fake()->create('../../.env', 1, 'text/plain');

        $attachment = $this->engine->store($file, 'customer', 'Customer', 1);

        // পথটা প্রত্যাশিত ফোল্ডারের ভেতরেই, আর নামটা ব্যবহারকারীর দেওয়া নয়।
        $this->assertStringStartsWith("attachments/{$this->company->id}/customer/", $attachment->stored_path);
        $this->assertStringNotContainsString('..', $attachment->stored_path);
        $this->assertMatchesRegularExpression('/[0-9a-f-]{36}/', basename($attachment->stored_path));
    }

    /**
     * ⛔ যে ফাইলগুলো ব্রাউজারেই কোড চালায় — ২১ সেপ্টেম্বর ২০২৬।
     *
     * ── ⓘ কেন এগুলো আলাদা করে ─────────────────────────────────────
     * উপরের তালিকাটা "প্রোগ্রাম" ধরে বানানো ছিল, আর `.svg`/`.html` দেখতে
     * প্রোগ্রাম নয়। ⚠️ কিন্তু ভিতরে `<script>` বসে, আর ব্রাউজার সেটা
     * **ঐ সাইটেরই অংশ হিসেবে** চালায় — অর্থাৎ লগইন করা ব্যবহারকারীর হয়ে।
     *
     * ⓘ আজ ফাইল যায় `attachment` হিসেবে, তাই ব্রাউজার চালায় না। ⛔ কিন্তু
     * mime-টা ব্রাউজারের বলা, আর সেটাই ফেরত দেওয়া হয় — তাই কোনোদিন
     * "ভিতরেই দেখুন" পর্দা বসলেই ওটা সংরক্ষিত XSS হত।
     */
    public function test_files_that_run_in_the_browser_are_refused(): void
    {
        foreach (['logo.svg', 'report.html', 'page.htm', 'sheet.xsl'] as $name) {
            try {
                $this->engine->store(UploadedFile::fake()->create($name, 1), 'customer', 'Customer', 1);
                $this->fail("{$name} should not be accepted — it runs in the browser.");
            } catch (AttachmentException $e) {
                $this->assertStringContainsString('cannot be attached', $e->getMessage());
            }
        }

        $this->assertSame(0, Attachment::query()->count());
    }

    public function test_executable_types_are_refused(): void
    {
        foreach (['shell.php', 'setup.exe', 'run.bat', 'payload.phar'] as $name) {
            try {
                $this->engine->store(UploadedFile::fake()->create($name, 1), 'customer', 'Customer', 1);
                $this->fail("{$name} should not be accepted.");
            } catch (AttachmentException $e) {
                $this->assertStringContainsString('cannot be attached', $e->getMessage());
            }
        }

        $this->assertSame(0, Attachment::query()->count());
    }

    public function test_a_php_file_renamed_to_pdf_is_still_refused(): void
    {
        $this->expectException(AttachmentException::class);

        // এক্সটেনশন নিরীহ, MIME নয় — দুই দিকেই দেখতে হয়।
        $this->engine->store(
            UploadedFile::fake()->create('invoice.pdf', 1, 'application/x-httpd-php'),
            'customer',
            'Customer',
            1,
        );
    }

    public function test_a_file_over_the_limit_is_refused_with_a_readable_message(): void
    {
        try {
            $this->engine->store(
                UploadedFile::fake()->create('big.pdf', 11 * 1024),
                'customer',
                'Customer',
                1,
            );
            $this->fail('An oversized file should be refused.');
        } catch (AttachmentException $e) {
            $this->assertStringContainsString('the limit is', $e->getMessage());
            $this->assertStringContainsString('MB', $e->getMessage());
        }
    }

    public function test_attachments_are_listed_per_record(): void
    {
        $this->engine->store(UploadedFile::fake()->create('a.pdf', 1), 'customer', 'Customer', 1);
        $this->engine->store(UploadedFile::fake()->create('b.pdf', 1), 'customer', 'Customer', 1);
        $this->engine->store(UploadedFile::fake()->create('c.pdf', 1), 'customer', 'Customer', 2);

        $this->assertCount(2, $this->engine->listFor('customer', 'Customer', 1));
        $this->assertCount(1, $this->engine->listFor('customer', 'Customer', 2));
    }

    public function test_a_new_version_replaces_the_old_one_in_the_list_but_keeps_it_in_history(): void
    {
        $first = $this->engine->store(UploadedFile::fake()->create('contract.pdf', 1), 'customer', 'Customer', 1);
        $second = $this->engine->store(
            UploadedFile::fake()->create('contract-v2.pdf', 1),
            'customer',
            'Customer',
            1,
            replacesId: $first->id,
        );

        $this->assertSame(2, $second->version);

        $current = $this->engine->listFor('customer', 'Customer', 1);
        $this->assertCount(1, $current);
        $this->assertSame($second->id, $current->first()->id);

        // পুরনোটা রয়ে গেছে — "আগের চুক্তিতে কী লেখা ছিল" প্রশ্নের উত্তর থাকে।
        $this->assertSame(2, Attachment::query()->count());
    }

    public function test_deleting_keeps_the_file_on_disk(): void
    {
        $attachment = $this->engine->store(UploadedFile::fake()->create('deed.pdf', 1), 'customer', 'Customer', 1);
        $path = $attachment->stored_path;

        $this->engine->delete($attachment);

        $this->assertSoftDeleted($attachment);

        // ভুল করে মোছা একটা দলিল যেন ফেরানো যায়।
        Storage::disk('local')->assertExists($path);
    }

    public function test_another_companys_attachment_is_invisible(): void
    {
        $this->engine->store(UploadedFile::fake()->create('secret.pdf', 1), 'customer', 'Customer', 1);

        $other = Company::create(['code' => 'OT', 'name_en' => 'Other']);

        CompanyContext::forCompany($other->id, function () {
            $this->assertCount(0, $this->engine->listFor('customer', 'Customer', 1));
            $this->assertSame(0, Attachment::query()->count());
        });
    }

    /**
     * ⛔ ফিক্সচারটা মিথ্যা বলছিল, কোডটা নয় — ১৫ সেপ্টেম্বর ২০২৬।
     *
     * ── কী ছিল ───────────────────────────────────────────────────────
     *     UploadedFile::fake()->create('note.pdf', 2048)
     *
     * ⓘ `fake()->create()` **আকারটা বলে দেয়** (`getSize()` ২ MB ফেরায়),
     * কিন্তু ফাইলটায় একটাও বাইট লেখে না। ⚠️ তাই জমা হওয়ার পর ডিস্কে
     * ফাইলটা শূন্য, আর দাবিটা `'0 B'` পেত।
     *
     * ── ⭐ কেন ইঞ্জিনটাই ঠিক, আর পরীক্ষাটাই বদলাল ───────────────────
     * [[AttachmentEngine]] এখন **জমা হওয়া** ফাইলের আকার মাপে
     * (`Storage::size($path)`), আপলোড করা ফাইলের নয়। ⓘ আর সেটাই সঠিক:
     * ছবি জমা হওয়ার আগে ঘুরিয়ে, ছোট করে, চেপে নেওয়া হয় — তাই আপলোডের
     * আকারটা ব্যবহারকারীকে দেখালে **ভুল সংখ্যা** দেখানো হত, আর তিনি
     * ডাউনলোড করে অন্য আকার পেতেন।
     *
     * ⚠️ অর্থাৎ লাল দাবিটা একটা আসল উন্নতিকে ভুল প্রমাণ করছিল। তাই
     * ফিক্সচারে এখন **সত্যিকারের বাইট** — যা মাপা হচ্ছে তা যেন সত্যিই
     * ওখানে থাকে।
     */
    public function test_file_size_reads_the_way_a_person_expects(): void
    {
        $twoMegabytes = str_repeat('a', 2048 * 1024);

        $attachment = $this->engine->store(
            UploadedFile::fake()->createWithContent('note.pdf', $twoMegabytes),
            'customer',
            'Customer',
            1,
        );

        $this->assertSame('2 MB', $attachment->humanSize());
    }
}
