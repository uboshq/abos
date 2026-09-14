<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Attachment\AttachmentEngine;
use App\Core\Engines\Attachment\AttachmentException;
use App\Core\Engines\Image\ImageEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * ⛔ বিলের ছবি যেমন আসত তেমনই জমা হত — ১৪ সেপ্টেম্বর ২০২৬।
 *
 * ── কী ভাঙা ছিল ──────────────────────────────────────────────────────
 * মালিক বললেন: *"যেকোনো ফটো আপলোডের সময় নিজে থেকে ক্রপ করে নেওয়ার
 * ব্যবস্থা করার কথা ছিল সেটা হয় নাই"*, আর *"নিজেই সাইজ করে নিতে হবে,
 * কত কেবি/এমবি হবে নিজের বানিয়ে নিতে হবে"*।
 *
 * ⓘ কথাটা আক্ষরিক অর্থেই সত্যি ছিল: [[AvatarService]] প্রোফাইল ছবিতে
 * ঘোরানো-কাটা-চাপা করত, কিন্তু [[AttachmentEngine]]-এ একটাই লাইন ছিল —
 * `$file->storeAs(...)`। ⛔ অর্থাৎ ফোনে তোলা রসিদ **পাশ ফিরে, ছয়
 * মেগাবাইট**, কোনো প্রক্রিয়া ছাড়া ডিস্কে বসত।
 *
 * ── ⚠️ এই ফাইলটা কেন দরকার ───────────────────────────────────────────
 * এখানকার ভুল কোনো ত্রুটিবার্তা দেয় না। কাগজ জমা হয়, পর্দায় দেখায়ও —
 * শুধু ডিস্ক ভরতে থাকে আর ডিপোর নেটে সংযুক্তির পাতা খুলতে সেকেন্ড লাগে।
 * ⓘ যেদিন ধরা পড়ে সেদিন হাজারটা ফাইল জমে গেছে।
 *
 * ⭐ তাই প্রতিটা দাবি **সংখ্যা মেপে** লেখা — "কিছু একটা হয়েছে" নয়।
 */
class ThePhotoWentInSidewaysAndEightMegabytesTest extends TestCase
{
    use RefreshDatabase;

    private AttachmentEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->engine = new AttachmentEngine('local');

        CompanyContext::set(Company::create(['code' => 'PH', 'name_en' => 'Photo Co'])->id);
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    /**
     * একটা সত্যিকারের বড় ছবি — GD দিয়ে আঁকা, কারণ
     * `UploadedFile::fake()->image()` বিন্দুর মাপ দেয় কিন্তু ভিতরটা
     * প্রায় ফাঁকা রাখে।
     */
    private function photo(string $name = 'rosid.jpg', int $width = 2400, int $height = 3200): UploadedFile
    {
        $image = imagecreatetruecolor($width, $height);

        imagefill($image, 0, 0, imagecolorallocate($image, 118, 120, 124));
        imagefilledrectangle($image, 200, 200, $width - 200, $height - 200, imagecolorallocate($image, 246, 244, 240));

        // ⓘ লেখার মতো দাগ — নাহলে JPEG একটা এক রঙা ছবিকে কয়েক KB-তে
        // নামিয়ে দিত, আর "চেপে ছোট করা হয়েছে" দাবিটা অর্থহীন হত।
        $ink = imagecolorallocate($image, 28, 28, 30);

        for ($i = 0; $i < 60; $i++) {
            imagefilledrectangle($image, 320, 360 + $i * 45, $width - random_int(250, 900), 372 + $i * 45, $ink);
        }

        $path = tempnam(sys_get_temp_dir(), 'abos').'.jpg';
        imagejpeg($image, $path, 98);

        return new UploadedFile($path, $name, 'image/jpeg', null, true);
    }

    public function test_a_photo_of_a_bill_is_straightened_shrunk_and_squeezed_before_it_touches_the_disk(): void
    {
        $file = $this->photo();
        $before = (int) $file->getSize();

        $attachment = $this->engine->store($file, 'purchase', 'Bill', 7, maxBytes: 12 * 1024 * 1024);

        $stored = Storage::disk('local')->get($attachment->stored_path);
        $size = getimagesizefromstring($stored);

        // ⭐ আসল দাবি: ডিস্কে যা বসল তা বাজেটের ভিতরে
        $this->assertLessThanOrEqual(
            ImageEngine::PAPER_BYTES,
            strlen($stored),
            'ছবিটা বাজেটের চেয়ে বড় হয়ে ডিস্কে বসেছে।',
        );

        // ⚠️ এটাই সেই দাবি যেটা ভাঙা কোডে লাল হত: আগে ফাইলটা অপরিবর্তিত বসত
        $this->assertLessThan($before, strlen($stored), 'ছবিটা মোটেই ছোট হয়নি।');

        $this->assertLessThanOrEqual(ImageEngine::PAPER_EDGE, max($size[0], $size[1]));

        // ফলাফল সবসময় JPEG, আর সারিতে সেটাই লেখা থাকে — নাম দেখে নয়
        $this->assertSame('jpg', $attachment->extension);
        $this->assertSame('image/jpeg', $attachment->mime_type);
        $this->assertSame(strlen($stored), (int) $attachment->size_bytes);
    }

    public function test_the_name_the_person_gave_survives_even_though_the_file_did_not(): void
    {
        $attachment = $this->engine->store($this->photo('IMG_20260914.jpg'), 'purchase', 'Bill', 7);

        // ⓘ তালিকায় মানুষ নিজের ফাইলটা চিনতে চান; বদলে যায় কেবল যা সত্যিই বদলেছে
        $this->assertSame('IMG_20260914.jpg', $attachment->original_name);
        $this->assertStringEndsWith('.jpg', $attachment->stored_path);
    }

    public function test_a_contract_pdf_is_left_exactly_as_it_came(): void
    {
        $file = UploadedFile::fake()->create('chukti.pdf', 120, 'application/pdf');

        $attachment = $this->engine->store($file, 'customer', 'Customer', 1);

        /*
         * ⛔ একটা চুক্তিপত্রের PDF "উন্নত" করতে যাওয়া মানে সেটা নষ্ট করা।
         * ⚠️ দাবিটা এখানে না থাকলে একদিন কেউ `keep()`-এ PDF যোগ করে
         * ফেলত আর পাতাগুলো হারিয়ে যেত।
         */
        $this->assertSame('pdf', $attachment->extension);
        $this->assertSame(120 * 1024, (int) $attachment->size_bytes);
    }

    public function test_a_photo_that_could_not_be_processed_is_refused_rather_than_quietly_kept_at_full_size(): void
    {
        /*
         * ⓘ এই ফাঁকটা [[abos-68]] ধরেছে, ব্যবহারকারী নয়।
         *
         * ⚠️ দরজার সীমা ১২ MB করা হয়েছিল এই ভরসায় যে ইঞ্জিন ছোট করে
         * নেবে। ⛔ ইঞ্জিন ব্যর্থ হলে ভরসাটা মিথ্যা — আর তখন নীরবে
         * ১২ MB ডিস্কে বসত।
         *
         * ভাঙা ছবি বানানো হয়: শুরুটা JPEG-এর, বাকিটা আবর্জনা। অর্থাৎ
         * `getimagesize()` "এটা ছবি" বলে, কিন্তু GD খুলতে পারে না।
         */
        $path = tempnam(sys_get_temp_dir(), 'abos').'.jpg';

        $head = imagecreatetruecolor(3000, 3000);
        imagejpeg($head, $path, 100);
        file_put_contents($path, substr((string) file_get_contents($path), 0, 400).random_bytes(3 * 1024 * 1024), FILE_APPEND);

        $broken = new UploadedFile($path, 'bhanga.jpg', 'image/jpeg', null, true);

        $this->expectException(AttachmentException::class);

        $this->engine->store($broken, 'purchase', 'Bill', 9, maxBytes: 12 * 1024 * 1024);
    }

    public function test_a_small_photo_that_could_not_be_processed_is_still_kept_because_losing_the_paper_is_worse(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'abos').'.jpg';

        $head = imagecreatetruecolor(40, 40);
        imagejpeg($head, $path, 100);
        file_put_contents($path, substr((string) file_get_contents($path), 0, 300).random_bytes(2048), FILE_APPEND);

        $small = new UploadedFile($path, 'choto.jpg', 'image/jpeg', null, true);

        $attachment = $this->engine->store($small, 'purchase', 'Bill', 10);

        // ⭐ ছোট হলে রেখে দেওয়াই সঠিক — ব্যবহারকারীর কাগজ হারানোর চেয়ে ভালো
        Storage::disk('local')->assertExists($attachment->stored_path);
    }

    public function test_a_logo_keeps_its_transparency_because_a_logo_is_not_a_page(): void
    {
        $logo = imagecreatetruecolor(1800, 1200);
        imagealphablending($logo, false);
        imagesavealpha($logo, true);
        imagefilledrectangle($logo, 0, 0, 1800, 1200, imagecolorallocatealpha($logo, 0, 0, 0, 127));
        imagealphablending($logo, true);
        imagefilledellipse($logo, 900, 600, 900, 900, imagecolorallocate($logo, 214, 40, 40));

        $path = tempnam(sys_get_temp_dir(), 'abos').'.png';
        imagepng($logo, $path, 6);

        $mark = (new ImageEngine)->mark($path);

        $this->assertSame('png', $mark['extension'], 'লোগোটা JPEG হয়ে গেছে — স্বচ্ছতা মারা পড়ত।');

        $out = imagecreatefromstring($mark['bytes']);
        $corner = (imagecolorat($out, 0, 0) >> 24) & 0x7F;

        // ১২৭ মানে পুরো স্বচ্ছ; ০ মানে ভরাট
        $this->assertGreaterThan(100, $corner, 'লোগোর কোণা ভরাট হয়ে গেছে।');

        $this->assertLessThanOrEqual(ImageEngine::MARK_EDGE, max(imagesx($out), imagesy($out)));
    }

    public function test_a_file_with_more_pixels_than_memory_is_never_opened(): void
    {
        /*
         * ⛔ মেমরি শেষ হওয়া একটা fatal error, ব্যতিক্রম নয় — `catch`
         * ওটা ধরতে পারত না, আর অনুরোধটাই মরে যেত।
         *
         * ⓘ তাই হিসাবটা আগে: `reads()` "না" বলে, GD-কে ডাকাই হয় না।
         */
        $engine = new ImageEngine;

        $reflected = new \ReflectionMethod($engine, 'fitsInMemory');

        $this->assertTrue($reflected->invoke($engine, 3000, 4000), 'ফোনের সাধারণ ছবিও আটকে যাচ্ছে।');
        $this->assertFalse($reflected->invoke($engine, 30000, 30000), '৯০ কোটি বিন্দুর ছবি খোলার চেষ্টা করা হচ্ছে।');
    }
}
