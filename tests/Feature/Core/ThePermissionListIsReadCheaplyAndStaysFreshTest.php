<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Security\CheapPermissionRegistrar;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\ReportRun;
use App\Models\User;
use App\Modules\SystemAdmin\Services\ScheduledReportRunner;
use App\Modules\SystemAdmin\Services\ScheduleService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ অনুমতি-তালিকা সস্তায় পড়া হয়, আর একটা চাবিও বাসি হয় না (২৪ নভেম্বর ২০২৬; [[CheapPermissionRegistrar]])।
 *
 * দাবি: (১) spatie-র নিজের তালিকার হুবহু সমান, তিন কোয়েরিতে; (২) এক প্রসেসে দুই কোম্পানি — A-র চাবি B-তে নেই, দুই দিকেই; সুপার অ্যাডমিন
 * দুটোতেই; (৩) অন্য প্রসেসে বদলানো রোল পরের প্রসঙ্গেই দেখা যায় — দেওয়া আর কেড়ে নেওয়া (কিউ-কর্মী); (৪) ওয়েবে কোম্পানি বদলালে;
 * (৫) নির্ধারিত রিপোর্ট পরপর দুই কোম্পানির — গোপন কলাম কেবল যেখানে চাবি (৭ সেপ্টেম্বর)।
 */
final class ThePermissionListIsReadCheaplyAndStaysFreshTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'inventory.report';

    private Company $a;

    private Company $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->a = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->b = Company::query()->where('code', 'FMART')->firstOrFail();
        CompanyContext::set($this->a->id, $this->a->defaultBranch()?->id);
    }

    public function test_the_list_is_spaties_own_and_takes_three_questions(): void
    {
        $this->assertInstanceOf(CheapPermissionRegistrar::class, app(PermissionRegistrar::class));
        Permission::findOrCreate('claim.held_by_no_role', 'web');

        $read = function (PermissionRegistrar $registrar): array {
            $registrar->forgetCachedPermissions();

            return $registrar->getPermissions()->mapWithKeys(fn ($p) => [$p->getKey() => [$p->getAttributes(),
                $p->roles->sortBy('id')->map(fn ($r) => $r->getAttributes())->values()->all()]])->sortKeys()->all();
        };

        $own = $read($this->app->make(CheapPermissionRegistrar::class));
        $this->assertGreaterThan(100, count($own));
        $this->assertGreaterThan(100, array_sum(array_map(fn ($x) => count($x[1]), $own)), 'প্রস্তুতিটাই ভুল — রোলের জোড়া নেই।');
        $this->assertEquals($read(new PermissionRegistrar(app('cache'))), $own, '⛔ তালিকা spatie-রটার সমান নয়।');

        $asked = [];
        DB::listen(function ($q) use (&$asked): void {
            if (preg_match('/permissions|roles/', $q->sql) === 1) {
                $asked[] = $q->sql;
            }
        });
        $read($this->app->make(CheapPermissionRegistrar::class));
        $this->assertCount(3, $asked, implode("\n", $asked));
    }

    public function test_one_process_two_companies_keeps_each_key_at_home(): void
    {
        $user = $this->person();
        $inA = $this->role('only-a', $this->a, [self::KEY]);
        $inB = $this->role('only-b', $this->b, ['customer.report']);
        $this->seat($user, $this->a, $inA);
        $this->seat($user, $this->b, $inB);

        foreach ([1, 2] as $round) {
            $this->assertTrue($this->can($user, $this->a, self::KEY), "A, ঘুরে {$round}");
            $this->assertFalse($this->can($user, $this->a, 'customer.report'), "⛔ B-র চাবি A-তে, ঘুরে {$round}");
            $this->assertTrue($this->can($user, $this->b, 'customer.report'), "B, ঘুরে {$round}");
            $this->assertFalse($this->can($user, $this->b, self::KEY), "⛔ A-র চাবি B-তে, ঘুরে {$round}");
        }

        $held = fn (Company $c, string $k): bool => $user->canInCompany($c->id, $k);
        $this->assertSame([true, false, false, true], [$held($this->a, self::KEY), $held($this->b, self::KEY), $held($this->a, 'customer.report'), $held($this->b, 'customer.report')]);

        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        foreach ([$this->a, $this->b] as $c) {
            $this->assertTrue($this->can($owner, $c, self::KEY), '⛔ সুপার অ্যাডমিন আটকে গেলেন।');
        }
    }

    public function test_a_role_changed_by_another_process_is_seen_at_the_next_context(): void
    {
        $user = $this->person();
        $role = $this->role('worker-role', $this->a, []);
        $this->seat($user, $this->a, $role);
        $this->assertFalse($this->can($user, $this->a, self::KEY), 'প্রস্তুতিটাই ভুল।');

        // ⓘ অন্য প্রসেস: টেবিলে লেখা হলো, এই প্রসেসের spatie কিছু জানে না — কিউ-কর্মীর পরের কাজ নিজের প্রসঙ্গ বসায়
        $pair = ['permission_id' => Permission::findOrCreate(self::KEY, 'web')->id, 'role_id' => $role->id];
        app(PermissionRegistrar::class)->getPermissions();
        DB::table('role_has_permissions')->insert($pair);
        $this->assertTrue($this->can($user, $this->a, self::KEY), '⛔ দেওয়া চাবি পরের কাজেও নেই।');

        DB::table('role_has_permissions')->where($pair)->delete();
        $this->assertFalse($this->can($user, $this->a, self::KEY), '⛔ কেড়ে নেওয়া চাবি বাসি রইল।');
    }

    public function test_the_web_switching_company_changes_what_is_allowed(): void
    {
        Route::middleware(['web', 'auth', 'can:'.self::KEY])->get('/claim-key-door', fn () => 'in');
        $user = $this->person();
        $this->seat($user, $this->a, $this->role('web-a', $this->a, [self::KEY]));
        $this->seat($user, $this->b, $this->role('web-b', $this->b, ['customer.report']));

        foreach ([[$this->a, 200], [$this->b, 403], [$this->a, 200]] as [$company, $status]) {
            User::query()->whereKey($user->id)->update(['current_company_id' => $company->id, 'current_branch_id' => $company->defaultBranch()?->id]);
            $this->app['auth']->forgetGuards();
            $this->actingAs(User::query()->findOrFail($user->id));
            $this->get('/claim-key-door')->assertStatus($status);
        }
    }

    public function test_two_companies_reports_in_one_run_keep_the_secret_column_at_home(): void
    {
        app(ReportEngine::class)->register(new ReportDefinition(
            key: 'claim_secret_column', title: 'Secret',
            query: fn (array $filters) => DB::query()->selectRaw("'row' as name, 98765 as cost"),
            columns: [['key' => 'name', 'label' => 'Name', 'type' => ReportColumn::TEXT],
                ['key' => 'cost', 'label' => 'Cost', 'type' => ReportColumn::MONEY, 'permission' => self::KEY]],
            filters: [], permission: 'customer.report',
        ));
        Storage::fake('local');
        $user = $this->person();
        $common = ['customer.report', 'system_admin.reports.schedule', 'core.export'];
        $this->seat($user, $this->a, $this->role('run-a', $this->a, [...$common, self::KEY]));
        $this->seat($user, $this->b, $this->role('run-b', $this->b, $common));

        $schedules = [];
        foreach ([$this->a, $this->b] as $c) {
            CompanyContext::set($c->id, $c->defaultBranch()?->id);
            $this->actingAs(User::query()->findOrFail($user->id));
            $schedules[] = app(ScheduleService::class)->create(['report_key' => 'claim_secret_column', 'frequency' => 'daily', 'format' => 'csv']);
        }
        CompanyContext::clear();
        Auth::logout();
        foreach ($schedules as $s) {
            $s->forceFill(['next_run_at' => now()->subMinute()])->save();
        }

        app(ScheduledReportRunner::class)->runDue();

        $files = array_map(function ($s): string {
            $run = ReportRun::withoutGlobalScope('company')->where('report_schedule_id', $s->id)->firstOrFail();

            return (string) Storage::disk('local')->get((string) $run->file_path);
        }, $schedules);
        $plain = array_map(fn (string $f): string => str_replace(',', '', $f), $files);
        $this->assertStringContainsString('98765', $plain[0], '⛔ A-তে চাবি আছে, তবু কলাম নেই।');
        $this->assertStringContainsString('row', $plain[1], 'প্রস্তুতিটাই ভুল — B-র ফাইল খালি।');
        $this->assertStringNotContainsString('98765', $plain[1], '⛔ B-তে চাবি নেই, তবু গোপন কলাম গেল।');
    }

    private function person(): User
    {
        $user = User::factory()->create(['is_active' => true, 'current_company_id' => $this->a->id, 'current_branch_id' => $this->a->defaultBranch()?->id]);
        foreach ([$this->a, $this->b] as $c) {
            $user->companies()->attach($c->id, ['is_active' => true]);
        }

        return $user;
    }

    /** @param  list<string>  $keys */
    private function role(string $name, Company $company, array $keys): Role
    {
        return CompanyContext::forCompany($company->id, function () use ($name, $company, $keys): Role {
            $role = Role::query()->create(['name' => $name, 'guard_name' => 'web', 'company_id' => $company->id]);
            $role->syncPermissions(array_map(fn (string $k) => Permission::findOrCreate($k, 'web'), $keys));

            return $role;
        });
    }

    private function seat(User $user, Company $company, Role $role): void
    {
        CompanyContext::forCompany($company->id, fn () => User::query()->findOrFail($user->id)->assignRole($role));
    }

    /** ⓘ পরের কাজের মতো — নিজের প্রসঙ্গ, টাটকা মানুষ */
    private function can(User $user, Company $company, string $key): bool
    {
        return (bool) CompanyContext::forCompany($company->id, fn () => Gate::forUser(User::query()->findOrFail($user->id))->check($key));
    }
}
