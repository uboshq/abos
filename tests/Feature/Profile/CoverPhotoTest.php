<?php

declare(strict_types=1);

namespace Tests\Feature\Profile;

use App\Core\Engines\Image\ImageEngine;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\AuditTrail;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ⭐ প্রোফাইলের মলাট ছবি (২০ নভেম্বর ২০২৬) — ফোন আর ওয়েব দুই দরজায় বসানো, দেখা, সরানো।
 *
 * ⓘ মুখের একই নিয়ম ([[AnyoneCouldSeeMyFaceTest]]): ব্যক্তিগত ডিস্ক (`covers/`), ৩:১ আর সর্বোচ্চ ১৫০০×৫০০ JPEG, নতুন করে আঁকা
 * (EXIF যায় না), সহকর্মী দেখেন, অন্য কোম্পানির কেউ ৪০৪। ফোনে কেবল JPG/PNG — GIF, PDF ৪২২। আর `/me/today`-র আকার ও `/me`-র দুই সুইচ।
 */
final class CoverPhotoTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $me;

    private User $colleague;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');

        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, null);

        $this->me = User::query()->where('email', 'sales@abos.test')->firstOrFail();
        $this->colleague = User::query()->where('email', 'accounts@abos.test')->firstOrFail();
    }

    public function test_a_jpg_from_the_phone_is_cut_to_3_by_1_kept_privately_and_served_to_a_colleague(): void
    {
        $answer = $this->phone($this->me)->post('/api/v1/me/cover', ['cover' => $this->image(3000, 1500, 'jpg')], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonStructure(['cover_url', 'updated_at']);

        $path = (string) $this->me->fresh()->cover_path;

        $this->assertStringStartsWith('covers/', $path, '⛔ মলাট ব্যক্তিগত ফোল্ডারে বসেনি');
        Storage::disk('local')->assertExists($path);
        $this->assertSame([], Storage::disk('public')->allFiles('covers'), '⛔ মলাট public ডিস্কে গেল');

        $size = getimagesizefromstring((string) Storage::disk('local')->get($path));
        $this->assertSame([ImageEngine::COVER_WIDTH, ImageEngine::COVER_HEIGHT, 'image/jpeg'], [$size[0], $size[1], $size['mime']]);
        $this->assertLessThanOrEqual(ImageEngine::COVER_BYTES, strlen((string) Storage::disk('local')->get($path)));

        $this->assertStringContainsString('/api/v1/users/'.$this->me->public_id.'/cover', (string) $answer->json('cover_url'));
        $this->assertSame($answer->json('cover_url'), $this->me->fresh()->coverApiUrl());
        $this->assertStringStartsWith('/users/'.$this->me->public_id.'/cover', (string) $this->me->fresh()->coverUrl());

        $seen = $this->phone($this->colleague)->get('/api/v1/users/'.$this->me->public_id.'/cover')->assertOk();
        $this->assertSame('image/jpeg', $seen->headers->get('Content-Type'));
        $this->assertStringContainsString('private', (string) $seen->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $seen->headers->get('X-Content-Type-Options'));
        $this->phone($this->colleague)->get('/api/v1/users/'.$this->me->public_id.'/cover', ['If-None-Match' => (string) $seen->headers->get('ETag')])
            ->assertStatus(304);

        $this->assertSame(1, $this->trails('cover_changed')->count(), '⛔ মলাট বদল খাতায় বসেনি');
    }

    public function test_a_small_picture_is_cut_to_3_by_1_but_never_blown_up(): void
    {
        $this->phone($this->me)->post('/api/v1/me/cover', ['cover' => $this->image(1200, 800, 'jpg')], ['Accept' => 'application/json'])
            ->assertOk();

        $size = getimagesizefromstring((string) Storage::disk('local')->get((string) $this->me->fresh()->cover_path));

        $this->assertSame([1200, 400], [$size[0], $size[1]]);
    }

    public function test_a_png_is_taken_and_written_as_jpeg(): void
    {
        $this->phone($this->me)->post('/api/v1/me/cover', ['cover' => $this->image(900, 900, 'png')], ['Accept' => 'application/json'])
            ->assertOk();

        $size = getimagesizefromstring((string) Storage::disk('local')->get((string) $this->me->fresh()->cover_path));

        $this->assertSame([900, 300, 'image/jpeg'], [$size[0], $size[1], $size['mime']]);
    }

    public function test_exif_is_gone_because_the_picture_is_redrawn(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'exif').'.jpg';
        file_put_contents($path, $this->withExif($this->bytes(1500, 600, 'jpg'), 'SECRET-GPS-23.81N-90.41E'));
        $this->assertStringContainsString('SECRET-GPS', (string) file_get_contents($path), 'ⓘ পরীক্ষার ছবিতেই EXIF বসেনি');

        $this->phone($this->me)->post('/api/v1/me/cover', [
            'cover' => new UploadedFile($path, 'cover.jpg', 'image/jpeg', null, true),
        ], ['Accept' => 'application/json'])->assertOk();

        $kept = (string) Storage::disk('local')->get((string) $this->me->fresh()->cover_path);

        $this->assertStringNotContainsString('SECRET-GPS', $kept, '⛔ EXIF রাখা ফাইলে রয়ে গেল');
        $this->assertStringNotContainsString("Exif\x00\x00", $kept, '⛔ EXIF রাখা ফাইলে রয়ে গেল');
    }

    public function test_gif_pdf_and_a_text_file_named_jpg_are_refused_422(): void
    {
        $pdf = tempnam(sys_get_temp_dir(), 'pdf').'.pdf';
        file_put_contents($pdf, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");
        $text = tempnam(sys_get_temp_dir(), 'evil').'.jpg';
        file_put_contents($text, "<?php echo 'owned';");

        foreach ([
            'gif' => $this->image(600, 200, 'gif'),
            'pdf' => new UploadedFile($pdf, 'cover.pdf', 'application/pdf', null, true),
            'text' => new UploadedFile($text, 'cover.jpg', 'image/jpeg', null, true),
        ] as $what => $file) {
            $this->phone($this->me)->post('/api/v1/me/cover', ['cover' => $file], ['Accept' => 'application/json'])
                ->assertStatus(422)->assertJsonValidationErrors('cover');
            $this->assertNull($this->me->fresh()->cover_path, "⛔ {$what} মলাট হয়ে বসল");
        }

        $this->assertSame([], Storage::disk('local')->allFiles('covers'));
        $this->assertSame(0, $this->trails('cover_changed')->count(), '⛔ ফেরানো মলাট খাতায় "বদল" হয়ে বসল');
    }

    public function test_removing_clears_the_file_and_the_column(): void
    {
        $this->phone($this->me)->post('/api/v1/me/cover', ['cover' => $this->image(900, 300, 'jpg')], ['Accept' => 'application/json'])
            ->assertOk();
        $path = (string) $this->me->fresh()->cover_path;

        $this->phone($this->me)->deleteJson('/api/v1/me/cover')->assertNoContent();

        $this->assertNull($this->me->fresh()->cover_path);
        Storage::disk('local')->assertMissing($path);
        $this->assertNull($this->me->fresh()->coverApiUrl());
        $this->assertNull($this->me->fresh()->coverUrl());
        $this->assertSame(1, $this->trails('cover_removed')->count());

        // ⓘ মলাট নেই — আবার সরালেও ২০৪, খাতায় না-ঘটা ঘটনা নয়
        $this->phone($this->me)->deleteJson('/api/v1/me/cover')->assertNoContent();
        $this->assertSame(1, $this->trails('cover_removed')->count());
    }

    public function test_a_new_cover_replaces_the_old_file(): void
    {
        $this->phone($this->me)->post('/api/v1/me/cover', ['cover' => $this->image(900, 300, 'jpg')], ['Accept' => 'application/json'])
            ->assertOk();
        $first = (string) $this->me->fresh()->cover_path;

        $this->phone($this->me)->post('/api/v1/me/cover', ['cover' => $this->image(900, 300, 'png')], ['Accept' => 'application/json'])
            ->assertOk();

        Storage::disk('local')->assertMissing($first);
        $this->assertCount(1, Storage::disk('local')->allFiles('covers'));
    }

    public function test_someone_from_another_company_gets_404_on_both_doors(): void
    {
        $this->phone($this->me)->post('/api/v1/me/cover', ['cover' => $this->image(900, 300, 'jpg')], ['Accept' => 'application/json'])
            ->assertOk();
        $outsider = $this->memberOf(Company::query()->where('code', 'FMART')->firstOrFail());

        $this->phone($outsider)->get('/api/v1/users/'.$this->me->public_id.'/cover')->assertNotFound();

        $this->app['auth']->forgetGuards();
        $this->actingAs($outsider)->get((string) $this->me->fresh()->coverUrl())->assertNotFound();

        // ⓘ মলাট নেই এমন সহকর্মী — ৪০৪, ভাঙা ছবি নয়
        $this->phone($this->me)->get('/api/v1/users/'.$this->colleague->public_id.'/cover')->assertNotFound();

        // ⛔ লগইন ছাড়া — লগইনের পাতায়
        $this->app['auth']->forgetGuards();
        $this->get((string) $this->me->fresh()->coverUrl())->assertRedirect(route('login'));
    }

    public function test_the_web_profile_sets_and_removes_the_cover(): void
    {
        $this->app['auth']->forgetGuards();

        $this->actingAs($this->me)->from('/profile')
            ->post(route('profile.cover'), ['cover' => $this->image(1800, 900, 'jpg')])
            ->assertRedirect('/profile')
            ->assertSessionHas('saved', true)
            ->assertSessionHasNoErrors();

        $path = (string) $this->me->fresh()->cover_path;
        Storage::disk('local')->assertExists($path);

        // ⭐ সহকর্মী ওয়েবের দরজায় দেখেন
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->colleague)->get((string) $this->me->fresh()->coverUrl())->assertOk();

        // ⛔ ছবি নয় — ফর্মে ফেরত, ভুলের বার্তাসহ
        $pdf = tempnam(sys_get_temp_dir(), 'pdf').'.pdf';
        file_put_contents($pdf, "%PDF-1.4\n%%EOF\n");
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->me)->from('/profile')
            ->post(route('profile.cover'), ['cover' => new UploadedFile($pdf, 'cover.pdf', 'application/pdf', null, true)])
            ->assertRedirect('/profile')
            ->assertSessionHasErrors('cover');
        $this->assertSame($path, (string) $this->me->fresh()->cover_path, '⛔ ভুল ফাইলে আগের মলাট হারাল');

        $this->actingAs($this->me)->from('/profile')
            ->delete(route('profile.cover.remove'))
            ->assertRedirect('/profile');

        $this->assertNull($this->me->fresh()->cover_path);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_me_today_answers_three_whole_numbers(): void
    {
        $answer = $this->phone($this->me)->getJson('/api/v1/me/today')
            ->assertOk()
            ->assertJsonStructure(['approvals_waiting', 'unread_notices', 'day_close_pending']);

        foreach (['approvals_waiting', 'unread_notices', 'day_close_pending'] as $key) {
            $this->assertIsInt($answer->json($key), "⛔ {$key} পূর্ণসংখ্যা নয়");
            $this->assertGreaterThanOrEqual(0, $answer->json($key));
        }
    }

    public function test_me_tells_the_app_the_profile_page_and_cover_doors_exist(): void
    {
        $this->phone($this->me)->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('features.profile_page', true)
            ->assertJsonPath('features.cover_photo', true)
            ->assertJsonPath('features.profile_photo', true);
    }

    private function trails(string $action)
    {
        return AuditTrail::query()->withoutGlobalScopes()
            ->where('auditable_type', User::class)->where('auditable_id', $this->me->id)->where('action', $action);
    }

    private function memberOf(Company $company): User
    {
        $user = User::factory()->create(['current_company_id' => $company->id]);
        $user->companies()->attach($company->id, ['is_active' => true]);

        return $user;
    }

    private function phone(User $user): self
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user->fresh(), [AuthController::APP]);

        return $this;
    }

    private function image(int $width, int $height, string $format): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'cover').'.'.$format;
        file_put_contents($path, $this->bytes($width, $height, $format));

        return new UploadedFile($path, 'cover.'.$format, null, null, true);
    }

    private function bytes(int $width, int $height, string $format = 'jpg'): string
    {
        $gd = imagecreatetruecolor($width, $height);
        imagefilledrectangle($gd, 0, 0, $width, $height, imagecolorallocate($gd, 40, 90, 180));
        imagefilledrectangle($gd, 0, 0, (int) ($width / 3), (int) ($height / 3), imagecolorallocate($gd, 220, 60, 60));

        ob_start();
        match ($format) {
            'jpg' => imagejpeg($gd, null, 90),
            'png' => imagepng($gd),
            'gif' => imagegif($gd),
        };

        return (string) ob_get_clean();
    }

    /** ⓘ SOI-এর ঠিক পরে একটা APP1 (EXIF) অংশ — ছোট বৈধ TIFF মাথা (শূন্য ঘর), তারপর চিহ্নের লেখা */
    private function withExif(string $jpeg, string $marker): string
    {
        $payload = "Exif\x00\x00".'II*'."\x00".pack('V', 8).pack('v', 0).pack('V', 0).$marker;
        $segment = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;

        return substr($jpeg, 0, 2).$segment.substr($jpeg, 2);
    }
}
