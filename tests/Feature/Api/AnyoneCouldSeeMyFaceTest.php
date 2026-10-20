<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Engines\Image\ImageEngine;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\AuditFieldChange;
use App\Models\AuditTrail;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ⛔ যে কেউ আমার মুখ দেখতে পেত — ছবি ছিল `public` ডিস্কে, `/storage/avatars/…`, লগইন ছাড়াই (মালিক, a6, ২০ অক্টোবর ২০২৬)।
 *
 * ⭐ এখন: ফোন থেকে ছবি বসানো (JPG/PNG, বাইট দেখে) → ৫১২ বিন্দুর বর্গ, ব্যক্তিগত ডিস্কে, ওয়েবের একই কলামে; সরানো যায়;
 * দুটোই নিরীক্ষার খাতায়, বাইট ছাড়া। দেখা যায় কেবল নিজে আর একই চালু কোম্পানির চালু সহকর্মী — অন্য কোম্পানির কেউ ৪০৪,
 * ফোনের দরজায় আর ওয়েবের দরজায় একই ([[UserPhotoController]], [[AvatarService::visibleTo()]])।
 */
final class AnyoneCouldSeeMyFaceTest extends TestCase
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

    public function test_a_jpg_from_the_phone_is_kept_at_512_privately_and_the_web_shows_the_same_face(): void
    {
        $answer = $this->phone($this->me)->post('/api/v1/me/photo', ['photo' => $this->image(1200, 800, 'jpg')], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonStructure(['photo_url', 'updated_at']);

        $path = (string) $this->me->fresh()->avatar_path;

        $this->assertStringStartsWith('faces/', $path, '⛔ ছবি ব্যক্তিগত ফোল্ডারে বসেনি');
        Storage::disk('local')->assertExists($path);
        $this->assertSame([], Storage::disk('public')->allFiles(), '⛔ ছবি আবার public ডিস্কে গেল — লগইন ছাড়াই খোলা');

        $size = getimagesizefromstring(Storage::disk('local')->get($path));
        $this->assertSame(ImageEngine::FACE_EDGE, $size[0]);
        $this->assertSame(512, $size[1], '⛔ মালিকের ৫১২ বিন্দু নয়');
        $this->assertSame('image/jpeg', $size['mime']);

        // ⭐ ফোনের ঠিকানা টোকেনের দরজা, আর ওয়েবেরটা একই মানুষের একই ছবির — public ঠিকানা কোথাও নয়
        $this->assertStringContainsString('/api/v1/users/'.$this->me->public_id.'/photo', (string) $answer->json('photo_url'));
        $this->assertSame($answer->json('photo_url'), $this->me->fresh()->avatarApiUrl());
        $this->assertStringStartsWith('/users/'.$this->me->public_id.'/photo', (string) $this->me->fresh()->avatarUrl());
        $this->assertStringNotContainsString('/storage/', (string) $this->me->fresh()->avatarUrl());

        $this->assertTrailWithoutBytes('photo_changed');
    }

    public function test_a_png_is_taken_and_written_as_a_square_jpeg(): void
    {
        $this->phone($this->me)->post('/api/v1/me/photo', ['photo' => $this->image(600, 900, 'png')], ['Accept' => 'application/json'])
            ->assertOk();

        $bytes = Storage::disk('local')->get((string) $this->me->fresh()->avatar_path);
        $size = getimagesizefromstring($bytes);

        $this->assertSame([512, 512, 'image/jpeg'], [$size[0], $size[1], $size['mime']]);
    }

    public function test_a_text_file_named_jpg_and_a_webp_are_refused_by_their_bytes(): void
    {
        $fake = tempnam(sys_get_temp_dir(), 'evil').'.jpg';
        file_put_contents($fake, "<?php echo 'owned';");

        $this->phone($this->me)->post('/api/v1/me/photo', [
            'photo' => new UploadedFile($fake, 'photo.jpg', 'image/jpeg', null, true),
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('photo');

        // ⓘ WebP ওয়েবে চলে; ফোনের মেনু কেবল JPG/PNG (মালিক)
        $this->phone($this->me)->post('/api/v1/me/photo', ['photo' => $this->image(400, 400, 'webp')], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('photo');

        $this->assertNull($this->me->fresh()->avatar_path);
        $this->assertSame([], Storage::disk('local')->allFiles('faces'));
        $this->assertSame(0, $this->trails('photo_changed')->count(), '⛔ ফেরানো ছবি খাতায় "বদল" হয়ে বসল');
    }

    public function test_removing_clears_the_file_and_the_column_and_is_written_down(): void
    {
        $this->phone($this->me)->post('/api/v1/me/photo', ['photo' => $this->image(400, 400, 'jpg')], ['Accept' => 'application/json'])
            ->assertOk();
        $path = (string) $this->me->fresh()->avatar_path;

        $this->phone($this->me)->deleteJson('/api/v1/me/photo')->assertNoContent();

        $this->assertNull($this->me->fresh()->avatar_path);
        Storage::disk('local')->assertMissing($path);
        $this->assertNull($this->me->fresh()->avatarApiUrl());
        $this->assertTrailWithoutBytes('photo_removed');
    }

    public function test_a_colleague_sees_my_face_and_someone_from_another_company_gets_404(): void
    {
        $this->phone($this->me)->post('/api/v1/me/photo', ['photo' => $this->image(400, 400, 'jpg')], ['Accept' => 'application/json'])
            ->assertOk();
        $url = '/api/v1/users/'.$this->me->public_id.'/photo';

        // ⭐ সহকর্মী — একই চালু কোম্পানি
        $seen = $this->phone($this->colleague)->get($url)->assertOk();
        $this->assertSame('image/jpeg', $seen->headers->get('Content-Type'));
        $this->assertStringContainsString('private', (string) $seen->headers->get('Cache-Control'));
        $this->assertNotEmpty($seen->headers->get('ETag'));

        // ⓘ একই ছবি দ্বিতীয়বার — ৩০৪, বাইট ছাড়া
        $this->phone($this->colleague)->get($url, ['If-None-Match' => (string) $seen->headers->get('ETag')])->assertStatus(304);

        // ⛔ অন্য কোম্পানির মানুষ — ৪০৪, ৪০৩ নয় (আছেন কি না-ও জানবেন না)
        $outsider = $this->memberOf(Company::query()->where('code', 'FMART')->firstOrFail());
        $this->phone($outsider)->get($url)->assertNotFound();

        // ⛔ এই কোম্পানিতে সদস্যপদ ছিল, এখন বন্ধ (অন্য কোম্পানিতে বসেন) — ৪০৪
        $former = $this->memberOf(Company::query()->where('code', 'FMART')->firstOrFail());
        DB::table('company_user')->insert([
            'company_id' => $this->company->id, 'user_id' => $former->id, 'is_active' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->phone($former)->get($url)->assertNotFound();

        // ⛔ উল্টো দিক — যাঁর মুখ, তাঁর এই কোম্পানির সদস্যপদ বন্ধ: চালু সহকর্মীও আর দেখেন না
        Storage::disk('local')->put('faces/'.$former->id.'-0123456789abcdef.jpg', $this->bytes(64, 64));
        $former->forceFill(['avatar_path' => 'faces/'.$former->id.'-0123456789abcdef.jpg'])->save();
        $this->phone($this->colleague)->get('/api/v1/users/'.$former->public_id.'/photo')->assertNotFound();
        $this->phone($former)->get('/api/v1/users/'.$former->public_id.'/photo')->assertOk(); // ⭐ নিজের মুখ সবসময়

        // ⛔ বন্ধ কোম্পানির সহকর্মী-সম্পর্ক গোনে না — দুজনেই কেবল একটা বন্ধ কোম্পানিতে মিলতেন
        $this->assertTrue(app(\App\Core\Services\AvatarService::class)->visibleTo($this->colleague->fresh(), $this->me->fresh()));
        Company::query()->whereKey($this->company->id)->update(['is_active' => false]);
        $this->assertFalse(app(\App\Core\Services\AvatarService::class)->visibleTo($this->colleague->fresh(), $this->me->fresh()),
            '⛔ বন্ধ কোম্পানির সদস্যপদে মুখ দেখা গেল');
    }

    public function test_the_web_door_asks_the_same_question(): void
    {
        $this->phone($this->me)->post('/api/v1/me/photo', ['photo' => $this->image(400, 400, 'jpg')], ['Accept' => 'application/json'])
            ->assertOk();
        $url = (string) $this->me->fresh()->avatarUrl();

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->colleague)->get($url)->assertOk();

        $outsider = $this->memberOf(Company::query()->where('code', 'FMART')->firstOrFail());
        $this->app['auth']->forgetGuards();
        $this->actingAs($outsider)->get($url)->assertNotFound();

        // ⛔ লগইন ছাড়া — লগইনের পাতায়, ছবি নয়
        $this->app['auth']->forgetGuards();
        $this->get($url)->assertRedirect(route('login'));
    }

    public function test_an_old_photo_on_the_public_disk_is_read_through_the_guarded_door(): void
    {
        Storage::disk('public')->put('avatars/old.jpg', $this->bytes(300, 300));
        $this->me->forceFill(['avatar_path' => 'avatars/old.jpg'])->save();

        $this->assertStringNotContainsString('/storage/', (string) $this->me->fresh()->avatarUrl(),
            '⛔ পাতায় আবার public ঠিকানা ছাপা হলো');

        $this->phone($this->colleague)->get('/api/v1/users/'.$this->me->public_id.'/photo')->assertOk();

        $outsider = $this->memberOf(Company::query()->where('code', 'FMART')->firstOrFail());
        $this->phone($outsider)->get('/api/v1/users/'.$this->me->public_id.'/photo')->assertNotFound();

        // ⭐ নতুন ছবি দিলে পুরনো public ফাইলটা মুছে যায়
        $this->phone($this->me)->post('/api/v1/me/photo', ['photo' => $this->image(400, 400, 'jpg')], ['Accept' => 'application/json'])
            ->assertOk();
        Storage::disk('public')->assertMissing('avatars/old.jpg');
    }

    private function assertTrailWithoutBytes(string $action): void
    {
        $trails = $this->trails($action)->get();

        $this->assertCount(1, $trails, "⛔ '{$action}' খাতায় বসেনি");
        $this->assertSame((int) $this->me->id, (int) $trails[0]->user_id, '⛔ খাতায় কাজটা অন্যের নামে');
        $this->assertSame(0, AuditFieldChange::query()->withoutGlobalScopes()->where('audit_trail_id', $trails[0]->id)->count(),
            '⛔ খাতায় ঘর-বদল বসল — ছবির পথ বা বাইট খাতায় যাওয়ার কথা নয়');

        foreach ($trails[0]->getAttributes() as $value) {
            $this->assertStringNotContainsString('faces/', (string) $value, '⛔ খাতায় ছবির পথ');
            $this->assertStringNotContainsString("\xFF\xD8\xFF", (string) $value, '⛔ খাতায় ছবির বাইট');
        }
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
        $path = tempnam(sys_get_temp_dir(), 'face').'.'.$format;
        file_put_contents($path, $this->bytes($width, $height, $format));

        return new UploadedFile($path, 'photo.'.$format, null, null, true);
    }

    private function bytes(int $width, int $height, string $format = 'jpg'): string
    {
        $gd = imagecreatetruecolor($width, $height);
        imagefilledrectangle($gd, 0, 0, $width, $height, imagecolorallocate($gd, 40, 90, 180));

        ob_start();
        match ($format) {
            'jpg' => imagejpeg($gd, null, 90),
            'png' => imagepng($gd),
            'webp' => imagewebp($gd),
        };

        return (string) ob_get_clean();
    }
}
