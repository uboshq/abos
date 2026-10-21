<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\SystemAdmin;

use App\Core\Support\CompanyContext;
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
            $this->send($this->owner, $file)->assertSessionHasErrors('logo');
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
     * ⭐ ছবি ১২ MB পর্যন্ত নেওয়া হয় কারণ আমরা ছোট করি — কিন্তু রাখা ফাইল ৫০০KB-র নিচে, আর এখনো ছবি (fe, ২১ অক্টোবর ২০২৬)।
     *
     * ⓘ ৪০০০×৪০০০, ৪px-এর এলোমেলো রঙের খোপ — ফটোর মতো ঝিরঝিরে, সংকোচনে সবচেয়ে কঠিন। ⛔ আগে PNG ৮০০px-এ নামিয়ে পুরো রঙে
     * রাখা হত — এই ছবিতে ~৬৩০KB; JPEG আগে থেকেই ছোট (~২১০KB), তবু দুইটাই দাবি। ⓘ ৪০০০² ছোট করতে GD-র ~১৬০ MB লাগে, তাই
     * এই পরীক্ষায় স্মৃতির সীমা ১ GB (সার্ভারের সীমা আলাদা কথা — না কুলালে দরজা "প্রক্রিয়া হয়নি" বলে ফেরায়, কাঁচা রাখে না)।
     */
    public function test_a_large_photo_is_shrunk_and_stored_under_500_kb(): void
    {
        $limit = ini_set('memory_limit', '1G');

        try {
            foreach (['png', 'jpg'] as $type) {
                $photo = $this->blockNoise(4000, 4000, $type);
                $size = (int) filesize($photo);
                $this->assertGreaterThan(1024 * 1024, $size, "প্রস্তুতিটাই ভুল — {$type} ছবিটা বড় নয়।");
                $this->assertLessThan(12 * 1024 * 1024, $size, "প্রস্তুতিটাই ভুল — {$type} ছবিটা দরজার ১২ MB-র বেশি।");

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
