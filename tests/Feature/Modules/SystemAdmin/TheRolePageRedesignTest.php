<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\SystemAdmin;

use App\Core\Services\CompositeRoles;
use App\Core\Services\PermissionSyncer;
use App\Core\Services\RoleTerms;
use App\Core\Support\CompanyContext;
use App\Models\AuditTrail;
use App\Models\Company;
use App\Models\Notification;
use App\Models\User;
use App\Modules\SystemAdmin\Support\DutyClash;
use Database\Seeders\DemoSeeder;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ রোল ও অনুমতির নতুন পাতা — মালিকের অনুমোদিত নমুনা, ২০ নভেম্বর ২০২৬ ("ok")।
 *
 * এক সারির মাথা, চিকন কার্ড, ডানের কলাম নেই, রোলের ট্যাব; মডিউল এক সারিতে একটা আর শুরুতে সব বন্ধ; দায়িত্বের সংঘাত; কারণ ছাড়া
 * বদল নয়; ছাপুন · Excel; যৌথ রোল (চক্র নিষেধ); সময়-বাঁধা সদস্যপদ (আগের দিন খবর, শেষে নিজে ওঠে); মালিকের রোল অক্ষত।
 */
final class TheRolePageRedesignTest extends TestCase
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
    }

    public function test_the_page_has_the_one_row_head_slim_cards_role_tabs_and_no_right_column(): void
    {
        $role = $this->aRole('রোল-পাতা', ['sales.invoice.view']);
        $html = (string) $this->actingAs($this->owner)->get(route('system_admin.role.edit', $role))->assertOk()->getContent();

        // ⓘ মাথা: পাতার সাধারণ page-header (ব্যাখ্যা ⓘ-এ যায়), পাশে তিনটা পিল ট্যাব, ডানে ছাপুন · Excel · নতুন রোল
        $this->assertStringContainsString('data-page-subtitle', $html, '⛔ পাতাটা সাধারণ মাথা ব্যবহার করে না — ⓘ বসবে না।');
        foreach (['roles', 'users', 'compare'] as $pill) {
            $this->assertStringContainsString('data-tab="'.$pill.'"', $html, "⛔ মাথার পিল ট্যাব «{$pill}» নেই।");
        }
        $this->assertStringContainsString(e(route('system_admin.role.matrix', ['role' => $role, 'export' => 'print'])), $html, '⛔ ছাপুন নেই।');
        $this->assertStringContainsString(e(route('system_admin.role.matrix', ['role' => $role, 'export' => 'xlsx'])), $html, '⛔ Excel নেই।');

        // ⓘ পাঁচটা চিকন কার্ড, অন্য তালিকার `list-card`; "এই রোলে যাঁরা আছেন" নেই
        preg_match_all('/class="list-card[^"]*" data-role-card="([a-z-]+)"/', $html, $cards);
        $this->assertSame(['users', 'roles', 'unassigned', 'clash', 'last-change'], $cards[1], '⛔ কার্ডগুলো নমুনার পাঁচটা নয়।');

        // ⓘ ডানের আলাদা কলাম নেই — বাঁয়ের তালিকাই একমাত্র aside
        preg_match('/<main\b.*<\/main>/s', $html, $main);
        $this->assertSame(1, substr_count($main[0] ?? $html, '<aside'), '⛔ ডানের কলাম এখনো আছে।');

        foreach (['permissions', 'members', 'effective', 'history'] as $tab) {
            $this->assertStringContainsString('data-role-tab="'.$tab.'"', $html, "⛔ রোলের ট্যাব «{$tab}» নেই।");
        }
    }

    public function test_modules_sit_one_per_row_all_closed_with_no_arrow_and_one_open_at_a_time(): void
    {
        $role = $this->aRole('ভাঁজ', ['sales.invoice.view']);
        $html = (string) $this->actingAs($this->owner)->get(route('system_admin.role.edit', $role))->assertOk()->getContent();

        preg_match_all('/<details\b[^>]*data-permission-module="([a-z_]+)"[^>]*>/', $html, $modules);
        $this->assertGreaterThan(3, count($modules[0]), 'ⓘ পরীক্ষার ভিত্তি: কয়েকটা মডিউল।');

        foreach ($modules[0] as $tag) {
            $this->assertDoesNotMatchRegularExpression('/\sopen[\s>=]/', $tag, '⛔ শুরুতেই একটা মডিউল খোলা — নমুনায় সব বন্ধ।');
            // ⓘ ব্রাউজারের নিজের এক-খোলা ভাঁজ: একই নাম
            $this->assertStringContainsString('name="role-modules-perm"', $tag, '⛔ মডিউলগুলো এক দলের নয় — একটা খুললে আগেরটা বন্ধ হবে না।');
        }

        $this->assertStringNotContainsString('▸', $html, '⛔ তীর-চিহ্ন — নমুনায় নেই।');
        $this->assertStringContainsString(e(__('system_admin::permission.whole_column')), $html, '⛔ "পুরো কলাম"-এর সারি নেই।');
    }

    public function test_a_role_that_writes_and_approves_the_same_paper_is_a_duty_conflict(): void
    {
        $this->assertSame(['accounts.voucher'], DutyClash::of(['accounts.voucher.create', 'accounts.voucher.approve', 'accounts.voucher.view']));
        $this->assertSame(['finance.capital'], DutyClash::of(['finance.capital.manage', 'finance.capital.post']));
        $this->assertSame([], DutyClash::of(['accounts.voucher.create', 'accounts.count.approve']), '⛔ দুই আলাদা কাগজ — সংঘাত নয়।');

        $before = $this->clashCard();
        $role = $this->aRole('লেখে-আর-পাকা-করে', ['accounts.voucher.create', 'accounts.voucher.approve']);
        $this->aRole('কেবল-লেখে', ['accounts.voucher.create']);

        $this->assertSame($before + 1, $this->clashCard(), '⛔ "দায়িত্বের সংঘাত" কার্ড নতুন সংঘাতটা গোনেনি।');

        $html = (string) $this->get(route('system_admin.role.edit', $role))->assertOk()->getContent();
        $this->assertStringContainsString('data-role-sod="1"', $html, '⛔ রোলের নামের পাশে সংঘাতের চিহ্ন নেই।');

        // ⓘ কার্ডে চাপলে তালিকা কেবল সংঘাতের রোলগুলো
        $only = (string) $this->get(route('system_admin.role.edit', $role).'?only=clash')->assertOk()->getContent();
        $this->assertStringContainsString('লেখে-আর-পাকা-করে', $only);
        $this->assertStringNotContainsString('>কেবল-লেখে<', $only, '⛔ সংঘাত নেই এমন রোলও ছাঁকা তালিকায়।');
    }

    public function test_saving_a_change_needs_a_reason_and_the_reason_is_in_the_history(): void
    {
        $role = $this->aRole('কারণ', ['sales.invoice.view']);
        $this->actingAs($this->owner);

        $this->put(route('system_admin.role.update', $role), ['name' => 'কারণ', 'permissions' => ['sales.invoice.view', 'sales.order.view']])
            ->assertSessionHasErrors('reason');
        $this->assertFalse($role->fresh()->hasPermissionTo('sales.order.view'), '⛔ কারণ ছাড়াই বদল বসে গেল।');

        // ⓘ কিছু না বদলালে কারণ লাগে না
        $this->put(route('system_admin.role.update', $role), ['name' => 'কারণ', 'permissions' => ['sales.invoice.view']])
            ->assertSessionHasNoErrors();

        $this->put(route('system_admin.role.update', $role), [
            'name' => 'কারণ', 'permissions' => ['sales.invoice.view', 'sales.order.view'], 'reason' => 'নতুন কাউন্টারের জন্য',
        ])->assertSessionHasNoErrors();

        $this->assertTrue($role->fresh()->hasPermissionTo('sales.order.view'));
        $trail = AuditTrail::query()->where('auditable_type', Role::class)->where('auditable_id', $role->id)
            ->where('action', 'role_permissions_changed')->latest('id')->firstOrFail();
        $this->assertSame('নতুন কাউন্টারের জন্য', $trail->reason, '⛔ কারণটা খাতায় ওঠেনি।');

        $html = (string) $this->get(route('system_admin.role.edit', ['role' => $role, 'tab' => 'history']))->assertOk()->getContent();
        $this->assertStringContainsString('নতুন কাউন্টারের জন্য', $html, '⛔ বদলের ইতিহাসে কারণ নেই।');
    }

    public function test_print_and_excel_carry_the_whole_matrix_of_the_role(): void
    {
        $role = $this->aRole('ছাপা', ['sales.invoice.view']);
        $this->actingAs($this->owner);

        $screen = (string) $this->get(route('system_admin.role.matrix', $role))->assertOk()->getContent();
        $this->assertGreaterThan(20, substr_count($screen, 'data-role-matrix-row'), '⛔ ছকের সারি নেই।');
        $this->assertStringContainsString('✓', $screen, '⛔ দেওয়া অনুমতির ✓ নেই।');

        $xlsx = $this->get(route('system_admin.role.matrix', ['role' => $role, 'export' => 'xlsx']))->assertOk();
        $this->assertStringContainsString('spreadsheetml', (string) $xlsx->headers->get('Content-Type'), '⛔ Excel ফাইল নয়।');

        $elsewhere = Role::query()->forceCreate(['name' => 'অন্য', 'guard_name' => 'web',
            'company_id' => Company::query()->whereKeyNot($this->company->id)->value('id') ?? Company::factory()->create()->id]);
        $this->get(route('system_admin.role.matrix', $elsewhere))->assertNotFound();
    }

    public function test_a_composite_role_grants_what_its_roles_grant_follows_them_and_refuses_a_loop(): void
    {
        $this->actingAs($this->owner);
        $a = $this->aRole('ক-রোল', ['sales.invoice.view']);
        $b = $this->aRole('খ-রোল', ['sales.order.view']);

        $this->post(route('system_admin.role.store'), ['name' => 'যৌথ-গ', 'kind' => 'composite', 'includes' => [$a->id, $b->id]])
            ->assertSessionHasNoErrors();
        $composite = Role::query()->where('company_id', $this->company->id)->where('name', 'যৌথ-গ')->firstOrFail();
        $this->assertEqualsCanonicalizing(['sales.invoice.view', 'sales.order.view'], $composite->permissions->pluck('name')->all());

        // ⓘ যৌথ রোলের সদস্য ভেতরের রোলের কাজ পারেন
        $member = $this->aMember($composite);
        $this->assertTrue($member->can('sales.order.view'), '⛔ যৌথ রোলের সদস্য ভেতরের রোলের কাজ পারেন না।');

        // ⓘ ভেতরের রোল বদলালে যৌথটাও সাথে সাথে
        $this->put(route('system_admin.role.update', $a), [
            'name' => 'ক-রোল', 'permissions' => ['sales.invoice.view', 'sales.challan.view'], 'reason' => 'চালানও দেখবেন',
        ])->assertSessionHasNoErrors();
        $this->assertTrue($composite->fresh()->hasPermissionTo('sales.challan.view'), '⛔ ভেতরের রোল বদলাল, যৌথ রোল বদলাল না।');

        // ⛔ চক্র: ক-এর ভেতরে যৌথ-গ বসালে যৌথ-গ → ক → যৌথ-গ
        app(CompositeRoles::class)->forget();
        $this->put(route('system_admin.role.includes', $a), ['includes' => [$composite->id]])->assertSessionHasErrors('includes');
        $this->assertSame([], app(CompositeRoles::class)->includedIds((int) $a->id), '⛔ চক্রটা বসে গেল।');
        $this->assertTrue(app(CompositeRoles::class)->wouldCycle((int) $a->id, [(int) $composite->id]));

        // ⓘ ভেতর থেকে সরালে সদস্যের ক্ষমতাও যায়
        $this->put(route('system_admin.role.includes', $composite), ['includes' => [$a->id], 'reason' => 'অর্ডার আর নয়'])->assertSessionHasNoErrors();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertFalse($member->fresh()->can('sales.order.view'), '⛔ রোল সরানোর পরেও সদস্য আগের কাজ পারেন।');

        // ⓘ সদস্য ট্যাবে: ক-রোলের সদস্য তালিকায় যৌথ-গ-এর মানুষটা, "ভেতর দিয়ে"
        $html = (string) $this->get(route('system_admin.role.edit', ['role' => $a, 'tab' => 'members']))->assertOk()->getContent();
        $this->assertStringContainsString(e(__('system_admin::permission.member_via_composite', ['role' => 'যৌথ-গ'])), $html);
    }

    public function test_a_composite_role_may_not_hold_the_owner_role_nor_hand_out_keys_its_maker_lacks(): void
    {
        $ownerRole = Role::query()->where('company_id', $this->company->id)->where('name', PermissionSyncer::SUPER_ADMIN_ROLE)->firstOrFail();

        $this->actingAs($this->owner)
            ->post(route('system_admin.role.store'), ['name' => 'মালিক-সহ', 'kind' => 'composite', 'includes' => [$ownerRole->id]])
            ->assertSessionHasErrors('includes');
        $this->assertNull(Role::query()->where('name', 'মালিক-সহ')->first());

        // ⓘ নিজের হাতে নেই এমন চাবি রোল মিলিয়েও দেওয়া যায় না
        $rich = $this->aRole('ধনী', ['accounts.count.approve']);
        $keeper = $this->aKeeper();
        $this->actingAs($keeper)
            ->post(route('system_admin.role.store'), ['name' => 'ঘুরপথ', 'kind' => 'composite', 'includes' => [$rich->id]])
            ->assertSessionHasErrors('permissions');
    }

    public function test_the_owner_role_stays_untouchable(): void
    {
        $ownerRole = Role::query()->where('company_id', $this->company->id)->where('name', PermissionSyncer::SUPER_ADMIN_ROLE)->firstOrFail();
        $before = $ownerRole->permissions->count();
        $other = $this->aRole('সাধারণ', ['sales.invoice.view']);
        $this->actingAs($this->owner);

        $this->put(route('system_admin.role.includes', $ownerRole), ['includes' => [$other->id]])->assertSessionHasErrors('name');
        $this->put(route('system_admin.role.term', ['role' => $ownerRole, 'user' => $this->owner->id]), ['valid_until' => now()->addDays(3)->toDateString()])
            ->assertSessionHasErrors('name');
        $this->get(route('system_admin.role.edit', $ownerRole))->assertSessionHasErrors('name');

        $this->assertSame($before, $ownerRole->fresh()->permissions->count());
        $this->assertTrue($this->owner->fresh()->hasRole(PermissionSyncer::SUPER_ADMIN_ROLE), '⛔ মালিক নিজের রোল হারালেন।');
    }

    public function test_a_time_bound_role_warns_the_day_before_and_leaves_by_itself(): void
    {
        Carbon::setTestNow('2026-11-20 09:00:00');
        $role = $this->aRole('ছুটির-বদলি', ['sales.order.view']);
        $member = $this->aMember($role);
        $this->actingAs($this->owner);

        $this->put(route('system_admin.role.term', ['role' => $role, 'user' => $member->id]), [
            'valid_from' => '2026-11-18', 'valid_until' => '2026-11-21', 'note' => 'ছুটির বদলি',
        ])->assertSessionHasNoErrors();

        // ⛔ অতীতের শেষ দিন আর শুরুর আগের শেষ দিন — বসে না
        $this->put(route('system_admin.role.term', ['role' => $role, 'user' => $member->id]), ['valid_until' => '2026-11-19'])
            ->assertSessionHasErrors('valid_until');

        $html = (string) $this->get(route('system_admin.role.edit', ['role' => $role, 'tab' => 'members']))->assertOk()->getContent();
        $this->assertStringContainsString(e(__('system_admin::permission.term_state_ending', ['days' => 1])), $html, '⛔ "১ দিন পরে শেষ" দেখায় না।');

        // ⓘ শেষের আগের দিন: মানুষটা আর মালিক খবর পান — একবারই
        $terms = app(RoleTerms::class);
        $this->assertSame(1, $terms->due(Carbon::parse('2026-11-20'))['warned']);
        $this->assertSame(0, $terms->due(Carbon::parse('2026-11-20'))['warned'], '⛔ একই খবর দুইবার।');
        $told = Notification::query()->withoutGlobalScopes()->where('type', RoleTerms::ENDING)->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        $this->assertContains((int) $member->id, $told, '⛔ মানুষটা খবর পাননি।');
        $this->assertContains((int) $this->owner->id, $told, '⛔ মালিক খবর পাননি।');

        // ⓘ শেষ দিনেও রোল থাকে; পরদিন নিজে ওঠে
        $this->assertSame(0, $terms->due(Carbon::parse('2026-11-21'))['ended']);
        $this->assertTrue($member->fresh()->hasRole($role));

        $this->travelTo(Carbon::parse('2026-11-22 00:05:00'));
        $this->artisan('abos:role-terms')->assertSuccessful();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->assertFalse($member->fresh()->hasRole($role), '⛔ মেয়াদ পেরোনোর পরেও রোলটা আছে।');
        $this->assertFalse($member->fresh()->can('sales.order.view'));
        $this->assertSame('ended', DB::table(RoleTerms::TABLE)->where('company_id', $this->company->id)->where('user_id', $member->id)->value('state'));
        $this->assertTrue(AuditTrail::query()->where('auditable_id', $role->id)->where('action', 'role_term_ended')->exists());
    }

    public function test_a_role_past_its_term_is_taken_at_sign_in_even_if_the_night_job_did_not_run(): void
    {
        $role = $this->aRole('রাতের-কাজ-চলেনি', ['sales.order.view']);
        $member = $this->aMember($role);

        DB::table(RoleTerms::TABLE)->insert([
            'public_id' => (string) \Illuminate\Support\Str::uuid7(), 'company_id' => $this->company->id, 'user_id' => $member->id,
            'role_id' => $role->id, 'valid_until' => now()->subDays(2)->toDateString(), 'state' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        event(new Login('web', $member, false));
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->assertFalse($member->fresh()->hasRole($role), '⛔ লগইনে মেয়াদ পেরোনো রোল উঠল না।');
    }

    public function test_a_future_start_waits_and_the_role_arrives_on_its_day(): void
    {
        Carbon::setTestNow('2026-11-20 09:00:00');
        $role = $this->aRole('আসবে', ['sales.order.view']);
        $member = $this->aMember($role);

        $this->actingAs($this->owner)
            ->put(route('system_admin.role.term', ['role' => $role, 'user' => $member->id]), ['valid_from' => '2026-11-25'])
            ->assertSessionHasNoErrors();
        $this->assertFalse($member->fresh()->hasRole($role), '⛔ শুরুর দিনের আগেই রোল আছে।');

        $this->assertSame(1, app(RoleTerms::class)->due(Carbon::parse('2026-11-25'))['started']);
        $this->assertTrue($member->fresh()->hasRole($role), '⛔ শুরুর দিনে রোল বসল না।');
    }

    /** @param  list<string>  $permissions */
    private function aRole(string $name, array $permissions): Role
    {
        $role = Role::create(['name' => $name, 'guard_name' => 'web']);
        $role->syncPermissions(array_map(fn (string $p) => Permission::findOrCreate($p, 'web'), $permissions));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $role->fresh();
    }

    private function aMember(Role $role): User
    {
        $user = User::factory()->create(['current_company_id' => $this->company->id, 'is_active' => true]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);
        $user->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    private function aKeeper(): User
    {
        $keeper = $this->aMember($this->aRole('রোল-রক্ষক', ['system_admin.role.manage', 'sales.invoice.view']));

        return $keeper;
    }

    private function clashCard(): int
    {
        $html = (string) $this->actingAs($this->owner)->get(route('system_admin.role.index'))->assertOk()->getContent();
        preg_match('/data-role-card="clash"(?:(?!<\/a>).)*class="list-card-value num">(\d+)</s', $html, $m);
        $this->assertNotEmpty($m, '⛔ "দায়িত্বের সংঘাত" কার্ড নেই।');

        return (int) $m[1];
    }
}
