<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\AuditTrail;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ চুরি যাওয়া ফোন ঢোকাই থাকত — নিজের কোন কোন ফোনে ঢোকা, দেখার বা একটা থেকে বের করার পথ ফোনে ছিল না (প্রোফাইল মেনু,
 * অ্যাপ ০.৪.৪১; a6, ২০ অক্টোবর ২০২৬; [[MeDevicesController]])।
 *
 * ⭐ এখন: আসল লগইনের টোকেনে "আমার ফোনগুলো" — এক ফোন এক সারি (access আর refresh জোড়া একসাথে), এই ফোনটা চিহ্নিত;
 * ফোন খ বের করলে খ-এর পরের অনুরোধ আর নবায়ন দুটোই ৪০১, ক চলতেই থাকে; অন্যের ফোনের id ৪০৪; খাতায় সারি।
 */
final class AStolenPhoneStayedSignedInTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);
    }

    public function test_my_two_phones_are_two_rows_and_this_one_is_marked(): void
    {
        $a = $this->login('sales@abos.test', 'phone-a-0001', 'android');
        $this->travel(5)->minutes();
        $b = $this->login('sales@abos.test', 'phone-b-0002', 'ios');
        $this->travel(5)->minutes();

        $me = User::query()->where('email', 'sales@abos.test')->firstOrFail();
        $this->assertSame(4, $me->tokens()->where('name', 'like', '%:phone-%')->count(), 'দুই ফোন × (access + refresh)');

        $rows = $this->as($a['accessToken'])->getJson('/api/v1/me/devices')->assertOk()->json('data');

        $this->assertCount(2, $rows, '⛔ access আর refresh আলাদা সারি হয়ে গেল — এক ফোন এক সারি');
        $this->assertSame(1, collect($rows)->where('current', true)->count(), '⛔ "এই ফোন" ঠিক একটা নয়');

        $current = collect($rows)->firstWhere('current', true);
        $this->assertSame('android', $current['platform']);
        $this->assertStringContainsString(__('core.devices.android'), $current['name']);
        $this->assertNotNull($current['last_used_at']);
        $this->assertNotNull($current['created_at']);

        foreach ($rows as $row) {
            // ⛔ কাঁচা deviceId বা টোকেনের সারি-নম্বর বাইরে নয় — ৩২ অক্ষরের অস্বচ্ছ ছাপ
            $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $row['id']);
            $this->assertStringNotContainsString('phone-', json_encode($row));
        }

        // ⭐ সবচেয়ে সাম্প্রতিক ব্যবহার আগে — ক এইমাত্র ডাকল (১০ মিনিটে), খ কেবল ৫ মিনিটে ঢুকেছিল
        $this->assertTrue($rows[0]['current'], '⛔ সাম্প্রতিকতম ফোন প্রথমে নয়');

        // ⓘ খ-এর চোখে খ-ই চিহ্নিত
        $fromB = $this->as($b['accessToken'])->getJson('/api/v1/me/devices')->assertOk()->json('data');
        $this->assertSame('ios', collect($fromB)->firstWhere('current', true)['platform']);
    }

    public function test_signing_out_phone_b_from_phone_a_ends_b_and_keeps_a(): void
    {
        $a = $this->login('sales@abos.test', 'phone-a-0001', 'android');
        $b = $this->login('sales@abos.test', 'phone-b-0002', 'ios');

        $this->as($b['accessToken'])->getJson('/api/v1/me')->assertOk();

        $rows = collect($this->as($a['accessToken'])->getJson('/api/v1/me/devices')->json('data'));
        $phoneB = $rows->firstWhere('current', false);

        $this->as($a['accessToken'])->deleteJson('/api/v1/me/devices/'.$phoneB['id'])->assertNoContent();

        // ⛔ খ-এর পরের অনুরোধ ৪০১ — আর নবায়নও ৪০১, নাহলে ফোনটা নিজেকে আবার ঢুকিয়ে নিত
        $this->as($b['accessToken'])->getJson('/api/v1/me')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->withToken($b['refreshToken'])->postJson('/api/v1/auth/refresh', ['deviceId' => 'phone-b-0002'])->assertUnauthorized();

        // ⭐ ক চলতেই থাকে
        $this->as($a['accessToken'])->getJson('/api/v1/me')->assertOk();
        $this->assertCount(1, $this->as($a['accessToken'])->getJson('/api/v1/me/devices')->json('data'));

        $me = User::query()->where('email', 'sales@abos.test')->firstOrFail();
        $trail = AuditTrail::query()->withoutGlobalScopes()
            ->where('auditable_type', User::class)->where('auditable_id', $me->id)->where('action', 'device_signed_out')->get();

        $this->assertCount(1, $trail, '⛔ ফোন বের করা খাতায় বসেনি');
        $this->assertSame($phoneB['name'], $trail[0]->reason, 'খাতায় ফোনের নাম');
        $this->assertSame((int) $me->id, (int) $trail[0]->user_id);
        $this->assertStringNotContainsString('phone-b', (string) $trail[0]->reason, '⛔ খাতায় কাঁচা deviceId');
    }

    public function test_another_persons_phone_is_404_and_stays_signed_in(): void
    {
        $mine = $this->login('sales@abos.test', 'phone-a-0001', 'android');
        $theirs = $this->login('accounts@abos.test', 'phone-c-0003', 'android');

        $theirId = $this->as($theirs['accessToken'])->getJson('/api/v1/me/devices')->json('data.0.id');
        $this->assertIsString($theirId);

        $this->as($mine['accessToken'])->deleteJson('/api/v1/me/devices/'.$theirId)->assertNotFound();
        $this->as($mine['accessToken'])->deleteJson('/api/v1/me/devices/'.str_repeat('0', 32))->assertNotFound();

        $this->as($theirs['accessToken'])->getJson('/api/v1/me')->assertOk();
        $this->assertSame(0, AuditTrail::query()->withoutGlobalScopes()->where('action', 'device_signed_out')->count());
    }

    public function test_one_shared_handset_signs_out_only_my_login_on_it(): void
    {
        // ⓘ দোকানের এক ফোন, দুই মানুষ পালা করে — একই deviceId-তে দুজনেরই টোকেন ([[AuthController::issue()]] কেবল নিজেরটা মোছে)
        $mine = $this->login('sales@abos.test', 'shop-phone-01', 'android');
        $theirs = $this->login('accounts@abos.test', 'shop-phone-01', 'android');

        $id = $this->as($mine['accessToken'])->getJson('/api/v1/me/devices')->json('data.0.id');
        $this->as($mine['accessToken'])->deleteJson('/api/v1/me/devices/'.$id)->assertNoContent();

        $this->as($mine['accessToken'])->getJson('/api/v1/me')->assertUnauthorized();

        // ⛔ একই হ্যান্ডসেটে অন্যজনের লগইন চলতেই থাকে — নবায়নসহ
        $this->as($theirs['accessToken'])->getJson('/api/v1/me')->assertOk();
        $accounts = User::query()->where('email', 'accounts@abos.test')->firstOrFail();
        $this->assertSame(2, $accounts->tokens()->where('name', 'like', '%:shop-phone-01')->count(),
            '⛔ আমার ফোন বের করতে গিয়ে একই হ্যান্ডসেটে অন্যের টোকেনও মুছল');
    }

    public function test_signing_out_this_very_phone_is_allowed_and_signs_it_out(): void
    {
        $a = $this->login('sales@abos.test', 'phone-a-0001', 'android');

        $own = $this->as($a['accessToken'])->getJson('/api/v1/me/devices')->json('data.0');
        $this->assertTrue($own['current']);

        $this->as($a['accessToken'])->deleteJson('/api/v1/me/devices/'.$own['id'])->assertNoContent();

        $this->as($a['accessToken'])->getJson('/api/v1/me')->assertUnauthorized();
        $this->assertSame(0, User::query()->where('email', 'sales@abos.test')->firstOrFail()->tokens()->count());
    }

    public function test_a_refresh_token_cannot_open_the_list(): void
    {
        $a = $this->login('sales@abos.test', 'phone-a-0001', 'android');

        // ⛔ refresh টোকেনে `abilities:app` নেই — চুরি যাওয়া refresh টোকেনে তালিকাও নয়, বের করাও নয়
        $this->as($a['refreshToken'])->getJson('/api/v1/me/devices')->assertForbidden();
    }

    /** @return array{accessToken: string, refreshToken: string} */
    private function login(string $email, string $device, string $platform): array
    {
        $this->app['auth']->forgetGuards();

        $answer = $this->postJson('/api/v1/auth/login', [
            'identifier' => $email, 'password' => 'password', 'deviceId' => $device,
            'appVersion' => '0.4.41', 'platform' => $platform,
        ])->assertOk();

        $this->assertNotSame('', (string) $answer->json('accessToken'), 'লগইনই হয়নি');

        return ['accessToken' => (string) $answer->json('accessToken'), 'refreshToken' => (string) $answer->json('refreshToken')];
    }

    private function as(string $token): self
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }
}
