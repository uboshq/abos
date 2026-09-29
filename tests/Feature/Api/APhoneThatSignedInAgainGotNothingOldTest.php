<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ফোনে বেরিয়ে আবার ঢুকলে পুরনো কিছুই আর আসত না — গভীর অডিট ২৯ সেপ্টেম্বর ২০২৬।
 *
 * ── ⛔ কী ভাঙা ছিল ─────────────────────────────────────────────────────
 * বেরোনোর সময় অ্যাপ নিজের জমানো তথ্য মোছে (ReferenceCache::clearAll) —
 * কিন্তু সার্ভার ঐ যন্ত্রের জলচিহ্ন ([[SyncState]]) রেখে দিত, আর মুছত কেবল
 * কোম্পানি বদলালে। তাই আবার লগইনের পরে টানায় আসত কেবল **নতুন** বদল:
 * আগের গ্রাহক, পণ্য, বকেয়া আর কোনোদিন না, আর কোনো ত্রুটিও না — খালি
 * তালিকা দেখে কর্মী ভাবতেন কাজ নেই।
 *
 * ⭐ এখন ফোনে প্রতিটা **নতুন লগইন** গোড়া থেকে টানে; টোকেন নবায়ন নয় —
 * নইলে প্রতি নবায়নে পুরো তালিকা আবার আসত।
 *
 * ⓘ "সব" মানে: একেবারে নতুন একটা যন্ত্রে ঐ একই মানুষ যতগুলো সারি পান।
 */
final class APhoneThatSignedInAgainGotNothingOldTest extends TestCase
{
    use RefreshDatabase;

    private const DEVICE = 'handset-signed-in-again';

    private const MODULE = 'customer';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        /*
         * ⓘ গ্রাহকের সিঙ্ক `customer.view` চায় ([[CustomerSync::requiredPermission()]]);
         * এই দাবির বিষয় জলচিহ্ন, চাবি নয় — তাই দুইজনকেই চাবিটা দেওয়া।
         */
        foreach (['accounts@abos.test', 'sales@abos.test'] as $email) {
            $user = User::query()->where('email', $email)->firstOrFail();
            CompanyContext::forCompany($company->id, fn () => $user->givePermissionTo(Permission::findOrCreate('customer.view', 'web')));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        /*
         * ⚠️ ঘড়ি এক ঘণ্টা এগিয়ে — টানা হয় জলচিহ্ন থেকে কয়েক সেকেন্ড পিছিয়ে
         * ([[SyncService::OVERLAP_SECONDS]]), আর নমুনা-তথ্য বসে ঠিক এই মুহূর্তেই।
         * ঘড়ি না এগোলে প্রতিটা টানাতেই সব আবার আসত, আর দাবিগুলো "পুরনো"
         * আর "নতুন"-এর তফাতই মাপতে পারত না।
         */
        $this->travel(1)->hours();
    }

    /** @return array{accessToken: string, refreshToken: string} */
    private function signIn(string $who, string $device = self::DEVICE): array
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/v1/auth/login', [
            'identifier' => $who,
            'password' => 'password',
            'deviceId' => $device,
            'appVersion' => '0.4.0',
            'platform' => 'android',
        ])->assertOk()->json();
    }

    private function signOut(string $access): void
    {
        $this->withToken($access)->postJson('/api/v1/auth/logout')->assertOk();
        $this->app['auth']->forgetGuards();
    }

    /** একবার পুরো টানা — পাতা শেষ না হওয়া পর্যন্ত, তারপর "শেষ" জানানো, অ্যাপ যেমন করে। */
    private function pullAll(string $access, string $device = self::DEVICE): int
    {
        $count = 0;

        for ($page = 0; $page < 50; $page++) {
            $this->app['auth']->forgetGuards();
            $body = $this->withToken($access)
                ->getJson('/api/v1/sync/'.self::MODULE.'/pull?deviceId='.$device)
                ->assertOk()->json();

            $count += count($body['records'] ?? []);

            if (! ($body['hasMore'] ?? false)) {
                break;
            }
        }

        $this->app['auth']->forgetGuards();
        $this->withToken($access)
            ->postJson('/api/v1/sync/'.self::MODULE.'/pull-complete?deviceId='.$device)
            ->assertOk();

        return $count;
    }

    private function freshDeviceCount(string $who): int
    {
        $tokens = $this->signIn($who, 'a-brand-new-handset-'.md5($who));
        $count = $this->pullAll($tokens['accessToken'], 'a-brand-new-handset-'.md5($who));
        $this->signOut($tokens['accessToken']);

        return $count;
    }

    public function test_signing_in_again_on_the_same_phone_brings_everything_back(): void
    {
        $all = $this->freshDeviceCount('accounts@abos.test');
        $this->assertGreaterThan(0, $all, 'দৃশ্যটাই বানানো যায়নি — নতুন যন্ত্রেও কিছু আসে না।');

        $first = $this->signIn('accounts@abos.test');
        $this->assertSame($all, $this->pullAll($first['accessToken']));
        $this->signOut($first['accessToken']);

        $again = $this->signIn('accounts@abos.test');
        $this->assertSame($all, $this->pullAll($again['accessToken']),
            '⛔ বেরিয়ে আবার ঢোকার পরে পুরনো সারিগুলো আর আসেনি।');
    }

    public function test_the_next_person_on_a_shared_phone_gets_everything_they_may_see(): void
    {
        $all = $this->freshDeviceCount('accounts@abos.test');
        $this->assertGreaterThan(0, $all, 'দৃশ্যটাই বানানো যায়নি।');

        $first = $this->signIn('sales@abos.test');
        $this->pullAll($first['accessToken']);
        $this->signOut($first['accessToken']);

        $second = $this->signIn('accounts@abos.test');
        $this->assertSame($all, $this->pullAll($second['accessToken']),
            '⛔ ভাগ করা ফোনে দ্বিতীয় জন আগের জনের পরের বদল ছাড়া কিছু পাননি।');
    }

    public function test_a_token_refresh_keeps_the_place_and_does_not_start_over(): void
    {
        $tokens = $this->signIn('accounts@abos.test');
        $this->assertGreaterThan(0, $this->pullAll($tokens['accessToken']), 'দৃশ্যটাই বানানো যায়নি।');

        $this->app['auth']->forgetGuards();
        $renewed = $this->withToken($tokens['refreshToken'])
            ->postJson('/api/v1/auth/refresh', ['deviceId' => self::DEVICE])
            ->assertOk()->json();

        $this->assertSame(0, $this->pullAll($renewed['accessToken']),
            '⛔ টোকেন নবায়নেই গোড়া থেকে টানা শুরু হলো — প্রতি নবায়নে পুরো তালিকা আসত।');
    }
}
