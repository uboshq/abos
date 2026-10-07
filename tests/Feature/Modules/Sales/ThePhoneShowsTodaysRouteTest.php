<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\MasterData\Models\Location;
use App\Modules\MasterData\Services\LocationService;
use App\Modules\Sales\Services\RouteVisitService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ ফোনে "আমার আজকের রুট" — সমন্বয়কের ক্রম "ঘ" (৫ অক্টোবর ২০২৬; [[MyRouteApiController]])।
 *
 * ⭐ দাবি:
 *   চাবি ছাড়া ৪০৩; চাবিতে কেবল আজকের সপ্তাহের দিনে আমার নামে বসানো রুট, তার দোকানগুলো, বকেয়াসহ;
 *   অন্য দিনের রুট বা অন্যের রুট আসে না; ছক শেষ হয়ে গেলে আসে না; অন্য দিন চাইলে সেই দিনের রুট।
 */
final class ThePhoneShowsTodaysRouteTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Location $mine;

    private Location $otherDay;

    private Location $theirs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $point = Location::query()->where('code', 'PT-KDA')->firstOrFail();
        $this->mine = $this->route($point, 'RT-T-MINE', 'আমার রুট');
        $this->otherDay = $this->route($point, 'RT-T-OTHER', 'অন্য দিনের রুট');
        $this->theirs = $this->route($point, 'RT-T-THEIRS', 'অন্যের রুট');

        Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail()->forceFill(['location_id' => $this->mine->id])->save();
        Customer::query()->where('name_en', 'Alam Store')->firstOrFail()->forceFill(['location_id' => $this->otherDay->id])->save();
        Customer::query()->where('name_en', 'Karim Stores')->firstOrFail()->forceFill(['location_id' => $this->theirs->id])->save();
    }

    public function test_the_same_person_needs_the_key_and_sees_only_their_route_for_the_day(): void
    {
        $sr = $this->staff([]);
        $other = $this->staff([]);
        $today = Carbon::today();
        $tomorrow = $today->copy()->addDay();
        $visits = app(RouteVisitService::class);
        $visits->assign($this->mine, ['user_id' => $sr->id, 'weekdays' => [$today->dayOfWeek], 'effective_from' => $today->copy()->subMonth()->toDateString()]);
        $visits->assign($this->otherDay, ['user_id' => $sr->id, 'weekdays' => [$tomorrow->dayOfWeek], 'effective_from' => $today->copy()->subMonth()->toDateString()]);
        $visits->assign($this->theirs, ['user_id' => $other->id, 'weekdays' => [$today->dayOfWeek], 'effective_from' => $today->copy()->subMonth()->toDateString()]);

        Sanctum::actingAs($sr, [AuthController::APP]);
        $this->getJson('/api/v1/sales/my-route')->assertForbidden();

        $sr = $this->staff(['sales.order.view'], $sr);
        Sanctum::actingAs($sr, [AuthController::APP]);
        $day = $this->getJson('/api/v1/sales/my-route')->assertOk()->json();
        $this->assertSame([(string) $this->mine->public_id], array_column($day['routes'], 'id'), '⛔ আজকের রুটে অন্য দিনের বা অন্যের রুট এলো।');
        $this->assertSame(['Rahim Traders'], array_map(fn ($s) => Customer::query()->where('public_id', $s['id'])->value('name_en'), $day['shops']),
            '⛔ আজকের দোকানে অন্য রুটের দোকান এলো।');
        $this->assertSame((string) $this->mine->public_id, $day['shops'][0]['route']);
        $this->assertArrayHasKey('outstanding', $day['shops'][0]);
        $this->assertNull($day['next_page']);

        $next = $this->getJson('/api/v1/sales/my-route?date='.$tomorrow->toDateString())->assertOk()->json('routes');
        $this->assertSame([(string) $this->otherDay->public_id], array_column($next, 'id'), 'অন্য দিন চাইলে সেই দিনের রুট।');
    }

    public function test_an_ended_schedule_no_longer_brings_the_route(): void
    {
        $sr = $this->staff(['sales.order.view']);
        $today = Carbon::today();
        [$visit] = app(RouteVisitService::class)->assign($this->mine, [
            'user_id' => $sr->id, 'weekdays' => [$today->dayOfWeek], 'effective_from' => $today->copy()->subMonth()->toDateString(),
        ]);
        app(RouteVisitService::class)->end($visit, $today->copy()->subDay());

        Sanctum::actingAs($sr, [AuthController::APP]);
        $day = $this->getJson('/api/v1/sales/my-route')->assertOk()->json();
        $this->assertSame([], $day['routes'], '⛔ শেষ হয়ে যাওয়া ছকের রুট আজও এলো।');
        $this->assertSame([], $day['shops'], '⛔ রুট নেই, তবু দোকান এলো — সব দোকান ফাঁস হত।');
    }

    private function route(Location $point, string $code, string $name): Location
    {
        return app(LocationService::class)->create([
            'code' => $code, 'name_en' => $name, 'name_bn' => $name, 'level' => Location::ROUTE, 'parent_id' => $point->id,
        ]);
    }

    /** @param  list<string>  $keys */
    private function staff(array $keys, ?User $user = null): User
    {
        if ($user === null) {
            $user = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id]);
            $user->companies()->attach($this->company->id, ['is_active' => true]);
        }
        foreach ($keys as $key) {
            CompanyContext::forCompany($this->company->id,
                fn () => $user->givePermissionTo(Permission::findOrCreate($key, 'web')));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }
}
