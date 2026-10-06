<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Services\PermissionSyncer;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⛔ নতুন কোম্পানিই ছিল সব কোম্পানির চাবি — পুরো ERP অডিট, ৬ অক্টোবর ২০২৬ (SystemAdmin ⛔১)।
 *
 * শিকলটা: company.manage-ধারী (শাখার মেনুও এই চাবিতে) নতুন কোম্পানি খুলতেন — সেখানে নিজে super_admin; তারপর বাকি
 * কোম্পানিগুলো বন্ধ করতেন; ব্যাকআপের নিয়ম গুনত কেবল চালুগুলো — ফলে পুরো ডাটাবেস (সব প্রতিষ্ঠান) তাঁর। আর বন্ধ
 * কোম্পানিতেও মানুষ আগের মতো কাজ করে যেতেন, কেউ টেরও পেতেন না।
 *
 * দাবি — একই মানুষ দুইবার:
 *   · company.manage আছে কিন্তু সব কোম্পানির মালিক নন → কোম্পানি খোলা আর বন্ধ ৪০৩, কিছুই বদলায় না;
 *     সব কোম্পানিতে super_admin হলে → দুটোই হয়, আর বন্ধ কোম্পানি আবার চালুও করা যায়
 *   · বন্ধ কোম্পানিতে সদস্যপদ থাকলেও ঢোকা নয় — পরের অনুরোধ চালু কোম্পানিতে নামায়
 */
final class ANewCompanyWasTheKeyToEveryCompanyTest extends TestCase
{
    use RefreshDatabase;

    private Company $home;

    private Company $other;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->home = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->other = Company::query()->where('is_active', true)->where('id', '<>', $this->home->id)->orderBy('id')->firstOrFail();

        $this->manager = User::factory()->create(['is_active' => true, 'current_company_id' => $this->home->id]);
        $this->manager->companies()->attach([$this->home->id => ['is_active' => true], $this->other->id => ['is_active' => true]]);
        foreach ([$this->home, $this->other] as $company) {
            CompanyContext::forCompany($company->id, fn () => $this->manager->givePermissionTo('system_admin.company.manage'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        CompanyContext::set($this->home->id, $this->home->defaultBranch()?->id);
    }

    public function test_only_the_owner_of_every_company_opens_or_closes_a_company(): void
    {
        $before = Company::query()->count();

        $this->actingAs($this->manager->fresh())->post(route('system_admin.company.store'), $this->newCompany('GRAB'))->assertForbidden();
        $this->assertSame($before, Company::query()->count(), '⛔ company.manage দিয়েই নতুন কোম্পানি খোলা গেল — সেখানে নিজে super_admin।');

        $this->actingAs($this->manager->fresh())->post(route('system_admin.company.toggle', $this->other))->assertNotFound();
        $this->assertTrue($this->other->fresh()->is_active, '⛔ company.manage দিয়েই অন্য কোম্পানি বন্ধ করা গেল।');

        // ⭐ একই মানুষ, এবার প্রতিটা কোম্পানির super_admin — গোটা ব্যবস্থার মালিক
        $this->superAdminEverywhere($this->manager);

        $this->actingAs($this->manager->fresh())->post(route('system_admin.company.store'), $this->newCompany('MINE'))->assertRedirect();
        $this->assertSame($before + 1, Company::query()->count());

        $this->actingAs($this->manager->fresh())->post(route('system_admin.company.toggle', $this->other))->assertRedirect();
        $this->assertFalse($this->other->fresh()->is_active);

        // ⓘ বন্ধ কোম্পানিতে আর ঢোকা যায় না — তবু মালিক সেটা আবার চালু করতে পারেন
        $this->actingAs($this->manager->fresh())->post(route('system_admin.company.toggle', $this->other))->assertRedirect();
        $this->assertTrue($this->other->fresh()->is_active, '⛔ বন্ধ কোম্পানি আর চালু করা গেল না।');
    }

    public function test_nobody_works_in_a_closed_company(): void
    {
        // ⓘ ছোট আইডির কোম্পানিটা বন্ধ — নইলে "প্রথম কোম্পানি" হিসেবে চালুটা এমনিতেই আসত, ছাঁকনি না থাকলেও
        [$closed, $open] = $this->home->id < $this->other->id ? [$this->home, $this->other] : [$this->other, $this->home];
        $this->manager->forceFill(['current_company_id' => $closed->id])->save();
        $this->assertTrue($this->manager->fresh()->canAccessCompany($closed->id));

        $closed->forceFill(['is_active' => false])->save();

        $this->assertFalse($this->manager->fresh()->canAccessCompany($closed->id), '⛔ বন্ধ কোম্পানিতে এখনো ঢোকা যায়।');
        $this->actingAs($this->manager->fresh())->get('/')->assertSuccessful();
        $this->assertSame($open->id, (int) $this->manager->fresh()->current_company_id, '⛔ বন্ধ কোম্পানিতেই রয়ে গেলেন।');
    }

    /** @return array<string, string> */
    private function newCompany(string $code): array
    {
        return [
            'code' => $code,
            'name_en' => $code.' Distribution',
            'branch_code' => 'MAIN',
            'branch_name_en' => 'Head Office',
            'year_name' => '2026-2027',
            'year_starts_on' => '2026-07-01',
            'year_ends_on' => '2027-06-30',
        ];
    }

    private function superAdminEverywhere(User $user): void
    {
        foreach (Company::query()->get() as $company) {
            $role = Role::query()->where('company_id', $company->id)->where('name', PermissionSyncer::SUPER_ADMIN_ROLE)->first();
            if ($role !== null) {
                CompanyContext::forCompany($company->id, fn () => $user->unsetRelation('roles')->assignRole($role));
                $user->companies()->syncWithoutDetaching([$company->id]);
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
