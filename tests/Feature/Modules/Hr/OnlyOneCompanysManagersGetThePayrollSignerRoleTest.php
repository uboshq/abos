<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Hr;

use App\Console\Commands\GrantPayrollSigners;
use App\Core\Support\CompanyContext;
use App\Models\AuditTrail;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ⭐ বেতনের সইয়ের চাবি কেবল এক কোম্পানির Manager-দের হাতে — মালিক, ২১ অক্টোবর ২০২৬ ([[GrantPayrollSigners]])।
 *
 * ⓘ লাইভের মতো সাজানো: একটা খালি-কোম্পানির "Manager" সারি (সব কোম্পানিতে দেখা যায়) আর কোম্পানির নিজের "Manager"।
 * TDEPOT = UB, FMART = অন্য কোম্পানি।
 *
 *  · globalMgr — TDEPOT-এ খালি-কোম্পানির Manager            → পাবেন
 *  · ownMgr    — TDEPOT-এ TDEPOT-এর নিজের Manager            → পাবেন
 *  · otherMgr  — কেবল FMART-এ, দুই রকম Manager-ই              → পাবেন না
 *  · both      — দুই কোম্পানিরই সদস্য; FMART-এ Manager, TDEPOT-এ accountant → পাবেন না
 *  · clerk     — ডেমোর হিসাবরক্ষক, TDEPOT-এ accountant         → পাবেন না
 */
final class OnlyOneCompanysManagersGetThePayrollSignerRoleTest extends TestCase
{
    use RefreshDatabase;

    private Company $ub;

    private Company $other;

    private User $globalMgr;

    private User $ownMgr;

    private User $otherMgr;

    private User $both;

    private User $clerk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        CompanyContext::clear();

        $this->ub = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->other = Company::query()->where('code', 'FMART')->firstOrFail();

        // ⓘ লাইভের খালি-কোম্পানির সারি — Role::create() চলতি টিম বসাত, তাই সরাসরি
        $global = Role::query()->create(['name' => 'Manager', 'guard_name' => 'web', 'company_id' => null]);
        $ubOwn = $this->companyManager($this->ub);
        $otherOwn = $this->companyManager($this->other);

        $this->globalMgr = $this->person('Global Row Manager', [$this->ub]);
        $this->ownMgr = $this->person('Own Row Manager', [$this->ub]);
        $this->otherMgr = $this->person('Other Company Manager', [$this->other]);
        $this->both = $this->person('Manager Elsewhere Only', [$this->ub, $this->other]);
        $this->clerk = User::query()->where('email', 'accounts@abos.test')->firstOrFail();

        CompanyContext::forCompany($this->ub->id, function () use ($global, $ubOwn) {
            $this->globalMgr->assignRole($global);
            $this->ownMgr->assignRole($ubOwn);
            $this->both->assignRole('accountant');
        });

        CompanyContext::forCompany($this->other->id, function () use ($global, $otherOwn) {
            $this->otherMgr->assignRole($global);
            $this->otherMgr->assignRole($otherOwn);
            $this->both->assignRole($global);
        });

        CompanyContext::clear();
    }

    /** ⭐ --dry কিছু লেখে না, আর কারা পাবেন তা নাম ধরে বলে — ইমেইল নয়। */
    public function test_a_dry_run_lists_the_people_and_writes_nothing(): void
    {
        $before = $this->counts();

        $this->artisan('abos:grant-payroll-signers', ['--company' => 'TDEPOT', '--role' => 'Manager', '--dry' => true])
            ->expectsOutputToContain('Global Row Manager')
            ->expectsOutputToContain('Own Row Manager')
            ->expectsOutputToContain('তৈরি হবে')
            ->expectsOutputToContain('hr.payroll.manage (নতুন)')
            ->doesntExpectOutputToContain('Other Company Manager')
            ->doesntExpectOutputToContain('Manager Elsewhere Only')
            ->doesntExpectOutputToContain((string) $this->globalMgr->email)
            ->assertExitCode(0)
            ->run();

        $this->assertSame($before, $this->counts(), '⛔ --dry কিছু লিখেছে।');
        $this->assertFalse(Role::query()->where('name', GrantPayrollSigners::ROLE)->exists(), '⛔ --dry রোল বানিয়েছে।');
    }

    /** ⭐ আসল চালানোয় কেবল TDEPOT-এর দুই Manager চাবি পান — অন্য কোম্পানির Manager, আর TDEPOT-এর অন্য কেউ নন। */
    public function test_the_real_run_gives_exactly_this_companys_managers_the_keys(): void
    {
        $this->assertFalse($this->globalMgr->canInCompany($this->ub->id, 'hr.payroll.manage'), 'ⓘ শুরুতেই চাবি ছিল — দাবিটা কিছু মাপত না।');

        $this->run_();

        $role = Role::query()->where('name', GrantPayrollSigners::ROLE)->sole();
        $this->assertSame((int) $this->ub->id, (int) $role->company_id, '⛔ রোলটা এই কোম্পানির নয় — সব কোম্পানিতে দেখা যেত।');
        $this->assertEqualsCanonicalizing(GrantPayrollSigners::KEYS, $role->permissions()->pluck('name')->all());

        foreach ([$this->globalMgr, $this->ownMgr] as $user) {
            foreach (GrantPayrollSigners::KEYS as $key) {
                $this->assertTrue($user->fresh()->canInCompany($this->ub->id, $key), "⛔ {$user->name} TDEPOT-এ {$key} পাননি।");
            }
        }

        foreach ([$this->both, $this->clerk] as $user) {
            foreach (['hr.payroll.manage', 'hr.identity.view'] as $key) {
                $this->assertFalse($user->fresh()->canInCompany($this->ub->id, $key), "⛔ Manager নন, তবু {$user->name} TDEPOT-এ {$key} পেলেন।");
            }
        }

        foreach ([$this->otherMgr, $this->both] as $user) {
            foreach (['hr.payroll.manage', 'hr.identity.view', 'hr.salary.manage'] as $key) {
                $this->assertFalse($user->fresh()->canInCompany($this->other->id, $key), "⛔ অন্য কোম্পানিতে {$user->name} {$key} পেলেন।");
            }
        }

        // ⓘ দুই সই-রোলধারী TDEPOT-এ ঠিক দুইজন, আর বরাদ্দের সারি TDEPOT-এর
        $holders = DB::table('model_has_roles')->where('role_id', $role->id)->get();
        $this->assertEqualsCanonicalizing([$this->globalMgr->id, $this->ownMgr->id], $holders->pluck('model_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame([(int) $this->ub->id], $holders->pluck('company_id')->map(fn ($id) => (int) $id)->unique()->values()->all());

        // ⓘ সরাসরি চাবি নয় — সব চাবি রোলের ভিতর দিয়ে
        $this->assertSame(0, DB::table('model_has_permissions')->whereIn('model_id', [$this->globalMgr->id, $this->ownMgr->id])->count(), '⛔ সরাসরি চাবি বসেছে।');
    }

    /** ⭐ দ্বিতীয়বার কিছুই যোগ হয় না, আর কমান্ড সেটা বলে। */
    public function test_a_second_run_adds_nothing_and_says_so(): void
    {
        $this->run_();
        $after = $this->counts();

        $this->artisan('abos:grant-payroll-signers', ['--company' => 'TDEPOT', '--role' => 'Manager'])
            ->expectsOutputToContain('কিছু করার নেই')
            ->assertExitCode(0)
            ->run();

        $this->assertSame($after, $this->counts(), '⛔ দ্বিতীয় চালানো কিছু যোগ করেছে।');
    }

    /** ⭐ রোল তৈরির একটা, আর প্রতিটা বরাদ্দের একটা নিরীক্ষার সারি — TDEPOT-এর খাতায়। */
    public function test_the_audit_names_the_role_and_each_person(): void
    {
        $this->run_();

        $role = Role::query()->where('name', GrantPayrollSigners::ROLE)->sole();

        $made = AuditTrail::query()->withoutGlobalScopes()->where('action', GrantPayrollSigners::ROLE_MADE)->get();
        $this->assertCount(1, $made, '⛔ রোল তৈরির নিরীক্ষা নেই।');
        $this->assertSame((int) $role->id, (int) $made->first()->auditable_id);
        $this->assertSame((int) $this->ub->id, (int) $made->first()->company_id);

        $given = AuditTrail::query()->withoutGlobalScopes()->where('action', GrantPayrollSigners::GIVEN)->get();
        $this->assertEqualsCanonicalizing(
            [$this->globalMgr->id, $this->ownMgr->id],
            $given->pluck('auditable_id')->map(fn ($id) => (int) $id)->all(),
            '⛔ প্রতিটা বরাদ্দের নিরীক্ষা নেই।',
        );
        $this->assertSame([User::class], $given->pluck('auditable_type')->unique()->values()->all());
        $this->assertSame([(int) $this->ub->id], $given->pluck('company_id')->map(fn ($id) => (int) $id)->unique()->values()->all());

        foreach ([GrantPayrollSigners::ROLE_MADE, GrantPayrollSigners::GIVEN] as $action) {
            $this->assertLessThanOrEqual(24, strlen($action));
            foreach (['bn', 'en'] as $locale) {
                $this->assertNotSame('governance::action.'.$action, trans('governance::action.'.$action, [], $locale), "⛔ {$locale}: {$action}-এর নাম নেই।");
            }
        }
    }

    /** ⭐ রোলটা TDEPOT-এর রোলের পর্দায় দেখা যায়, FMART-এর পর্দায় নয়। */
    public function test_the_role_shows_on_this_companys_role_screen_only(): void
    {
        $this->run_();

        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        $owner->switchCompany($this->ub->id);
        $this->actingAs($owner->fresh())
            ->get(route('system_admin.role.index'))
            ->assertOk()
            ->assertSee(GrantPayrollSigners::ROLE);

        $owner->fresh()->switchCompany($this->other->id);
        $this->actingAs($owner->fresh())
            ->get(route('system_admin.role.index'))
            ->assertOk()
            ->assertDontSee(GrantPayrollSigners::ROLE);
    }

    /** ⭐ অচেনা কোম্পানি বা রোল — ত্রুটি, আর কিছু লেখা হয় না। */
    public function test_an_unknown_company_or_role_stops_with_no_writes(): void
    {
        $before = $this->counts();

        $this->artisan('abos:grant-payroll-signers', ['--company' => 'NOPE', '--role' => 'Manager'])->assertExitCode(1)->run();
        $this->artisan('abos:grant-payroll-signers', ['--company' => 'TDEPOT', '--role' => 'No Such Role'])->assertExitCode(1)->run();
        $this->artisan('abos:grant-payroll-signers', ['--company' => 'TDEPOT'])->assertExitCode(1)->run();

        $this->assertSame($before, $this->counts(), '⛔ ত্রুটির পরও কিছু লেখা হয়েছে।');
    }

    /** ⭐ একটা চাবি অনুমতির টেবিলে না থাকলে নাম ধরে থামে, কিছু লেখে না। */
    public function test_a_missing_key_stops_with_its_name(): void
    {
        Permission::query()->where('name', 'hr.identity.view')->delete();
        $before = $this->counts();

        $this->artisan('abos:grant-payroll-signers', ['--company' => 'TDEPOT', '--role' => 'Manager'])
            ->expectsOutputToContain('hr.identity.view')
            ->assertExitCode(1)
            ->run();

        $this->assertSame($before, $this->counts());
    }

    private function run_(): void
    {
        $this->artisan('abos:grant-payroll-signers', ['--company' => 'TDEPOT', '--role' => 'Manager'])->assertExitCode(0)->run();
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        return [
            'roles' => DB::table('roles')->count(),
            'model_has_roles' => DB::table('model_has_roles')->count(),
            'model_has_permissions' => DB::table('model_has_permissions')->count(),
            'role_has_permissions' => DB::table('role_has_permissions')->count(),
            'audit_trails' => DB::table('audit_trails')->count(),
        ];
    }

    private function companyManager(Company $company): Role
    {
        $found = Role::query()->where('name', 'Manager')->where('guard_name', 'web')->where('company_id', $company->id)->first();

        return $found ?? Role::query()->create(['name' => 'Manager', 'guard_name' => 'web', 'company_id' => $company->id]);
    }

    /** @param  list<Company>  $companies */
    private function person(string $name, array $companies): User
    {
        $user = User::factory()->create(['name' => $name, 'is_active' => true]);
        $user->companies()->attach(array_map(fn (Company $c) => $c->id, $companies));
        $user->switchCompany($companies[0]->id);

        return $user->fresh();
    }
}
