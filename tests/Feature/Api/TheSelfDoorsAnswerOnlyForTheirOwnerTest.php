<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * ⭐ নিজের ছবি আর নিজের ফোনের চার দরজা — অনুমতির চাবি নেই, তাই পাহারা তিনটাই প্রমাণ করা (fe, ২০ অক্টোবর ২০২৬; a6;
 * [[MePhotoController]], [[MeDevicesController]]):
 *  - লগইন ছাড়া প্রতিটা ৪০১;
 *  - অ্যাপের ক্ষমতা ছাড়া টোকেনে (refresh) প্রতিটা ৪০৩;
 *  - একজনের দরজায় অন্যজনের ছবি বা ফোন কখনো বদলায় না — ছবি তোলা আর মোছা কেবল নিজের সারি, অন্যের ফোনের id ৪০৪ আর
 *    তার ফোন চলতেই থাকে।
 */
final class TheSelfDoorsAnswerOnlyForTheirOwnerTest extends TestCase
{
    use RefreshDatabase;

    private const DOORS = [
        ['POST', '/api/v1/me/photo'],
        ['DELETE', '/api/v1/me/photo'],
        ['GET', '/api/v1/me/devices'],
        ['DELETE', '/api/v1/me/devices/0123456789abcdef0123456789abcdef'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);
        Storage::fake('local');
        Storage::fake('public');
    }

    public function test_without_a_login_every_self_door_is_401(): void
    {
        foreach (self::DOORS as [$method, $uri]) {
            $this->app['auth']->forgetGuards();
            $this->json($method, $uri)->assertUnauthorized();
        }
    }

    public function test_a_token_without_the_app_ability_is_403_on_every_self_door(): void
    {
        $sales = $this->login('sales@abos.test', 'phone-s-0001');

        foreach (self::DOORS as [$method, $uri]) {
            $this->as($sales['refreshToken'])->json($method, $uri)->assertForbidden();
        }
    }

    public function test_my_doors_never_change_someone_elses_face_or_phone(): void
    {
        $sales = $this->login('sales@abos.test', 'phone-s-0001');
        $accounts = $this->login('accounts@abos.test', 'phone-a-0001');

        $this->as($accounts['accessToken'])->post('/api/v1/me/photo', ['photo' => $this->jpg()], ['Accept' => 'application/json'])->assertOk();
        $theirs = User::query()->where('email', 'accounts@abos.test')->value('avatar_path');
        $this->assertNotNull($theirs, 'প্রস্তুতি: অন্যজনের ছবি বসেনি');

        // ⛔ আমার ছবি তোলা আর মোছা — অন্যজনের সারি একই থাকে
        $this->as($sales['accessToken'])->post('/api/v1/me/photo', ['photo' => $this->jpg()], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame($theirs, User::query()->where('email', 'accounts@abos.test')->value('avatar_path'), '⛔ আমার ছবি তোলায় অন্যজনের ছবি বদলাল');
        $this->as($sales['accessToken'])->deleteJson('/api/v1/me/photo')->assertNoContent();
        $this->assertSame($theirs, User::query()->where('email', 'accounts@abos.test')->value('avatar_path'), '⛔ আমার ছবি মোছায় অন্যজনের ছবি গেল');
        $this->assertNull(User::query()->where('email', 'sales@abos.test')->value('avatar_path'), 'আমার ছবি নিজের সারি থেকে যায়নি');

        // ⛔ অন্যজনের ফোনের id আমার দরজায় — ৪০৪, তার ফোন চলতেই থাকে
        $theirPhone = $this->as($accounts['accessToken'])->getJson('/api/v1/me/devices')->assertOk()->json('data.0.id');
        $this->as($sales['accessToken'])->deleteJson('/api/v1/me/devices/'.$theirPhone)->assertNotFound();
        $this->as($accounts['accessToken'])->getJson('/api/v1/me')->assertOk();
        $this->assertCount(1, $this->as($accounts['accessToken'])->getJson('/api/v1/me/devices')->json('data'), '⛔ অন্যজনের ফোন তালিকা থেকে গেল');
    }

    /** @return array{accessToken: string, refreshToken: string} */
    private function login(string $email, string $device): array
    {
        $this->app['auth']->forgetGuards();

        $answer = $this->postJson('/api/v1/auth/login', [
            'identifier' => $email, 'password' => 'password', 'deviceId' => $device,
            'appVersion' => '0.4.41', 'platform' => 'android',
        ])->assertOk();

        $this->assertNotSame('', (string) $answer->json('accessToken'), 'লগইনই হয়নি');

        return ['accessToken' => (string) $answer->json('accessToken'), 'refreshToken' => (string) $answer->json('refreshToken')];
    }

    private function as(string $token): self
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    private function jpg(): UploadedFile
    {
        $image = imagecreatetruecolor(600, 600);
        imagefill($image, 0, 0, imagecolorallocate($image, 30, 120, 200));
        $path = tempnam(sys_get_temp_dir(), 'face').'.jpg';
        imagejpeg($image, $path);
        imagedestroy($image);

        return new UploadedFile($path, 'photo.jpg', 'image/jpeg', null, true);
    }
}
