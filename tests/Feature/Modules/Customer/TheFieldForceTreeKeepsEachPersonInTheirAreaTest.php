<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Customer;

use App\Core\Services\DataScope;
use App\Core\Services\DealerScope;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Customer\Models\AreaBinding;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Models\StaffPosition;
use App\Modules\Customer\Models\StaffSupervisor;
use App\Modules\MasterData\Models\Location;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ মালিক, ১২ নভেম্বর ২০২৬: "DSM/RSM/ASM/SR নিজের এলাকার ডিলার দেখবে … DSM সব এলাকা, নিচের ম্যানেজারদের ট্যাগ করে …
 *   কোডের কাজগুলো করে রাখো, কাকে কোন এলাকা, কার নিচে কীভাবে ট্রি — আমি পরে বসাব"।
 *
 * ⓘ চলমান এলাকা: এলাকায় **পরে** আসা ডিলারও আপনা থেকে তাঁর; উপরওয়ালা নিচের সবার এলাকা দেখেন; অন্য এলাকার SR দেখেন না।
 * ⛔ দেয়াল চালু হয় কেবল তালিকা সবুজ হলে (কারও ডিপো না থাকলে নয়), আর সুইচ কেবল মালিকের।
 */
final class TheFieldForceTreeKeepsEachPersonInTheirAreaTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        app(SettingsService::class)->set(DealerScope::SWITCH, true);
    }

    public function test_a_dealer_who_joins_the_area_later_is_seen_by_the_area_holder_and_their_boss_only(): void
    {
        $north = $this->place('FF-N', 'North', Location::AREA);
        $northPoint = $this->place('FF-N1', 'North Bazar', Location::POINT, $north);
        $south = $this->place('FF-S', 'South', Location::AREA);

        $sr = $this->person(['customer.view', 'customer.dealers.own']);
        $other = $this->person(['customer.view', 'customer.dealers.own']);
        $asm = $this->person(['customer.view', 'customer.dealers.own']);

        $this->actingAs($this->owner);
        $this->post(route('customer.fieldforce.save', $sr->id), ['position' => 'SR', 'supervisor_id' => $asm->id, 'areas' => [$north->id], 'starts_on' => now()->toDateString()])
            ->assertSessionHasNoErrors();
        $this->post(route('customer.fieldforce.save', $other->id), ['position' => 'SR', 'areas' => [$south->id], 'starts_on' => now()->toDateString()])
            ->assertSessionHasNoErrors();
        $this->post(route('customer.fieldforce.save', $asm->id), ['position' => 'ASM', 'starts_on' => now()->toDateString()])
            ->assertSessionHasNoErrors();

        // ⓘ ডিলার আসে এলাকা বসানোর **পরে**, এলাকার নিচের পয়েন্টে
        $late = Customer::query()->create(['code' => 'FF-LATE', 'name_en' => 'Late Store', 'is_active' => true, 'location_id' => $northPoint->id]);
        $scope = app(DealerScope::class);
        $scope->forget();

        $this->assertTrue($scope->allowsCustomer((int) $late->id, $sr->fresh()), '⛔ এলাকায় পরে আসা ডিলার এলাকার SR দেখেন না — চলমান নয়।');
        $this->assertTrue($scope->allowsCustomer((int) $late->id, $asm->fresh()), '⛔ উপরওয়ালা নিচের SR-এর এলাকার ডিলার দেখেন না।');
        $this->assertFalse($scope->allowsCustomer((int) $late->id, $other->fresh()), '⛔ অন্য এলাকার SR এই ডিলার দেখেন।');

        $this->assertSame('SR', StaffPosition::query()->where('user_id', $sr->id)->value('position'));
        $this->assertSame($asm->id, (int) StaffSupervisor::query()->where('user_id', $sr->id)->value('supervisor_id'));

        // ⓘ এলাকা তুলে নিলে আর দেখেন না — আজ শুরু হওয়া সারি মোছে, আগের সারি আগের দিনে শেষ হয় ("মার্চে কার ছিল")
        $this->post(route('customer.fieldforce.save', $sr->id), ['position' => 'SR', 'supervisor_id' => $asm->id, 'areas' => [], 'starts_on' => now()->toDateString()])
            ->assertSessionHasNoErrors();
        $scope->forget();
        $this->assertFalse($scope->allowsCustomer((int) $late->id, $sr->fresh()), '⛔ এলাকা তুলে নেওয়ার পরেও SR ডিলারটা দেখেন।');

        AreaBinding::query()->create(['company_id' => $this->company->id, 'user_id' => $sr->id, 'location_id' => $north->id, 'starts_on' => now()->subMonth()->toDateString()]);
        $this->post(route('customer.fieldforce.save', $sr->id), ['position' => 'SR', 'areas' => [], 'starts_on' => now()->toDateString()])
            ->assertSessionHasNoErrors();
        $old = AreaBinding::query()->where('user_id', $sr->id)->firstOrFail();
        $this->assertSame(now()->subDay()->toDateString(), $old->ends_on?->toDateString(), '⛔ পুরনো এলাকা মুছে গেল বা ভুল দিনে শেষ — "মার্চে কার ছিল" হারায়।');
    }

    public function test_the_page_sets_depots_of_this_company_only_and_keeps_the_wall_shut_until_the_list_is_green(): void
    {
        app(SettingsService::class)->set(DealerScope::SWITCH, false);
        $sr = $this->person(['customer.view', 'customer.dealers.own']);
        $depot = (int) $this->company->defaultBranch()?->id;
        $foreign = (int) \App\Models\Branch::query()->withoutGlobalScopes()->where('company_id', '<>', $this->company->id)->value('id');
        $this->assertGreaterThan(0, $foreign, 'প্রস্তুতিটাই ভুল — অন্য কোম্পানির ডিপো নেই।');

        $this->actingAs($this->owner);
        $this->get(route('customer.fieldforce.index', ['person' => $sr->id]))->assertOk()->assertSee($sr->name)->assertSee('data-no-depot="1"', false);

        $this->post(route('customer.fieldforce.save', $sr->id), ['position' => 'SR', 'depots' => [$foreign], 'starts_on' => now()->toDateString()])
            ->assertSessionHasErrors('depots');
        $this->assertSame(0, UserDataScope::query()->where('user_id', $sr->id)->count(), '⛔ অন্য কোম্পানির ডিপো বসে গেল।');

        // ⛔ ডিপো নেই — দেয়াল চালু হয় না
        $this->post(route('customer.fieldforce.wall'), ['on' => 1])->assertSessionHasErrors('wall');
        $this->assertFalse((bool) app(SettingsService::class)->get(DealerScope::SWITCH), '⛔ ডিপো ছাড়া মানুষ থাকতেও দেয়াল চালু হলো — চালুর সকালে তিনি অন্ধ।');

        $this->post(route('customer.fieldforce.save', $sr->id), ['position' => 'SR', 'depots' => [$depot], 'starts_on' => now()->toDateString()])
            ->assertSessionHasNoErrors();
        $scope = app(DataScope::class);
        $scope->forget();
        app(SettingsService::class)->set(DealerScope::SWITCH, true);
        $this->assertSame([$depot], $scope->idsFor($sr->fresh(), UserDataScope::BRANCH), '⛔ পাতায় বসানো ডিপো দেয়ালে খাটে না।');
    }

    public function test_only_the_owner_flips_the_wall(): void
    {
        $keeper = $this->person(['customer.binding.manage', 'customer.view', 'customer.dealers.all']);

        $this->actingAs($keeper);
        $this->get(route('customer.fieldforce.index'))->assertOk()->assertSee(__('customer::fieldforce.wall_owner_only'));
        $this->post(route('customer.fieldforce.wall'), ['on' => 0])->assertForbidden();
        $this->assertTrue((bool) app(SettingsService::class)->get(DealerScope::SWITCH), '⛔ মালিক নন এমন কেউ সবার দেয়াল নামালেন।');
    }

    private function place(string $code, string $en, string $level, ?Location $parent = null): Location
    {
        return Location::query()->create([
            'company_id' => $this->company->id, 'branch_id' => $this->company->defaultBranch()?->id, 'parent_id' => $parent?->id,
            'code' => $code, 'name_en' => $en, 'level' => $level, 'is_active' => true,
        ]);
    }

    /** @param list<string> $keys */
    private function person(array $keys): User
    {
        $u = User::factory()->create(['current_company_id' => $this->company->id, 'is_active' => true]);
        $u->companies()->attach($this->company->id, ['is_active' => true]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->company->id);
        $u->givePermissionTo($keys);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $u->fresh();
    }
}
