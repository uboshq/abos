<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\SystemAdmin;

use App\Core\Support\CompanyContext;
use App\Core\Support\UploadLimit;
use App\Models\AuditFieldChange;
use App\Models\AuditTrail;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * ⭐ কোম্পানির লোগো এখন SVG-ও — কিন্তু স্ক্রিপ্ট ছেঁটে (মালিক, fe-র মাধ্যমে, ২১ অক্টোবর ২০২৬; [[SafeSvg]])।
 *
 * ⓘ লোগো এখন বারের বাঁ কোণে, প্রতিটা পাতায়; আর `/storage/logos/…`-এ সরাসরি পরিবেশিত হয় — কেউ ঠিকানাটা খুললে ব্রাউজার
 * SVG-র ভেতরের স্ক্রিপ্ট চালাত, আমাদের ডোমেইনে।
 *
 * দাবি, আসল দরজা দিয়ে (কোম্পানির সম্পাদনা, `system_admin.company.update`):
 *  - স্ক্রিপ্টওয়ালা SVG নেওয়া হয়, কিন্তু রাখা ফাইলে `<script>`, `on…`, `javascript:`, `<foreignObject>` নেই; আঁকাটা থাকে;
 *  - ছবি নয় এমন ফাইল, ১ MB-র বড় SVG, ১২ MB-র বড় ছবি — ফেরত, পুরনো লোগো অক্ষত;
 *  - কেবল `system_admin.company.manage`-ওয়ালা মানুষ পারেন — অন্যরা দরজাতেই ৪০৩;
 *  - অডিটে আগের আর পরের ফাইল দুইটাই (`logo_path`, পুরনো → নতুন), আর পুরনো ফাইলটা ডিস্কে থেকে যায়।
 */
final class ALogoCouldCarryAScriptTest extends TestCase
{
    use RefreshDatabase;

    private const HOSTILE = <<<'SVG'
        <?xml version="1.0" encoding="UTF-8"?>
        <svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="120" height="40" onload="alert('load')">
          <script>alert('script')</script>
          <rect id="mark" width="40" height="40" fill="#0e4b59" onclick="alert('click')"/>
          <a xlink:href="java&#x09;script:alert('href')"><text x="48" y="26">ABC</text></a>
          <foreignObject width="10" height="10"><body xmlns="http://www.w3.org/1999/xhtml"><iframe src="https://evil.test"/></body></foreignObject>
          <set attributeName="href" to="javascript:alert('set')"/>
        </svg>
        SVG;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        Storage::fake('public');

        /*
         * ⓘ সার্ভারের মাপ বাঁধা — এই দাবি দরজার নিয়ম মাপে (ছবি নয়, ১ MB-র বড় SVG, ১২ MB-র বড় ছবি), PHP-র ini নয়। ⛔ না বাঁধলে
         * Mac-এর ini (৮M/২M) থেকে ১৩ MB-র ছবি POST-সীমা পেরোত, ফর্মে ফিরত `?file_too_big=1` নিয়ে, নিয়মের ভুল ছাড়া — এই PC-র
         * ২G-তে সবুজ, Mac-এ লাল (cb-web21-a, ২১ অক্টোবর ২০২৬)। বড় POST-এর ফেরা মাপে [[ALogoBiggerThanTheServerTakesTest]]।
         */
        config(['abos.uploads.post_max_size' => '64M', 'abos.uploads.upload_max_filesize' => '64M']);
    }

    public function test_a_script_in_an_svg_logo_is_cut_out_and_the_drawing_stays(): void
    {
        $this->send($this->owner, UploadedFile::fake()->createWithContent('logo.svg', self::HOSTILE))
            ->assertSessionHasNoErrors()->assertRedirect();

        $path = (string) $this->company->fresh()->logo_path;
        $this->assertStringEndsWith('.svg', $path, '⛔ SVG লোগো রাখা হয়নি।');
        $kept = (string) Storage::disk('public')->get($path);

        foreach (['<script', 'onload', 'onclick', 'javascript', 'foreignObject', '<iframe'] as $danger) {
            $this->assertStringNotContainsStringIgnoringCase($danger, $kept, "⛔ রাখা লোগোয় এখনো {$danger}।");
        }
        $this->assertStringContainsString('<rect id="mark"', $kept, '⛔ ছাঁটতে গিয়ে আঁকাটাই গেছে।');
        $this->assertStringContainsString('ABC', $kept, '⛔ ছাঁটতে গিয়ে লেখাটাই গেছে।');
        $this->assertStringStartsWith('<svg', $kept, '⛔ রাখা ফাইলটা SVG নয়।');
    }

    public function test_a_non_image_an_oversize_svg_and_an_oversize_picture_are_refused(): void
    {
        Storage::disk('public')->put('logos/before.png', 'png');
        $this->company->forceFill(['logo_path' => 'logos/before.png'])->save();

        $bigSvg = '<svg xmlns="http://www.w3.org/2000/svg"><rect width="1" height="1"/><desc>'.str_repeat('x', 1100 * 1024).'</desc></svg>';
        $refused = [
            'ছবি নয় (PDF)' => UploadedFile::fake()->create('logo.pdf', 20, 'application/pdf'),
            'ছবি নয় (লেখা)' => UploadedFile::fake()->createWithContent('logo.png', 'just some text, not a picture'),
            '১ MB-র বড় SVG' => UploadedFile::fake()->createWithContent('big.svg', $bigSvg),
            '১২ MB-র বড় ছবি' => UploadedFile::fake()->image('big.png', 200, 80)->size(13000),
            'SVG নামে অন্য জিনিস' => UploadedFile::fake()->createWithContent('page.svg', '<html><body><script>alert(1)</script></body></html>'),
        ];

        foreach ($refused as $what => $file) {
            $this->send($this->owner, $file);
            /*
             * ⓘ সেশন JSON-এ রাখা (`session.serialization = json`): রাখার মুহূর্তে Laravel ভুলের থলেটাকে array বানায়
             * (`Store::prepareErrorBagForSerialization()`), আর পরের অনুরোধে আবার থলে বানায় (`marshalErrorBag()`) — তাই পরীক্ষার
             * স্মৃতিতে এটা array, আসল অনুরোধে সবসময় ViewErrorBag (Mac-এ "has() on array", ২১ অক্টোবর ২০২৬)। দুই রূপই পড়া হয়।
             */
            $errors = session('errors');
            // ⛔ array রূপে ঘরটা উপরের চাবি নয় — `['default' => ['format' => …, 'messages' => ['logo' => […]]]]` (run e-তে ধরা)
            $has = $errors instanceof \Illuminate\Support\ViewErrorBag
                ? $errors->has('logo')
                : ! empty(((array) $errors)['default']['messages']['logo'] ?? null);
            $this->assertTrue($has, "⛔ {$what}: লোগোর ঘরে ভুলের বার্তা নেই — দরজা ফেরায়নি।");

            // ⭐ আর পাতাটা আবার খুললে ভুলসহ আঁকা হয়, ৫০০ নয় — Blade-এর `$errors` আসল থলে পায় (fe-র প্রশ্ন, ২১ অক্টোবর ২০২৬)
            $this->actingAs($this->owner)->get(route('system_admin.company.edit', $this->company))->assertOk();
            $this->assertSame('logos/before.png', $this->company->fresh()->logo_path, "⛔ {$what}: ফেরত যাওয়ার কথা, অথচ লোগো বদলে গেল।");
        }
    }

    public function test_only_company_manage_may_change_the_logo(): void
    {
        Storage::disk('public')->put('logos/before.png', 'png');
        $this->company->forceFill(['logo_path' => 'logos/before.png'])->save();

        $clerk = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id]);
        $clerk->companies()->attach($this->company, ['is_active' => true]);
        $this->assertFalse($clerk->fresh()->canInCompany((int) $this->company->id, 'system_admin.company.manage'), 'প্রস্তুতিটাই ভুল — কর্মীর হাতে চাবি আছে।');

        $this->send($clerk->fresh(), UploadedFile::fake()->createWithContent('logo.svg', self::HOSTILE))->assertForbidden();
        $this->assertSame('logos/before.png', $this->company->fresh()->logo_path, '⛔ চাবি ছাড়াই লোগো বদলে গেল।');
    }

    public function test_the_audit_keeps_the_file_before_and_after(): void
    {
        Storage::disk('public')->put('logos/before.png', 'png');
        $this->company->forceFill(['logo_path' => 'logos/before.png'])->save();

        $this->send($this->owner, UploadedFile::fake()->createWithContent('logo.svg', self::HOSTILE))->assertSessionHasNoErrors();
        $after = (string) $this->company->fresh()->logo_path;
        $this->assertNotSame('logos/before.png', $after, 'প্রস্তুতিটাই ভুল — লোগো বদলায়নি।');

        $trails = AuditTrail::query()->forRecord(Company::class, (int) $this->company->id)->pluck('id');
        $change = AuditFieldChange::query()->whereIn('audit_trail_id', $trails)->where('field', 'logo_path')->latest('id')->first();

        $this->assertNotNull($change, '⛔ লোগো বদলের অডিট সারি নেই।');
        $this->assertSame('logos/before.png', $change->old_value, '⛔ অডিটে আগের ফাইল নেই।');
        $this->assertSame($after, $change->new_value, '⛔ অডিটে নতুন ফাইল নেই।');
        Storage::disk('public')->assertExists('logos/before.png');
    }

    /**
     * ⭐ সার্ভার যতটা নেয় তার ভেতরের বড় ছবি ছোট হয়ে ৫০০KB-র নিচে, আর এখনো ছবি; সীমার বেশি হলে বাংলা বার্তায় ফেরত (fe, ২১ অক্টোবর ২০২৬)।
     *
     * ⓘ সীমা লাইভের মতো: post_max_size ৮ MB ([[UploadLimit]], কনফিগে বসানো — চলমান PHP-তে upload-এর ini নামানো যায় না) →
     * ছবির সীমা ৭.৭৫ MB, পর্দায় "7.7"। ⛔ আগে এই দাবি ৭.৭ MB-র JPEG পাঠাত — লাইভের সীমার গা ঘেঁষে; এখন সীমার ভেতরে
     * ৪০০০×৪০০০ PNG (~৪ MB) আর ৩০০০×৩০০০ JPEG (~৪ MB), আর সীমার বাইরে ৯ MB-র ছবি।
     * ⓘ ছবিগুলো ৪px-এর এলোমেলো রঙের খোপ — ফটোর মতো ঝিরঝিরে, সংকোচনে সবচেয়ে কঠিন; ⛔ আগে PNG ৮০০px-এ পুরো রঙে থাকত (~৬৩০KB)।
     * ⓘ ৪০০০² ছোট করতে GD-র ~১৬০ MB লাগে, তাই এই পরীক্ষায় স্মৃতির সীমা ১ GB (লাইভে ৫১২M — কুলায়)।
     */
    public function test_a_large_photo_within_the_limit_is_stored_under_500_kb_and_one_over_it_is_refused(): void
    {
        config(['abos.uploads.post_max_size' => '8M', 'abos.uploads.upload_max_filesize' => '50M']);
        $this->assertSame('7.7', UploadLimit::megabytes(UploadLimit::pictureBytes()), 'প্রস্তুতিটাই ভুল — লাইভের সীমা বসেনি।');
        $limit = ini_set('memory_limit', '1G');

        try {
            foreach (['png' => 4000, 'jpg' => 3000] as $type => $edge) {
                $photo = $this->blockNoise($edge, $edge, $type);
                $size = (int) filesize($photo);
                $this->assertGreaterThan(2 * 1024 * 1024, $size, "প্রস্তুতিটাই ভুল — {$type} ছবিটা বড় নয়।");
                $this->assertLessThan(UploadLimit::pictureBytes(), $size, "প্রস্তুতিটাই ভুল — {$type} ছবিটা সীমার বাইরে।");

                $this->send($this->owner, new UploadedFile($photo, 'photo.'.$type, $type === 'png' ? 'image/png' : 'image/jpeg', null, true))
                    ->assertSessionHasNoErrors()->assertRedirect();

                $path = (string) $this->company->fresh()->logo_path;
                $kept = (string) Storage::disk('public')->get($path);
                $this->assertLessThan(500 * 1024, strlen($kept), sprintf('⛔ %s: রাখা লোগো %d KB — ৫০০KB-র নিচে নয়।', $type, intdiv(strlen($kept), 1024)));

                $info = getimagesizefromstring($kept);
                $this->assertNotFalse($info, "⛔ {$type}: রাখা ফাইলটা আর ছবি নয়।");
                $this->assertContains($info['mime'], ['image/png', 'image/jpeg'], "⛔ {$type}: রাখা ফাইলটা PNG/JPEG নয়।");
                @unlink($photo);
            }
        } finally {
            ini_set('memory_limit', (string) $limit);
        }

        // ⛔ সীমার বাইরে (৯ MB) — বাংলা বার্তা, লোগো বদলায় না
        $this->owner->forceFill(['locale' => 'bn'])->save();
        $before = $this->company->fresh()->logo_path;
        $this->send($this->owner, UploadedFile::fake()->image('phone-photo.jpg', 400, 300)->size(9 * 1024))
            ->assertSessionHasErrors(['logo' => 'ফাইলটা 7.7 MB-র বেশি — ছোট করে আবার দিন']);
        $this->assertSame($before, $this->company->fresh()->logo_path, '⛔ সীমার বাইরের ছবিতে লোগো বদলে গেল।');
    }

    /** ৪px খোপের এলোমেলো রঙ — সংকোচনে কঠিন, তবু ১২ MB-র নিচে */
    private function blockNoise(int $width, int $height, string $type): string
    {
        mt_srand(21);
        $image = imagecreatetruecolor($width, $height);
        for ($y = 0; $y < $height; $y += 4) {
            for ($x = 0; $x < $width; $x += 4) {
                imagefilledrectangle($image, $x, $y, $x + 3, $y + 3, imagecolorallocate($image, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255)));
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'logo').'.'.$type;
        $type === 'png' ? imagepng($image, $path, 9) : imagejpeg($image, $path, 80);

        return $path;
    }

    private function send(User $who, UploadedFile $logo): \Illuminate\Testing\TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($who)
            ->from(route('system_admin.company.edit', $this->company))
            ->put(route('system_admin.company.update', $this->company), [
                'code' => $this->company->code,
                'name_en' => $this->company->name_en,
                'name_bn' => (string) $this->company->name_bn,
                'logo' => $logo,
            ]);
    }
}
