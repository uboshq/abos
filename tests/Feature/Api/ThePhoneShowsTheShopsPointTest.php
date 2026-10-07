<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Engines\Sync\SyncService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\MasterData\Models\Location;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ⭐ ফোনের দোকানে পয়েন্ট — মালিক, ৫ অক্টোবর ২০২৬ (ফোনের বকেয়া তালিকার ছবি দিয়ে): *"কাস্টমারের নাম মোবাইল নাম্বার
 * দেয়া আছে এখন সাথে পয়েন্ট আউট করে দাও"* ([[CustomerSync]]-এর `pointName`)।
 *
 * ⭐ দাবি:
 *   · পয়েন্টে বসা দোকান তার পয়েন্টের নাম পায় — বাংলা আগে; রুটে বসা দোকান রুটের মায়ের (পয়েন্টের) নাম
 *   · পয়েন্টে না বসা দোকানে নাম থাকে না (null) — ফোন কিছু যোগ করে না
 *   · পয়েন্টের নাম বদলালে তার দোকান পরের টানায় আবার যায়, দোকানের সারি না বদলালেও
 *   · মাইগ্রেশন গ্রাহক-মডিউলের "শেষ টানা" মোছে, অন্য মডিউলের নয় — পুরনো দোকানও পয়েন্ট পায়
 */
final class ThePhoneShowsTheShopsPointTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
    }

    public function test_a_shop_carries_its_points_name_and_a_shop_without_a_point_carries_none(): void
    {
        $point = $this->place('PT-X', 'Kawran Bazar', 'কারওয়ান বাজার', Location::POINT);
        $route = $this->place('RT-X', 'Route 7', null, Location::ROUTE, $point);
        $territory = $this->place('TR-X', 'North', 'উত্তর', Location::TERRITORY);

        [$onPoint, $onRoute, $noPoint, $nowhere] = Customer::query()->orderBy('id')->take(4)->get()->all();
        $onPoint->forceFill(['location_id' => $point->id])->save();
        $onRoute->forceFill(['location_id' => $route->id])->save();
        $noPoint->forceFill(['location_id' => $territory->id])->save();
        $nowhere->forceFill(['location_id' => null])->save();

        $shops = $this->pulled('phone-pt');

        $this->assertSame('কারওয়ান বাজার', $shops[$onPoint->public_id]['pointName'], 'পয়েন্টে বসা দোকানে পয়েন্টের বাংলা নাম নেই।');
        $this->assertSame('কারওয়ান বাজার', $shops[$onRoute->public_id]['pointName'], 'রুটে বসা দোকান তার পয়েন্টের নাম পায়নি।');
        $this->assertArrayHasKey('pointName', $shops[$noPoint->public_id]);
        $this->assertNull($shops[$noPoint->public_id]['pointName'], '⛔ পয়েন্ট নেই, অথচ এরিয়ার নাম পয়েন্ট হয়ে গেল।');
        $this->assertNull($shops[$nowhere->public_id]['pointName']);
    }

    public function test_renaming_a_point_sends_its_shops_again(): void
    {
        $point = $this->place('PT-Y', 'Old Name', 'পুরনো নাম', Location::POINT);
        $route = $this->place('RT-Y', 'Route Y', null, Location::ROUTE, $point);
        [$shop, $onRoute] = Customer::query()->orderBy('id')->take(2)->get()->all();
        $shop->forceFill(['location_id' => $point->id])->save();
        // ⓘ রুটে বসা দোকান — নাম বদলায় তার মায়ের (পয়েন্টের)
        $onRoute->forceFill(['location_id' => $route->id])->save();

        $sync = app(SyncService::class);
        $sync->pull($this->owner, 'phone-rn', 'customer', 500);
        $this->travel(2)->minutes();
        $sync->recordSuccessfulPull('phone-rn', 'customer');
        $this->travel(5)->minutes();

        $this->assertArrayNotHasKey($shop->public_id, $this->pulled('phone-rn'), 'প্রস্তুতিটাই ভুল — কিছু না বদলেও দোকান আবার এল।');

        $point->forceFill(['name_bn' => 'নতুন নাম'])->save();

        $shops = $this->pulled('phone-rn');
        $this->assertArrayHasKey($shop->public_id, $shops, '⛔ পয়েন্টের নাম বদলাল, কিন্তু দোকানটা ফোনে আবার গেল না — পুরনো নাম থেকে যেত।');
        $this->assertSame('নতুন নাম', $shops[$shop->public_id]['pointName']);
        $this->assertArrayHasKey($onRoute->public_id, $shops, '⛔ রুটের দোকান তার পয়েন্টের নতুন নাম পেল না।');
        $this->assertSame('নতুন নাম', $shops[$onRoute->public_id]['pointName']);
    }

    public function test_the_migration_makes_every_phone_pull_its_shops_again_and_nothing_else(): void
    {
        $now = now();
        foreach (['customer', 'inventory'] as $module) {
            DB::table('sync_states')->insert([
                'public_id' => (string) \Illuminate\Support\Str::uuid(), 'company_id' => CompanyContext::id(), 'device_id' => 'phone-mg',
                'module' => $module, 'last_synced_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        (require base_path('database/migrations/2027_02_10_110000_every_phone_pulls_its_shops_again_with_the_point.php'))->up();

        $this->assertNull(DB::table('sync_states')->where('device_id', 'phone-mg')->where('module', 'customer')->value('last_synced_at'));
        $this->assertNotNull(DB::table('sync_states')->where('device_id', 'phone-mg')->where('module', 'inventory')->value('last_synced_at'),
            '⛔ মাইগ্রেশন অন্য মডিউলের "শেষ টানা"-ও মুছল।');
    }

    /** @return array<string, array<string, mixed>> দোকানের public_id ধরে payload */
    private function pulled(string $device): array
    {
        $records = app(SyncService::class)->pull($this->owner, $device, 'customer', 500)['records'];

        return collect($records)->where('entityType', 'Customer')->mapWithKeys(fn (array $r) => [$r['entityId'] => json_decode($r['payloadJson'], true)])->all();
    }

    private function place(string $code, string $en, ?string $bn, string $level, ?Location $parent = null): Location
    {
        return Location::query()->create([
            'company_id' => CompanyContext::id(), 'parent_id' => $parent?->id, 'code' => $code,
            'name_en' => $en, 'name_bn' => $bn, 'level' => $level, 'is_active' => true,
        ]);
    }
}
