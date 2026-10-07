<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\MasterData;

use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Services\CustomerService;
use App\Modules\MasterData\Models\Location;
use App\Modules\MasterData\Services\LocationService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * এলাকা আর রুট প্রতিটা শাখার নিজের — মালিক, ৬ অক্টোবর ২০২৬: *"এলাকা ও রুট সব ব্রাঞ্চে একই দেখায় কেন?"*
 *
 * দাবি:
 *  - হেডারে A বাছলে এলাকার তালিকা, গ্রাহকের পয়েন্ট-বাছাই আর রুটের সময়সূচিতে A-র আর সব শাখার নোড, B-র নেই;
 *    ঠিকানায় B-র পয়েন্ট খুললে ৪০৪; "সব শাখা"-তে মালিক তিনটাই দেখেন।
 *  - নতুন পয়েন্ট হেডারের শাখা পায়, বাবার শাখা থাকলে সেটা; দেশ সব শাখার। অন্য শাখার এরিয়ার নিচে সরানো যায় না।
 *  - A-র গ্রাহকে B-র পয়েন্ট বসানো যায় না (সেবায় — ইমপোর্টও এই পথে)।
 *  - ভরাট: চালিয়ে-দেখা কিছু লেখে না; `--apply`-এর পরে এক শাখার পয়েন্ট সেই শাখার, তার খালি রুট বাবার শাখা পায়,
 *    দুই শাখার গ্রাহকের পয়েন্ট আর তার এরিয়া সব শাখার থাকে আর তালিকায় আসে; কোনো গ্রাহক সরে না।
 */
final class EachBranchHasItsOwnAreasAndRoutesTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Branch $a;

    private Branch $b;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->a = Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'MMS')->firstOrFail();
        $this->b = Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'NTK')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->allBranches();
    }

    public function test_a_picked_branch_sees_its_own_and_the_shared_areas_only(): void
    {
        $area = $this->node('area', 'WA-AREA', null, $this->division());
        $pointA = $this->node('point', 'WA-PA', $this->a->id, $area);
        $pointB = $this->node('point', 'WA-PB', $this->b->id, $area);
        $shared = $this->node('point', 'WA-PS', null, $area);
        $routeA = $this->node('route', 'WA-RA', $this->a->id, $pointA);
        $routeB = $this->node('route', 'WA-RB', $this->b->id, $pointB);

        // ── "সব শাখা"-তে মালিক তিনটাই দেখেন — দাবি অন্ধ নয় ──
        $codes = $this->get(route('master_data.location.level', ['level' => 'point']))->assertOk()->viewData('rows')->pluck('code');
        $this->assertTrue($codes->contains('WA-PA') && $codes->contains('WA-PB') && $codes->contains('WA-PS'), 'সব শাখায় মালিক সব পয়েন্ট দেখেন না — দাবি অন্ধ।');

        // ── হেডারে A ──
        $this->pick($this->a);

        $codes = $this->get(route('master_data.location.level', ['level' => 'point']))->assertOk()->viewData('rows')->pluck('code');
        $this->assertTrue($codes->contains('WA-PA'), 'A-র পয়েন্ট A-র তালিকায় নেই।');
        $this->assertTrue($codes->contains('WA-PS'), 'সব শাখার পয়েন্ট A-র তালিকায় নেই।');
        $this->assertFalse($codes->contains('WA-PB'), '⛔ A বাছা অবস্থায় এলাকার তালিকায় B-র পয়েন্ট।');

        $picker = $this->get(route('customer.create'))->assertOk()->viewData('locations')->pluck('code');
        $this->assertTrue($picker->contains('WA-PA'), 'গ্রাহকের পয়েন্ট-বাছাইয়ে A-র পয়েন্ট নেই — দাবি অন্ধ।');
        $this->assertFalse($picker->contains('WA-PB'), '⛔ গ্রাহকের পয়েন্ট-বাছাইয়ে অন্য শাখার পয়েন্ট।');

        $routes = collect($this->get(route('sales.route.index'))->assertOk()->viewData('routes')->items())->pluck('code');
        $this->assertTrue($routes->contains('WA-RA'), 'রুটের সময়সূচিতে A-র রুট নেই — দাবি অন্ধ।');
        $this->assertFalse($routes->contains('WA-RB'), '⛔ রুটের সময়সূচিতে B-র রুট।');
        $this->get(route('sales.route.show', $routeB))->assertNotFound();

        $this->get(route('master_data.location.show', $pointA))->assertOk();
        $this->get(route('master_data.location.show', $pointB))->assertNotFound();
        $this->get(route('master_data.location.edit', $routeB))->assertNotFound();
        $this->assertNotNull($routeA->id);
        $this->assertNotNull($shared->id);
    }

    public function test_a_new_node_takes_the_header_branch_and_cannot_move_under_another_branch(): void
    {
        $area = $this->node('area', 'WN-AREA', null, $this->division());
        $areaB = $this->node('area', 'WN-AREB', $this->b->id, $this->division());
        $this->pick($this->a);
        $service = app(LocationService::class);

        $point = $service->create(['level' => 'point', 'name_en' => 'New point in A', 'code' => 'WN-P', 'parent_id' => $this->parentFor('point', $area)->id]);
        $this->assertSame($this->a->id, $point->branch_id, '⛔ হেডারে A বাছা অবস্থায় নতুন পয়েন্ট A-র হলো না।');

        $route = $service->create(['level' => 'route', 'name_en' => 'New route', 'code' => 'WN-R', 'parent_id' => $point->id]);
        $this->assertSame($this->a->id, $route->branch_id, '⛔ A-র পয়েন্টের নিচের রুট A-র হলো না।');

        $country = $service->create(['level' => 'country', 'name_en' => 'Elsewhere', 'code' => 'WN-C']);
        $this->assertNull($country->branch_id, '⛔ দেশ একটা শাখার হয়ে গেল।');

        // ⛔ ঠিকানায় অন্য শাখার বাবা (পয়েন্টের ঠিক উপরের স্তর, B-র) — বাবা হিসেবে "নেই"; বার্তা হুবহু, যাতে অন্য কারণে থামা পাশ না করে
        $parentB = $this->parentFor('point', $areaB);
        $this->assertSame($this->b->id, $parentB->branch_id, 'B-র বাবা B-র নয় — দাবি অন্ধ।');

        try {
            $service->create(['level' => 'point', 'name_en' => 'Sneaky', 'code' => 'WN-X', 'parent_id' => $parentB->id]);
            $this->fail('⛔ A বাছা মানুষ B-র এলাকার নিচে নোড বানালেন।');
        } catch (ValidationException $e) {
            $this->assertSame([__('master_data::validation.parent_not_found')], $e->errors()['parent_id'] ?? null);
        }

        // ⛔ সব শাখার মালিক A-র পয়েন্টকে B-র বাবার নিচে সরাতে পারেন না
        $this->allBranches();

        try {
            $service->update($point->fresh(), ['parent_id' => $parentB->id]);
            $this->fail('⛔ A-র পয়েন্ট B-র এলাকার নিচে সরল।');
        } catch (ValidationException $e) {
            $this->assertSame([__('master_data::validation.parent_in_other_branch')], $e->errors()['parent_id'] ?? null);
        }

        // সব শাখার বাবার নিচে সরানো চলে — দাবি অন্ধ নয়
        $service->update($point->fresh(), ['parent_id' => $this->parentFor('point', $area)->id]);
    }

    public function test_a_customer_cannot_sit_on_another_branchs_point(): void
    {
        $area = $this->node('area', 'WC-AREA', null, $this->division());
        $pointA = $this->node('point', 'WC-PA', $this->a->id, $area);
        $pointB = $this->node('point', 'WC-PB', $this->b->id, $area);
        $customers = app(CustomerService::class);

        try {
            $customers->create(['name_en' => 'Wrong point shop', 'branch_id' => $this->a->id, 'location_id' => $pointB->id]);
            $this->fail('⛔ A-র দোকান B-র পয়েন্টে বসল।');
        } catch (ValidationException $e) {
            $this->assertSame([__('customer::validation.point_in_other_branch')], $e->errors()['location_id']);
        }

        $shop = $customers->create(['name_en' => 'Right point shop', 'branch_id' => $this->a->id, 'location_id' => $pointA->id]);
        $this->assertSame($pointA->id, (int) $shop->location_id, 'নিজের শাখার পয়েন্টে দোকান বসল না — দাবি অন্ধ।');

        $this->expectException(ValidationException::class);
        $customers->update($shop, ['location_id' => $pointB->id]);
    }

    public function test_the_backfill_gives_each_node_its_branch_and_splits_the_mixed_ones_nothing_is_tied_to(): void
    {
        $area = $this->node('area', 'WB-AREA', null, $this->division());
        $areaA = $this->node('area', 'WB-ARA', null, $this->division());
        $pointA = $this->node('point', 'WB-PA', null, $areaA);
        $routeA = $this->node('route', 'WB-RA', null, $pointA);
        $pointB = $this->node('point', 'WB-PB', null, $area);
        $mixed = $this->node('point', 'WB-PM', null, $area);
        $routeM = $this->node('route', 'WB-RM', null, $mixed);
        $tied = $this->node('point', 'WB-PT', null, $areaA);

        $this->shop('WB-1', $this->a, $pointA);
        $this->shop('WB-2', $this->b, $pointB);
        $m1 = $this->shop('WB-3', $this->a, $mixed);
        $this->shop('WB-4', $this->a, $mixed);
        $this->shop('WB-5', $this->a, $mixed);
        $m2 = $this->shop('WB-6', $this->b, $mixed);
        $t1 = $this->shop('WB-7', $this->a, $tied);
        $t2 = $this->shop('WB-8', $this->b, $tied);

        // দামের তালিকা বাঁধা পয়েন্টে — নকল হলে B-র দোকানে দামটা আর পৌঁছাত না
        \Illuminate\Support\Facades\DB::table('mdm_price_lists')->insert([
            'company_id' => $this->company->id, 'code' => 'WB-PL', 'name_en' => 'Tied list', 'location_id' => $tied->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        // ⓘ ঘড়ি থামানো — দুইটা --apply একই সেকেন্ডে, একই ফাইল-নামে; ⛔ ওপরে লিখলে প্রথম ফেরত-ফাইল হারাত (ভাগ্যের উপর নয়)
        $this->freezeTime();

        // পুরনো দৌড়ের ফাইল থাকলে "দুইটা আছে" বিনা কারণে পাশ করত
        foreach (glob(storage_path('app/location-backfill/TDEPOT-*.json')) ?: [] as $old) {
            @unlink($old);
        }

        // ── চালিয়ে-দেখা: প্রতিটা নোডে কী হবে, গ্রাহক কয়জন, কী বাঁধা — আর কিছুই লেখে না ──
        $this->artisan('master-data:locations-to-branches', ['--company' => 'TDEPOT'])
            ->expectsOutputToContain('WB-PM-2')
            ->expectsOutputToContain('mdm_price_lists: 1')
            ->assertSuccessful();
        $this->assertNull($pointA->fresh()->branch_id, '⛔ চালিয়ে-দেখা সারিতে শাখা লিখে দিল।');
        $this->assertFalse(Location::query()->where('code', 'WB-PM-2')->exists(), '⛔ চালিয়ে-দেখা নকল বানাল।');

        $this->artisan('master-data:locations-to-branches', ['--company' => 'TDEPOT', '--apply' => true])->assertSuccessful();

        $this->assertSame($this->a->id, $pointA->fresh()->branch_id, '⛔ কেবল A-র গ্রাহকের পয়েন্ট A-র হলো না।');
        $this->assertSame($this->a->id, $routeA->fresh()->branch_id, '⛔ A-র পয়েন্টের খালি রুট বাবার শাখা পেল না।');
        $this->assertSame($this->b->id, $pointB->fresh()->branch_id, '⛔ কেবল B-র গ্রাহকের পয়েন্ট B-র হলো না।');
        $this->assertNull($this->division()->fresh()->branch_id, '⛔ বিভাগ একটা শাখার হয়ে গেল।');

        // মিশ্র, অবাঁধা: বেশি গ্রাহকের শাখা (A) আসলটা রাখে, B-র জন্য নকল, B-র দোকান নকলে
        $copy = Location::query()->where('code', 'WB-PM-2')->first();
        $this->assertNotNull($copy, '⛔ মিশ্র অবাঁধা পয়েন্টের নকল হলো না।');
        $this->assertSame($this->a->id, $mixed->fresh()->branch_id, '⛔ মিশ্র পয়েন্টের আসলটা বেশি গ্রাহকের শাখা পেল না।');
        $this->assertSame($this->b->id, $copy->branch_id);
        $this->assertSame($copy->id, (int) $m2->fresh()->location_id, '⛔ B-র দোকান নকলে সরল না।');
        $this->assertSame($mixed->id, (int) $m1->fresh()->location_id, '⛔ A-র দোকান সরে গেল।');
        $this->assertSame($this->a->id, $routeM->fresh()->branch_id, '⛔ ভাগ হওয়া পয়েন্টের খালি রুট আসলটার শাখা পেল না।');

        // এরিয়াও মিশ্র আর অবাঁধা: A আসলটা রাখে, B-র নকলের নিচে B-র পয়েন্ট আর পয়েন্টের নকল
        $areaCopy = Location::query()->where('code', 'WB-AREA-2')->first();
        $this->assertNotNull($areaCopy, '⛔ মিশ্র এরিয়ার নকল হলো না।');
        $this->assertSame($this->a->id, $area->fresh()->branch_id);
        $this->assertSame([$areaCopy->id, $areaCopy->id], [(int) $pointB->fresh()->parent_id, (int) $copy->fresh()->parent_id], '⛔ B-র পয়েন্টগুলো এরিয়ার নকলের নিচে গেল না।');
        $this->assertSame($area->id, (int) $mixed->fresh()->parent_id);
        $this->assertSame((int) $area->parent_id, (int) $areaCopy->parent_id, '⛔ নকল এরিয়া অন্য বিভাগে বসল (উপরের দাম পৌঁছাত না)।');

        // বাঁধা মিশ্র: সব শাখার, কেউ সরে না; তাই তার এরিয়াও ভাগ হয় না
        $this->assertNull($tied->fresh()->branch_id, '⛔ দামের তালিকা বাঁধা মিশ্র পয়েন্ট এক শাখার হলো।');
        $this->assertFalse(Location::query()->where('code', 'WB-PT-2')->exists(), '⛔ বাঁধা পয়েন্টের নকল হলো।');
        $this->assertSame([$tied->id, $tied->id], [(int) $t1->fresh()->location_id, (int) $t2->fresh()->location_id], '⛔ বাঁধা পয়েন্টের দোকান সরল।');
        $this->assertNull($areaA->fresh()->branch_id, '⛔ নিচে সব শাখার পয়েন্ট থাকা এরিয়া এক শাখার হলো।');

        // প্রতিটা গ্রাহকের পয়েন্ট নিজের শাখার বা সব শাখার; প্রতিটা শাখার নোডের বাবা একই শাখার বা সব শাখার
        $wrong = Customer::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->whereNotNull('location_id')->get()
            ->filter(fn (Customer $c) => ($own = Location::query()->whereKey($c->location_id)->value('branch_id')) !== null && (int) $own !== (int) $c->branch_id);
        $this->assertSame([], $wrong->pluck('code')->all(), '⛔ ভরাটের পরে কোনো গ্রাহকের পয়েন্ট অন্য শাখার।');
        $torn = Location::query()->with('parent')->whereNotNull('branch_id')->get()
            ->filter(fn (Location $l) => $l->parent?->branch_id !== null && (int) $l->parent->branch_id !== (int) $l->branch_id);
        $this->assertSame([], $torn->pluck('code')->all(), '⛔ এক শাখার নোড অন্য শাখার বাবার নিচে।');

        // আবার চালালে কিছুই বদলায় না
        $count = Location::query()->count();
        $this->artisan('master-data:locations-to-branches', ['--company' => 'TDEPOT', '--apply' => true])->assertSuccessful();
        $this->assertSame($count, Location::query()->count(), '⛔ দ্বিতীয়বার চালাতে আবার নকল হলো।');

        // ── ফেরত-ফাইল: প্রথম --apply-এর অবস্থায় ফেরা ──
        $files = glob(storage_path('app/location-backfill/TDEPOT-*.json'));
        sort($files);
        $this->assertGreaterThanOrEqual(2, count($files), '⛔ একই সেকেন্ডের দ্বিতীয় --apply প্রথম ফেরত-ফাইলের ওপরে লিখল।');
        $first = collect($files)->first(fn ($f) => json_decode(file_get_contents($f), true)['created'] !== []);
        $this->assertNotNull($first, '⛔ --apply ফেরত-ফাইল রাখেনি।');

        // ⛔ নকলে এর মধ্যে নতুন দোকান বসলে ফেরত থামে, কিছুই বদলায় না — মোছা নকল ঐ দোকানকে অনাথ করত
        $stranger = $this->shop('WB-9', $this->b, $copy);
        $this->artisan('master-data:locations-to-branches', ['--company' => 'TDEPOT', '--revert' => $first])->assertFailed();
        $this->assertTrue(Location::query()->whereKey($copy->id)->exists(), '⛔ ব্যবহৃত নকল ফেরতে মুছে গেল।');
        $this->assertSame($copy->id, (int) $m2->fresh()->location_id, '⛔ থেমে যাওয়া ফেরত তবু দোকান সরাল।');
        $stranger->forceDelete();

        $this->artisan('master-data:locations-to-branches', ['--company' => 'TDEPOT', '--revert' => $first])->assertSuccessful();

        $this->assertNull($mixed->fresh()->branch_id, '⛔ ফেরতে শাখা মুছল না।');
        $this->assertSame($mixed->id, (int) $m2->fresh()->location_id, '⛔ ফেরতে B-র দোকান আসল পয়েন্টে ফিরল না।');
        $this->assertSame($area->id, (int) $pointB->fresh()->parent_id, '⛔ ফেরতে B-র পয়েন্ট আসল এরিয়ায় ফিরল না।');
        $this->assertFalse(Location::query()->withTrashed()->whereIn('code', ['WB-PM-2', 'WB-AREA-2'])->exists(), '⛔ ফেরতে নকল মুছল না।');

        foreach ($files as $f) {
            @unlink($f);
        }
    }

    // ── যন্ত্রপাতি ──

    private function node(string $level, string $code, ?int $branch, ?Location $parent): Location
    {
        return Location::query()->create([
            'company_id' => $this->company->id, 'branch_id' => $branch, 'parent_id' => $parent?->id,
            'code' => $code, 'name_en' => 'Node '.$code, 'level' => $level, 'is_active' => true,
        ]);
    }

    private function division(): Location
    {
        return Location::query()->where('level', 'division')->orderBy('id')->firstOrFail();
    }

    /** টেরিটরি চালু থাকলে পয়েন্টের বাবা টেরিটরি — এরিয়ার নিচে একটা বানিয়ে দেওয়া */
    private function parentFor(string $level, Location $area): Location
    {
        if (Location::parentLevelOf($level) === 'area') {
            return $area;
        }

        return Location::query()->where('parent_id', $area->id)->where('level', 'territory')->first()
            ?? $this->node('territory', $area->code.'-T', $area->branch_id, $area);
    }

    private function shop(string $code, Branch $branch, Location $at): Customer
    {
        return Customer::query()->create([
            'company_id' => $this->company->id, 'branch_id' => $branch->id, 'location_id' => $at->id,
            'code' => $code, 'name_en' => 'Shop '.$code, 'status' => DocumentStatus::CONFIRMED, 'is_active' => true,
        ]);
    }

    private function pick(Branch $branch): void
    {
        $this->owner->forceFill(['view_all_branches' => false, 'current_branch_id' => $branch->id])->save();
        CompanyContext::set($this->company->id, $branch->id);
        app(DataScope::class)->forget();
        $this->actingAs($this->owner->fresh());
    }

    private function allBranches(): void
    {
        $this->owner->forceFill(['view_all_branches' => true, 'current_branch_id' => $this->a->id])->save();
        CompanyContext::set($this->company->id, $this->a->id);
        app(DataScope::class)->forget();
        $this->actingAs($this->owner->fresh());
    }
}
