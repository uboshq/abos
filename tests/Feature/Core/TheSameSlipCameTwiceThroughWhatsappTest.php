<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Attachment\AttachmentEngine;
use App\Core\Engines\Image\ImageEngine;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * ⭐ স্লিপ KB-তে, আর একই স্লিপ দুবার এলে চেনা — মালিক, ১৬ নভেম্বর ২০২৬।
 *
 * *"ছবিগুলোকে কেবি সাইজে নিয়ে আসা, স্পষ্ট করা … রিসাইজ করে এটাকে ছোটো করে ঢোকানো"* আর *"অনেকেই হোয়াটসঅ্যাপে … পাঠায় দেয় …
 * তারা আবার অ্যাপসে এন্ট্রি দেয়"*।
 *
 *   - স্লিপের দরজায় ([[AttachmentEngine::SLIP]]) ছবি সেটিংসের KB-র নিচে (ডিফল্ট ১০০), তবু লম্বা বাহু পড়ার মতো (≥ ১১০০px)
 *   - সেটিংস বদলালে সীমা বদলায়; সাধারণ কাগজ (বিল) আগের ৮০০ KB-তেই
 *   - হোয়াটসঅ্যাপে ছোট আর আবার-চাপা কপি "একই চেহারা" হিসেবে ধরা পড়ে; অন্য স্লিপ পড়ে না
 */
final class TheSameSlipCameTwiceThroughWhatsappTest extends TestCase
{
    use RefreshDatabase;

    private AttachmentEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->engine = new AttachmentEngine('local');
        CompanyContext::set(Company::create(['code' => 'SL', 'name_en' => 'Slip Co'])->id);
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    /**
     * একটা ভারী স্লিপের ছবি — দানাদার কাগজ আর অনেক লেখা, যাতে মান ৪০-এও ১০০ KB-তে না ঢোকে (বাহু ছোট করার পথ চলে)।
     * ⓘ `$layout` বদলালে আলাদা স্লিপ — লেখার সারি অন্য জায়গায়। `$grain` কম = সাধারণ স্লিপ (কম দানা)।
     */
    private function slipImage(int $layout = 1, int $width = 2400, int $height = 3200, int $grain = 9000, int $lines = 40): \GdImage
    {
        mt_srand(4242 + $layout);
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 236, 232, 222));

        for ($i = 0; $i < $grain; $i++) {
            $g = mt_rand(200, 250);
            $x = mt_rand(0, $width - 8);
            $y = mt_rand(0, $height - 8);
            imagefilledrectangle($image, $x, $y, $x + mt_rand(2, 7), $y + mt_rand(2, 7), imagecolorallocate($image, $g, $g - 4, $g - 10));
        }

        $ink = imagecolorallocate($image, 20, 30, 90);

        for ($i = 0; $i < $lines; $i++) {
            $top = ($layout === 1 ? 300 : 1400) + $i * ($layout === 1 ? 60 : 38);
            imagefilledrectangle($image, 260, $top, $width - mt_rand(300, 1400), $top + 14, $ink);
        }

        // ⓘ দ্বিতীয় নকশায় বড় একটা সিল — চেহারাটা সত্যিই আলাদা
        if ($layout !== 1) {
            imagefilledellipse($image, 600, 600, 700, 700, imagecolorallocate($image, 170, 30, 40));
        }

        return $image;
    }

    private function file(\GdImage $image, string $name, int $quality = 95): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'slip').'.jpg';
        imagejpeg($image, $path, $quality);

        return new UploadedFile($path, $name, 'image/jpeg', null, true);
    }

    /** হোয়াটসঅ্যাপের মতো: লম্বা বাহু ১২৮০, মান ৬০ */
    private function whatsappCopy(\GdImage $image): \GdImage
    {
        return imagescale($image, (int) round(imagesx($image) * 1280 / imagesy($image)), 1280);
    }

    public function test_a_slip_lands_under_its_kb_and_stays_readable(): void
    {
        $slip = $this->engine->store($this->file($this->slipImage(), 'slip.jpg'), 'sales', 'DepositClaim', 1,
            maxBytes: AttachmentEngine::SLIP_MAX_BYTES, only: AttachmentEngine::SLIP);
        $bytes = Storage::disk('local')->get($slip->stored_path);
        [$w, $h] = getimagesizefromstring($bytes);

        $this->assertLessThanOrEqual(100 * 1024, strlen($bytes), '⛔ স্লিপ ১০০ KB-র বেশি জমা হয়েছে');
        $this->assertGreaterThanOrEqual(ImageEngine::READABLE_EDGE, max($w, $h), '⛔ স্লিপ এত ছোট হয়েছে যে লেখা পড়া যায় না');
        $this->assertLessThan(ImageEngine::PAPER_EDGE, max($w, $h), 'ⓘ মান কমিয়ে না ঢোকায় বাহু ছোট করার পথ চলেছে');
        $this->assertSame(16, strlen((string) $slip->fingerprint), '⛔ স্লিপের চেহারার ছাপ নেই');
    }

    /** ⓘ পড়ার মাপ বাজেটের চেয়ে বড় কথা: খুব দানাদার ছবি ২০ KB-তে না নামলেও লম্বা বাহু ১১০০-র নিচে নামে না */
    public function test_a_tiny_budget_never_shrinks_a_slip_below_reading_size(): void
    {
        app(SettingsService::class)->set('system.image_kb.slip', 20);

        $slip = $this->engine->store($this->file($this->slipImage(), 'slip.jpg'), 'sales', 'DepositClaim', 1,
            maxBytes: AttachmentEngine::SLIP_MAX_BYTES, only: AttachmentEngine::SLIP);
        [$w, $h] = getimagesizefromstring(Storage::disk('local')->get($slip->stored_path));

        $this->assertGreaterThanOrEqual(ImageEngine::READABLE_EDGE, max($w, $h), '⛔ বাজেটের জন্য স্লিপ পড়ার অযোগ্য ছোট হয়েছে');
        $this->assertLessThan(ImageEngine::READABLE_EDGE / 0.85, max($w, $h), 'ⓘ তবু পড়ার মাপের ঠিক উপর পর্যন্ত নেমেছে');
    }

    public function test_the_setting_moves_the_slip_limit_but_a_bill_keeps_the_paper_budget(): void
    {
        app(SettingsService::class)->set('system.image_kb.slip', 60);

        // ⓘ সাধারণ স্লিপ — সাদা কাগজে ৮–১০ সারি লেখা, যেমন আসল ব্যাংক স্লিপ (দানাদার ৪০ সারির ছবি পড়ার মাপে ~৭০ KB-তে থামে)
        $slip = $this->engine->store($this->file($this->slipImage(grain: 0, lines: 10), 'slip.jpg'), 'sales', 'DepositClaim', 1,
            maxBytes: AttachmentEngine::SLIP_MAX_BYTES, only: AttachmentEngine::SLIP);
        $bill = $this->engine->store($this->file($this->slipImage(), 'bill.jpg'), 'purchase', 'Bill', 1, maxBytes: 12 * 1024 * 1024);

        $this->assertLessThanOrEqual(60 * 1024, $slip->size_bytes, '⛔ সেটিংসের ৬০ KB মানা হয়নি');
        $this->assertGreaterThan(100 * 1024, $bill->size_bytes, '⛔ বিলের ছবিও স্লিপের মতো ছোট হয়ে গেছে — বাজেট কেবল স্লিপের');
        $this->assertLessThanOrEqual(ImageEngine::PAPER_BYTES, $bill->size_bytes);
    }

    public function test_a_whatsapp_copy_of_the_same_slip_is_found_and_another_slip_is_not(): void
    {
        $original = $this->slipImage(1);
        $first = $this->engine->store($this->file($original, 'from-sr.jpg'), 'sales', 'DepositClaim', 1,
            maxBytes: AttachmentEngine::SLIP_MAX_BYTES, only: AttachmentEngine::SLIP);
        $other = $this->engine->store($this->file($this->slipImage(2), 'another.jpg'), 'sales', 'DepositClaim', 2,
            maxBytes: AttachmentEngine::SLIP_MAX_BYTES, only: AttachmentEngine::SLIP);
        $again = $this->engine->store($this->file($this->whatsappCopy($original), 'IMG-WA0001.jpg', 60), 'sales', 'DepositClaim', 3,
            maxBytes: AttachmentEngine::SLIP_MAX_BYTES, only: AttachmentEngine::SLIP);

        $this->assertNotSame($first->checksum, $again->checksum, 'ⓘ হোয়াটসঅ্যাপের কপির বাইট আলাদা — তাই হুবহু ছাপ অকেজো');

        $found = $this->engine->lookalikes($again)->pluck('id')->all();
        $this->assertContains($first->id, $found, '⛔ হোয়াটসঅ্যাপে আবার আসা একই স্লিপ চেনা যায়নি');
        $this->assertNotContains($other->id, $found, '⛔ অন্য স্লিপকে একই বলা হয়েছে');
        $this->assertSame([], $this->engine->lookalikes($other)->pluck('id')->all());
    }

    /** ⛔ স্লিপের ঘর ক্যামেরায় বাঁধা নয় — হোয়াটসঅ্যাপের ছবি গ্যালারি থেকে আসে; কর্মীর পাতায় ছবি বাছলেই স্ক্যানার */
    public function test_the_slip_field_takes_a_gallery_photo_and_opens_the_scanner(): void
    {
        $staff = (string) file_get_contents(base_path('app/Modules/Sales/Resources/views/claim/request.blade.php'));
        $portal = (string) file_get_contents(base_path('app/Modules/Sales/Resources/views/portal/claim.blade.php'));

        foreach (['জমার খবরের পাতা' => $staff, 'পোর্টালের পাতা' => $portal] as $page => $html) {
            $this->assertDoesNotMatchRegularExpression('/<input[^>]*name="slip"[^>]*capture=/s', $html, "⛔ {$page}: স্লিপের ঘর কেবল ক্যামেরা খোলে");
        }

        $this->assertMatchesRegularExpression('/<(?:input|x-ui\.[\w-]+)[^>]*name="slip"[^>]*\$store\.scanner\.begin\(\$el, \'paper\'\)/s', $staff,
            '⛔ জমার খবরের স্লিপ স্ক্যানারে যায় না — কোণা, সোজা, পরিষ্কার হয় না');
    }

    public function test_the_distance_counts_differing_bits(): void
    {
        $this->assertSame(0, ImageEngine::distance('00ff00ff00ff00ff', '00ff00ff00ff00ff'));
        $this->assertSame(1, ImageEngine::distance('00ff00ff00ff00ff', '01ff00ff00ff00ff'));
        $this->assertSame(64, ImageEngine::distance('0000000000000000', 'ffffffffffffffff'));
        $this->assertSame(64, ImageEngine::distance('short', '0000000000000000'), 'ⓘ ভাঙা ছাপ কখনো "একই" নয়');
    }
}
