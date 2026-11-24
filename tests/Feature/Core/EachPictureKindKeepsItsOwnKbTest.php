<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Attachment\AttachmentEngine;
use App\Core\Engines\Image\ImageEngine;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ ধরন ধরে ছবির KB — কন্ট্রোল প্যানেলে (মালিক, ১৬–১৭ নভেম্বর ২০২৬: স্ক্যানার, "deposit report flexible")।
 *
 * ⓘ আগে কেবল জমার স্লিপের KB সেটিংসে ছিল (`system.image_kb.slip`); বাকি সব ছবি ইঞ্জিনের ধ্রুবকে আটকানো। এখন প্রতিটা ধরনের
 *   নিজের সেটিং (`system.image_kb.<ধরন>`), ডিফল্ট পুরনো ধ্রুবক:
 *
 *   - কেউ না বদলালে প্রতিটা ধরনের KB ঠিক আগের ধ্রুবক
 *   - একটা ধরন বদলালে কেবল সেই ধরনের মাপ বদলায়, অন্যগুলো নয়
 *   - শূন্য, ঋণাত্মক বা লেখা মানে ডিফল্ট; সীমার বাইরে হলে সীমায়
 *   - মালিক সেটিংগুলো কন্ট্রোল প্যানেলে দেখেন
 */
final class EachPictureKindKeepsItsOwnKbTest extends TestCase
{
    use RefreshDatabase;

    /** ধরন → আগের হাতে লেখা ধ্রুবক (বাইট) */
    private const OLD = [
        'paper' => ImageEngine::PAPER_BYTES,
        'photo' => ImageEngine::PAPER_BYTES,
        'face' => ImageEngine::FACE_BYTES,
        'cover' => ImageEngine::COVER_BYTES,
        'mark' => ImageEngine::MARK_BYTES,
    ];

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    private function company(): void
    {
        CompanyContext::set(Company::create(['code' => 'IKB', 'name_en' => 'Image KB Co'])->id);
    }

    private function settings(): SettingsService
    {
        return app(SettingsService::class);
    }

    public function test_every_kind_is_declared_and_starts_at_the_old_constant(): void
    {
        $this->company();
        $definitions = $this->settings()->definitions();

        $this->assertSame(array_keys(self::OLD), array_keys(ImageEngine::KB_DEFAULTS), '⛔ ইঞ্জিনের ধরনের তালিকা আর পরীক্ষার তালিকা আলাদা');

        foreach (self::OLD as $type => $bytes) {
            $key = 'system.image_kb.'.$type;

            $this->assertArrayHasKey($key, $definitions, "⛔ {$key} ঘোষণা করা নেই");
            $this->assertSame('integer', $definitions[$key]['type']);
            $this->assertSame($bytes / 1024, $definitions[$key]['default'], "⛔ {$key}-এর ডিফল্ট আগের ধ্রুবক নয়");
            $this->assertSame($bytes / 1024, ImageEngine::kbFor($type), "⛔ {$type}-এর KB কেউ না বদলাতেই বদলে গেছে");
            $this->assertSame($bytes, ImageEngine::budgetFor($type));
        }

        // ⓘ স্লিপের সেটিং যেমন ছিল
        $this->assertSame(AttachmentEngine::SLIP_KB, $this->settings()->get('system.image_kb.slip'));
    }

    public function test_changing_one_kind_moves_that_kind_and_no_other(): void
    {
        $this->company();

        foreach (array_keys(self::OLD) as $changed) {
            $this->settings()->set('system.image_kb.'.$changed, 40);

            foreach (self::OLD as $type => $bytes) {
                $this->assertSame($type === $changed ? 40 : $bytes / 1024, ImageEngine::kbFor($type),
                    "⛔ {$changed} বদলালে {$type}-এর KB ভুল");
            }

            $this->settings()->reset('system.image_kb.'.$changed);
            $this->assertSame(self::OLD[$changed] / 1024, ImageEngine::kbFor($changed), '⛔ ডিফল্টে ফেরেনি');
        }
    }

    public function test_the_setting_reaches_the_picture_itself(): void
    {
        $this->company();
        $engine = new ImageEngine;
        $cover = $this->picture(1800, 600);
        $face = $this->picture(700, 700);

        $coverBefore = $engine->cover($cover);
        $faceBefore = $engine->face($face);

        $this->settings()->set('system.image_kb.cover', ImageEngine::MIN_KB);

        $coverAfter = $engine->cover($cover);
        $faceAfter = $engine->face($face);

        $this->assertLessThan(strlen($coverBefore), strlen($coverAfter), '⛔ মলাটের KB কমালেও মলাট ছোট হয়নি');
        $this->assertSame(md5($faceBefore), md5($faceAfter), '⛔ মলাটের সেটিং প্রোফাইল ছবিও বদলে দিয়েছে');
    }

    public function test_an_invalid_value_falls_back_and_an_out_of_range_one_is_held_in_bounds(): void
    {
        $this->company();
        $default = ImageEngine::FACE_BYTES / 1024;

        foreach ([0, -5, 'abc'] as $bad) {
            $this->settings()->set('system.image_kb.face', $bad);
            $this->assertSame($default, ImageEngine::kbFor('face'), '⛔ ভুল মান ('.var_export($bad, true).') ডিফল্টে ফেরেনি');
            $this->assertSame($default * 1024, ImageEngine::budgetFor('face'));
        }

        $this->settings()->set('system.image_kb.face', 5);
        $this->assertSame(ImageEngine::MIN_KB, ImageEngine::kbFor('face'), '⛔ ২০ KB-র নিচে নেমেছে');

        $this->settings()->set('system.image_kb.paper', 999999);
        $this->assertSame(ImageEngine::MAX_KB, ImageEngine::kbFor('paper'), '⛔ ১০ MB-র ওপরে উঠেছে');

        $this->settings()->set('system.image_kb.mark', 900);
        $this->assertSame(intdiv(ImageEngine::MARK_STORED_MAX, 1024), ImageEngine::kbFor('mark'), '⛔ লোগো রাখার কড়া সীমা পেরিয়েছে');
    }

    public function test_an_unknown_kind_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ImageEngine::kbFor('nonsense');
    }

    public function test_the_owner_sees_every_kind_on_the_control_panel(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        $html = (string) $this->actingAs($owner)->get(route('system_admin.control-panel', ['tab' => 'system_admin']))->assertOk()->getContent();

        foreach (self::OLD as $type => $bytes) {
            $label = __('system_admin::settings.image_kb_'.$type);

            $this->assertStringNotContainsString('::', $label, "প্রস্তুতিটাই ভুল — {$type}-এর লেবেল লেখা নেই");
            $this->assertStringContainsString(e($label), $html, "⛔ {$type}-এর লেবেল কন্ট্রোল প্যানেলে নেই");
            $this->assertMatchesRegularExpression(
                '/name="settings\[system\.image_kb\.'.$type.'\]"\s*value="'.($bytes / 1024).'"/',
                $html,
                "⛔ {$type}-এর KB-র ঘর কন্ট্রোল প্যানেলে নেই, বা পুরনো ধ্রুবক দেখায় না",
            );
        }
    }

    /** দানাদার একটা ছবি — যাতে মান ৮৫-তে ২০ KB-র অনেক ওপরে থাকে আর সংকোচনের ধাপ চলে */
    private function picture(int $width, int $height): string
    {
        mt_srand(7070 + $width);
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 230, 226, 214));

        for ($i = 0; $i < 3000; $i++) {
            $x = mt_rand(0, $width - 10);
            $y = mt_rand(0, $height - 10);
            imagefilledrectangle($image, $x, $y, $x + mt_rand(2, 9), $y + mt_rand(2, 9),
                imagecolorallocate($image, mt_rand(20, 250), mt_rand(20, 250), mt_rand(20, 250)));
        }

        $path = tempnam(sys_get_temp_dir(), 'ikb').'.jpg';
        imagejpeg($image, $path, 95);

        return $path;
    }
}
