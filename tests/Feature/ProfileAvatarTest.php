<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Services\AvatarService;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class ProfileAvatarTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $company = Company::create(['code' => 'AV', 'name_en' => 'Avatar Co']);
        $this->user = User::factory()->create(['name' => 'Al Amin Shuvo']);
        $this->user->companies()->attach($company, ['is_active' => true]);
        $this->user->forceFill(['current_company_id' => $company->id])->save();
    }

    /** পরীক্ষার জন্য আসল ছবি — নকল বাইট নয়, কারণ সার্ভিসটা ছবিটা খুলে দেখে। */
    private function image(int $width, int $height, string $format = 'jpg'): UploadedFile
    {
        $gd = imagecreatetruecolor($width, $height);
        imagefilledrectangle($gd, 0, 0, $width, $height, imagecolorallocate($gd, 40, 90, 180));

        // উপরের অংশে আলাদা রং — কাটার পর কোন অংশটা টিকল তা মেপে দেখা যায়
        imagefilledrectangle($gd, 0, 0, $width, (int) ($height * 0.3), imagecolorallocate($gd, 220, 40, 40));

        $path = tempnam(sys_get_temp_dir(), 'avt').'.'.$format;

        match ($format) {
            'jpg' => imagejpeg($gd, $path, 95),
            'png' => imagepng($gd, $path),
            'webp' => imagewebp($gd, $path),
        };

        imagedestroy($gd);

        return new UploadedFile($path, "photo.{$format}", null, null, true);
    }

    public function test_it_stores_a_square_thumbnail(): void
    {
        $this->actingAs($this->user)
            ->post(route('profile.avatar'), ['avatar' => $this->image(1200, 800)])
            ->assertRedirect();

        $path = $this->user->fresh()->avatar_path;

        $this->assertNotNull($path);
        Storage::disk('public')->assertExists($path);

        $size = getimagesizefromstring(Storage::disk('public')->get($path));

        $this->assertSame(AvatarService::SIZE, $size[0]);
        $this->assertSame(AvatarService::SIZE, $size[1]);
        $this->assertSame('image/jpeg', $size['mime']);
    }

    public function test_it_accepts_png_and_webp_and_always_writes_jpeg(): void
    {
        foreach (['png', 'webp'] as $format) {
            $this->actingAs($this->user)
                ->post(route('profile.avatar'), ['avatar' => $this->image(600, 600, $format)])
                ->assertSessionHasNoErrors();

            $path = $this->user->fresh()->avatar_path;

            // উৎস যাই হোক, ফলাফল নতুন করে আঁকা JPEG — উৎস ফাইলটা কখনো
            // ডিস্কে যায় না, তাই ছবির ভেতরে লুকানো কিছুও যায় না।
            $this->assertStringEndsWith('.jpg', $path);
            $this->assertSame(
                'image/jpeg',
                getimagesizefromstring(Storage::disk('public')->get($path))['mime'],
            );
        }
    }

    /**
     * লম্বালম্বি ছবিতে মাঝখান থেকে কাটা হয় না।
     *
     * ৮০০×১৬০০ ছবির উপরের ৩০% (y ০–৪৮০) লাল, বাকিটা নীল। বর্গের বাহু ৮০০।
     *
     *   এখনকার নিয়ম — শুরু y = (১৬০০−৮০০) × ০.১০ = ৮০। কাটা অংশ ৮০–৮৮০,
     *   তাই লাল থাকে ৮০–৪৮০ = ৪০০px, অর্থাৎ ফলাফলের উপরের অর্ধেক
     *   (২৫৬-এ y ০–১২৮)।
     *
     *   মাঝ-বরাবর কাটলে — শুরু y = ৪০০। লাল থাকত ৪০০–৪৮০ = ৮০px,
     *   অর্থাৎ ফলাফলের উপরের ২৫px মাত্র।
     *
     * তাই y=১০০ দেখলেই দুইটা আলাদা করা যায়। ঠিক ১২৮-এ নমুনা নেওয়া হয় না —
     * ওটা দুই রঙের সীমানা, আর JPEG সেখানে রং মিশিয়ে দেয়।
     */
    public function test_portrait_photos_are_cropped_from_the_top_not_the_middle(): void
    {
        $this->actingAs($this->user)
            ->post(route('profile.avatar'), ['avatar' => $this->image(800, 1600)]);

        $out = imagecreatefromstring(
            Storage::disk('public')->get($this->user->fresh()->avatar_path)
        );

        $upper = imagecolorsforindex($out, imagecolorat($out, 128, 100));
        $lower = imagecolorsforindex($out, imagecolorat($out, 128, 200));

        // উপরের অর্ধেক লাল — মাঝ-বরাবর কাটলে এখানে নীল থাকত
        $this->assertGreaterThan(150, $upper['red']);
        $this->assertLessThan(100, $upper['blue']);

        // নিচের অর্ধেক নীল — অর্থাৎ পুরোটা লাল হয়ে যায়নি
        $this->assertGreaterThan(150, $lower['blue']);
        $this->assertLessThan(100, $lower['red']);
    }

    public function test_replacing_a_photo_deletes_the_previous_file(): void
    {
        $this->actingAs($this->user)->post(route('profile.avatar'), ['avatar' => $this->image(400, 400)]);
        $first = $this->user->fresh()->avatar_path;

        $this->actingAs($this->user)->post(route('profile.avatar'), ['avatar' => $this->image(400, 400)]);
        $second = $this->user->fresh()->avatar_path;

        $this->assertNotSame($first, $second, 'নতুন নাম না হলে ব্রাউজার পুরনো ছবিটাই ক্যাশ থেকে দেখাত।');
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);
    }

    public function test_removing_a_photo_clears_the_record_and_the_file(): void
    {
        $this->actingAs($this->user)->post(route('profile.avatar'), ['avatar' => $this->image(400, 400)]);
        $path = $this->user->fresh()->avatar_path;

        $this->actingAs($this->user)->delete(route('profile.avatar.remove'))->assertRedirect();

        $this->assertNull($this->user->fresh()->avatar_path);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_a_non_image_named_like_one_is_refused(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'evil').'.jpg';
        file_put_contents($path, "<?php echo 'owned';");

        $this->actingAs($this->user)
            ->post(route('profile.avatar'), [
                'avatar' => new UploadedFile($path, 'photo.jpg', 'image/jpeg', null, true),
            ])
            ->assertSessionHasErrors('avatar');

        $this->assertNull($this->user->fresh()->avatar_path);
        $this->assertSame([], Storage::disk('public')->allFiles('avatars'));
    }

    /** কন্ট্রোলার আটকালেও সার্ভিসটা নিজেও আটকায় — দুই স্তর, ইচ্ছাকৃত। */
    public function test_the_service_refuses_a_non_image_on_its_own(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'evil').'.jpg';
        file_put_contents($path, 'not an image at all');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('avatar.not_an_image');

        app(AvatarService::class)->store(
            $this->user,
            new UploadedFile($path, 'photo.jpg', 'image/jpeg', null, true),
        );
    }

    public function test_the_topbar_shows_the_photo_when_present_and_the_initial_when_not(): void
    {
        $this->actingAs($this->user)->get(route('dashboard'))->assertSee('Al Amin Shuvo', false);

        // ছবি নেই — আদ্যক্ষর
        $this->assertNull($this->user->avatarUrl());
        $this->assertSame('A', $this->user->initial());

        $this->actingAs($this->user)->post(route('profile.avatar'), ['avatar' => $this->image(400, 400)]);

        $this->actingAs($this->user)
            ->get(route('profile'))
            ->assertSee($this->user->fresh()->avatar_path, false);
    }

    /**
     * বাংলা নামে আদ্যক্ষর যেন মাঝখান থেকে কাটা বাইট না হয়।
     *
     * substr হলে "রহিম" থেকে তিন বাইটের প্রথম বাইটটা আসত, যেটা অক্ষরই নয়।
     */
    public function test_the_initial_of_a_bangla_name_is_a_whole_character(): void
    {
        $this->user->forceFill(['name' => 'রহিম উদ্দিন'])->save();

        $this->assertSame('র', $this->user->initial());
    }

    /**
     * ফাইলটা হারিয়ে গেলে ভাঙা ছবির বদলে আদ্যক্ষর।
     *
     * ব্যাকআপ থেকে ডাটাবেজ ফেরালে ছবিগুলো না-ও ফিরতে পারে — তখন প্রতিটা
     * পাতায় ভাঙা আইকন দেখানোর মানে হয় না।
     */
    public function test_a_missing_file_falls_back_to_the_initial(): void
    {
        $this->user->forceFill(['avatar_path' => 'avatars/gone.jpg'])->save();

        $this->assertNull($this->user->avatarUrl());
    }

    public function test_a_guest_cannot_touch_the_profile(): void
    {
        $this->get(route('profile'))->assertRedirect(route('login'));
        $this->post(route('profile.avatar'))->assertRedirect(route('login'));
        $this->delete(route('profile.avatar.remove'))->assertRedirect(route('login'));
    }
}
