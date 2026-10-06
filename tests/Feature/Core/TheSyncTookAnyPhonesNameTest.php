<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Models\SyncDevice;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ সিঙ্কের যন্ত্রের নাম টোকেনের সাথে বাঁধা — পুরো ERP অডিট, ৬ অক্টোবর ২০২৬, ফোন ⚠️৭।
 *
 * আগে নাম আসত কেবল `?deviceId=` থেকে, আর [[SyncService::register()]] সেই নামের যন্ত্রটা ডাকা মানুষের নামে সরাত।
 * অন্যের যন্ত্রের নাম জানলে তার জলছাপ মোছা, pull-complete করে সারি হারানো যেত। এখন নামটা লগইনের টোকেনের
 * (`sync:<deviceId>`) সাথে মিলতেই হবে ([[SyncController::deviceId()]])।
 */
class TheSyncTookAnyPhonesNameTest extends TestCase
{
    use RefreshDatabase;

    private const MINE = 'phone-of-the-sales-rep';

    private const THEIRS = 'phone-of-the-owner';

    private string $token = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        // ⓘ আরেক কর্মীর ফোন আগে নিবন্ধিত — এটাই সেই "অন্যের যন্ত্র" (মালিকের লগইনে দ্বিতীয় ধাপ লাগে, তাই সাধারণ কর্মী)
        $company = \App\Models\Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $colleague = \App\Models\User::factory()->create(['email' => 'colleague@abos.test', 'is_active' => true, 'current_company_id' => $company->id]);
        $colleague->companies()->attach($company->id, ['is_active' => true]);
        \App\Core\Support\CompanyContext::forCompany($company->id, fn () => $colleague->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate('customer.view', 'web')));
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->token = $this->login('colleague@abos.test', self::THEIRS);
        $this->withToken($this->token)->getJson('/api/v1/sync/customer/pull?deviceId='.self::THEIRS)->assertOk();

        $this->token = $this->login('sales@abos.test', self::MINE);
    }

    /** একই মানুষ, একই টোকেন: নিজের নামে চলে, অন্যের নামে ফেরে — আর অন্যের যন্ত্র তাঁর নামে সরে না */
    public function test_one_sign_in_reaches_its_own_device_and_not_another(): void
    {
        $this->withToken($this->token)->getJson('/api/v1/sync/customer/pull?deviceId='.self::MINE)->assertOk();
        $this->app['auth']->forgetGuards();

        $owner = SyncDevice::query()->withoutGlobalScopes()->where('device_id', self::THEIRS)->value('user_id');

        $this->withToken($this->token)->getJson('/api/v1/sync/customer/pull?deviceId='.self::THEIRS)->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->withToken($this->token)->postJson('/api/v1/sync/customer/pull-complete?deviceId='.self::THEIRS)->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->withToken($this->token)->postJson('/api/v1/sync/sales/push?deviceId='.self::THEIRS, [])->assertForbidden();
        $this->app['auth']->forgetGuards();

        $this->assertSame($owner, SyncDevice::query()->withoutGlobalScopes()->where('device_id', self::THEIRS)->value('user_id'),
            '⛔ অন্যের যন্ত্র এই মানুষের নামে সরে গেল।');
    }

    public function test_a_name_longer_than_the_sign_in_allows_is_refused(): void
    {
        $this->withToken($this->token)->getJson('/api/v1/sync/customer/pull?deviceId='.str_repeat('x', 65))->assertStatus(400);
    }

    private function login(string $who, string $device): string
    {
        $this->app['auth']->forgetGuards();
        $login = $this->postJson('/api/v1/auth/login', [
            'identifier' => $who, 'password' => 'password', 'deviceId' => $device, 'appVersion' => '0.4.21', 'platform' => 'android',
        ]);
        $token = (string) $login->json('accessToken');
        $this->assertNotSame('', $token, 'লগইনই হয়নি: '.$login->getContent());
        $this->app['auth']->forgetGuards();

        return $token;
    }
}
