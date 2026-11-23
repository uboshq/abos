<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\Setting;
use App\Models\User;
use App\Modules\Customer\Models\AreaBinding;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Models\DealerBinding;
use App\Modules\Customer\Models\StaffSupervisor;
use App\Modules\MasterData\Models\Location;
use App\Modules\Sales\Services\SalesResponsibility;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ⛔ বিক্রির কৃতিত্ব যেত বিল যিনি টাইপ করেছেন তাঁর — মালিক, ২৩ নভেম্বর ২০২৬ ([[SalesResponsibility]])।
 *
 * ⓘ আন্তর্জাতিক নিয়ম (SAP "sales employee", D365 "sales responsible", Odoo salesperson): বিক্রি দায়িত্বের মানুষের।
 *   ক্রম — ডিলারের বাঁধন › জায়গার বাঁধন (নিচ থেকে উপরে প্রথম যেখানে কেউ) › কোম্পানির সেটিং › কেউ নন।
 * ⛔ লাইভ ADI: DSM ২২ দেখার জন্য ASM-দের একই টেরিটরিতে বাঁধা — উপরওয়ালা সরাসরি কৃতিত্ব পান না, যোগফল পান।
 * ⛔ অন্য কোম্পানির কেউ কখনো ফেরেন না — বাঁধনে বা সেটিংয়ে ভুল করে বসে থাকলেও।
 */
final class TheSaleWentToWhoeverTypedTheBillTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private SalesResponsibility $who;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        // ⓘ পরিষ্কার মাঠ — ডেমোর কোনো বাঁধন উত্তর ঘোলা না করে
        DB::table('dealer_bindings')->where('company_id', $this->company->id)->delete();
        DB::table('area_bindings')->where('company_id', $this->company->id)->delete();
        DB::table('staff_supervisors')->where('company_id', $this->company->id)->delete();

        $this->who = app(SalesResponsibility::class);
        $this->who->forget();
    }

    public function test_dealer_beats_place_place_beats_default_default_beats_nobody(): void
    {
        $area = $this->place('SR-A', Location::AREA);
        $point = $this->place('SR-P', Location::POINT, $area);
        $dealer = $this->dealer('SR-D1', $point);
        $day = now()->toDateString();

        $this->assertNull($this->who->on($dealer, $day), '⛔ কেউ দায়িত্বে নেই, সেটিংও নেই — তবু কারো খাতায় উঠল।');
        $this->assertSame(['user_id' => null, 'via' => null, 'location_id' => null], $this->who->explain($dealer, $day));

        $fallback = $this->person();
        app(SettingsService::class)->set(SalesResponsibility::SETTING, $fallback->id);
        $this->who->forget();
        $this->assertSame($fallback->id, $this->who->on($dealer, $day), '⛔ কেউ দায়িত্বে নেই — কোম্পানির সেটিংয়ের মানুষ পেলেন না।');
        $this->assertSame('default', $this->who->explain($dealer, $day)['via']);

        $areaMan = $this->person();
        $this->bindArea($areaMan, $area, now()->subYear());
        $this->who->forget();
        $this->assertSame($areaMan->id, $this->who->on($dealer, $day), '⛔ পয়েন্টের উপরের এলাকায় বাঁধা মানুষ পেলেন না — সেটিং আগে খাটল।');
        $this->assertSame(['user_id' => $areaMan->id, 'via' => 'place', 'location_id' => $area->id], $this->who->explain($dealer, $day));

        $pointMan = $this->person();
        $this->bindArea($pointMan, $point, now()->subYear());
        $this->who->forget();
        $this->assertSame($pointMan->id, $this->who->on($dealer, $day), '⛔ নিজের পয়েন্টে বাঁধা মানুষ নন, উপরের এলাকার মানুষ পেলেন — প্রথম জায়গায় থামেনি।');

        $sr = $this->person();
        $this->bindDealer($sr, $dealer, now()->subYear());
        $this->who->forget();
        $this->assertSame(['user_id' => $sr->id, 'via' => 'dealer', 'location_id' => null], $this->who->explain($dealer, $day), '⛔ ডিলারে সরাসরি বাঁধা SR পেলেন না।');

        $other = $this->dealer('SR-D2', $point);
        $this->assertSame([$dealer => $sr->id, $other => $pointMan->id], $this->who->forMany([$dealer, $other], $day), '⛔ একসাথে চাইলে উত্তর আলাদা।');
    }

    public function test_a_handover_gives_each_day_to_whoever_held_it_that_day(): void
    {
        $dealer = $this->dealer('SR-H', null);
        $before = $this->person();
        $after = $this->person();

        $this->bindDealer($before, $dealer, now()->subMonths(3), now()->subMonth()->subDay());
        $this->bindDealer($after, $dealer, now()->subMonth());

        $this->assertSame($before->id, $this->who->on($dealer, now()->subMonths(2)), '⛔ হাতবদলের আগের বিক্রি নতুন জনের খাতায়।');
        $this->assertSame($after->id, $this->who->on($dealer, now()->subMonth()), '⛔ হাতবদলের দিনের বিক্রি পুরনো জনের খাতায়।');
        $this->assertSame($after->id, $this->who->on($dealer, now()->toDateString()));
        $this->assertNull($this->who->on($dealer, now()->subYear()), '⛔ বাঁধনের আগের দিনে কেউ পেলেন।');

        // ⓘ জায়গার বাঁধনেও একই — দিন ঠিক করে কে
        $point = $this->place('SR-HP', Location::POINT);
        $placed = $this->dealer('SR-H2', $point);
        $old = $this->person();
        $new = $this->person();
        $this->bindArea($old, $point, now()->subMonths(3), now()->subMonth()->subDay());
        $this->bindArea($new, $point, now()->subMonth());
        $this->who->forget();
        $this->assertSame($old->id, $this->who->on($placed, now()->subMonths(2)));
        $this->assertSame($new->id, $this->who->on($placed, now()->toDateString()));
    }

    public function test_the_supervisor_on_the_same_place_gets_no_direct_credit_but_his_own_place_does(): void
    {
        $region = $this->place('SR-R', Location::REGION);
        $shared = $this->place('SR-T1', Location::TERRITORY, $region);
        $alone = $this->place('SR-T2', Location::TERRITORY, $region);

        $dsm = $this->person();
        $rsm = $this->person();
        $asm = $this->person();
        StaffSupervisor::query()->create(['company_id' => $this->company->id, 'user_id' => $asm->id, 'supervisor_id' => $rsm->id]);
        StaffSupervisor::query()->create(['company_id' => $this->company->id, 'user_id' => $rsm->id, 'supervisor_id' => $dsm->id]);

        // ⓘ লাইভ ADI-র মতো: উপরওয়ালা আগে বাঁধা (দেখার জন্য), ASM পরে — "আগে শুরু" একা হলে DSM পেতেন
        $this->bindArea($dsm, $shared, now()->subYears(2));
        $this->bindArea($asm, $shared, now()->subYear());
        $this->bindArea($dsm, $alone, now()->subYears(2));

        $underShared = $this->dealer('SR-S1', $shared);
        $underAlone = $this->dealer('SR-S2', $alone);
        $day = now()->toDateString();

        $this->assertSame($asm->id, $this->who->on($underShared, $day), '⛔ একই টেরিটরিতে উপরওয়ালা (দুই ধাপ উপরে) সরাসরি কৃতিত্ব পেলেন, ASM নন।');
        $this->assertSame($dsm->id, $this->who->on($underAlone, $day), '⛔ উপরওয়ালার নিজের এলাকার ডিলারের বিক্রি তিনি পেলেন না।');
    }

    public function test_another_companys_person_never_gets_the_credit(): void
    {
        $foreignCompany = Company::query()->where('id', '<>', $this->company->id)->orderBy('id')->firstOrFail();
        $stranger = User::factory()->create(['current_company_id' => $foreignCompany->id, 'is_active' => true]);
        $stranger->companies()->attach($foreignCompany->id, ['is_active' => true]);

        $point = $this->place('SR-X', Location::POINT);
        $dealer = $this->dealer('SR-XD', $point);
        $day = now()->toDateString();

        // ⓘ ভুল করে বসা — পর্দা এগুলো দিত না, তাই সোজা টেবিলে
        DealerBinding::query()->create(['company_id' => $this->company->id, 'user_id' => $stranger->id, 'customer_id' => $dealer, 'starts_on' => now()->subYear()->toDateString()]);
        AreaBinding::query()->create(['company_id' => $this->company->id, 'user_id' => $stranger->id, 'location_id' => $point->id, 'starts_on' => now()->subYear()->toDateString()]);
        Setting::query()->updateOrCreate(
            ['company_id' => $this->company->id, 'key' => SalesResponsibility::SETTING],
            ['module' => 'sales', 'group' => 'entry', 'type' => 'integer', 'value' => (string) $stranger->id],
        );
        app(SettingsService::class)->flush();
        $this->who->forget();

        $this->assertSame($stranger->id, (int) app(SettingsService::class)->get(SalesResponsibility::SETTING), 'প্রস্তুতিটাই ভুল — সেটিংয়ে বাইরের মানুষ বসেনি।');
        $this->assertNull($this->who->on($dealer, $day), '⛔ অন্য কোম্পানির মানুষ এই কোম্পানির বিক্রির কৃতিত্ব পেলেন।');
        $this->assertSame([$dealer => null], $this->who->forMany([$dealer], $day));

        // ⓘ অন্য কোম্পানির ডিলার এই কোম্পানির প্রসঙ্গে — কারো নয়
        $foreignDealer = (int) DB::table('customers')->where('company_id', $foreignCompany->id)->value('id');
        if ($foreignDealer > 0) {
            $this->assertNull($this->who->on($foreignDealer, $day), '⛔ অন্য কোম্পানির ডিলারের বিক্রি এই কোম্পানির কারো খাতায়।');
        }

        $this->assertArrayNotHasKey((string) $stranger->id, SalesResponsibility::choices(), '⛔ সেটিংয়ের তালিকায় অন্য কোম্পানির মানুষ।');
    }

    private function place(string $code, string $level, ?Location $parent = null): Location
    {
        return Location::query()->create([
            'company_id' => $this->company->id, 'branch_id' => $this->company->defaultBranch()?->id, 'parent_id' => $parent?->id,
            'code' => $code, 'name_en' => $code, 'level' => $level, 'is_active' => true,
        ]);
    }

    private function dealer(string $code, ?Location $at): int
    {
        return (int) Customer::query()->create(['code' => $code, 'name_en' => $code, 'is_active' => true, 'location_id' => $at?->id])->id;
    }

    private function person(): User
    {
        $u = User::factory()->create(['current_company_id' => $this->company->id, 'is_active' => true]);
        $u->companies()->attach($this->company->id, ['is_active' => true]);

        return $u->fresh();
    }

    private function bindDealer(User $u, int $dealer, \DateTimeInterface $from, ?\DateTimeInterface $to = null): void
    {
        DealerBinding::query()->create([
            'company_id' => $this->company->id, 'user_id' => $u->id, 'customer_id' => $dealer,
            'starts_on' => $from->format('Y-m-d'), 'ends_on' => $to?->format('Y-m-d'),
        ]);
    }

    private function bindArea(User $u, Location $at, \DateTimeInterface $from, ?\DateTimeInterface $to = null): void
    {
        AreaBinding::query()->create([
            'company_id' => $this->company->id, 'user_id' => $u->id, 'location_id' => $at->id,
            'starts_on' => $from->format('Y-m-d'), 'ends_on' => $to?->format('Y-m-d'),
        ]);
    }
}
