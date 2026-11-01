<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\SystemAdmin;

use App\Core\Engines\Audit\AuditEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DateFormat;
use App\Models\AuditTrail;
use App\Models\Company;
use App\Models\User;
use App\Modules\Hr\Models\Employee;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ⭐ প্রতিটা পাতায় "শেষ বদল", আর "বদলের ইতিহাস"-এ আগের-পরের মান খোঁজা যায় — কন্ট্রোল প্যানেলের পরিকল্পনা, মালিকের অনুমোদিত, ২১ অক্টোবর
 * ২০২৬, দফা ১৩; ব্যবহারকারীর ফর্মে "ইতিহাস" ট্যাব। ⛔ আগে কে কবে বদলালেন জানতে নিরীক্ষার খাতায় গিয়ে হাতে খুঁজতে হত।
 */
final class EveryPageSaysItsLastChangeTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
    }

    public function test_the_company_page_says_when_and_who_and_opens_the_history_only_for_auditors(): void
    {
        $this->actingAs($this->owner);
        $trail = app(AuditEngine::class)->record($this->company, 'updated', ['name' => ['Old Depot Name', 'New Depot Name']]);
        $this->assertNotNull($trail, 'ⓘ পরীক্ষার ভিত্তি: খাতায় সারি বসল।');

        $page = (string) $this->get(route('system_admin.company.edit', $this->company))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/data-last-change(?:(?!<\/p>).)*'.preg_quote(DateFormat::spoken($trail->created_at), '/').'(?:(?!<\/p>).)*'.preg_quote(e($this->owner->name), '/').'/su', $page, '⛔ কোম্পানির পাতায় শেষ বদল (কবে, কে) নেই।');
        $this->assertStringContainsString('data-change-history href="'.e(route('governance.audit.record', $trail->id)).'"', $page, '⛔ বদলের ইতিহাসের দরজা নেই।');

        $keeper = $this->userWith(['system_admin.company.manage']);
        $plain = (string) $this->actingAs($keeper)->get(route('system_admin.company.edit', $this->company))->assertOk()->getContent();
        $this->assertStringContainsString('data-last-change', $plain, '⛔ নিরীক্ষার চাবি ছাড়া শেষ বদলটুকুও দেখা যায় না।');
        $this->assertStringNotContainsString('data-change-history', $plain, '⛔ নিরীক্ষার চাবি ছাড়াও মানের খাতার দরজা খোলা।');
    }

    public function test_the_history_searches_old_and_new_values_but_never_a_hidden_one(): void
    {
        $this->actingAs($this->owner);
        $trail = app(AuditEngine::class)->record($this->company, 'updated', ['name' => ['Old Depot Name', 'New Depot Name']]);

        $hit = (string) $this->get(route('governance.audit.record', ['trail' => $trail->id, 'q' => 'Old Depot']))->assertOk()->getContent();
        $this->assertStringNotContainsString(e(__('governance::message.nothing_yet')), $hit, '⛔ আগের মানে খুঁজে বদলটা পাওয়া গেল না।');
        $miss = (string) $this->get(route('governance.audit.record', ['trail' => $trail->id, 'q' => 'nothing-like-this']))->assertOk()->getContent();
        $this->assertStringContainsString(e(__('governance::message.nothing_yet')), $miss, '⛔ না-মেলা খোঁজেও সব সারি এল — খোঁজা কাজ করে না।');

        // ⓘ ঢাকা ঘর: জাতীয় পরিচয়পত্র HR-এর পরিচয়ের চাবিতে — চাবি ছাড়া নিরীক্ষক খুঁজে "আছে কি না" জানতে পারবেন না
        $hidden = AuditTrail::query()->create(['company_id' => $this->company->id, 'user_id' => $this->owner->id, 'action' => 'updated',
            'auditable_type' => Employee::class, 'auditable_id' => 990001, 'label' => 'x']);
        $hidden->changes()->create(['company_id' => $this->company->id, 'field' => 'national_id', 'old_value' => null, 'new_value' => '1990123456789']);
        $hidden->changes()->create(['company_id' => $this->company->id, 'field' => 'designation', 'old_value' => null, 'new_value' => 'Storekeeper']);

        $auditor = $this->userWith(['governance.audit.view']);
        $this->actingAs($auditor);
        $this->assertFalse($auditor->can('hr.identity.view'), 'ⓘ পরীক্ষার ভিত্তি: নিরীক্ষকের পরিচয়ের চাবি নেই।');
        $leak = (string) $this->get(route('governance.audit.record', ['trail' => $hidden->id, 'q' => '1990123456789']))->assertOk()->getContent();
        $this->assertStringContainsString(e(__('governance::message.nothing_yet')), $leak, '⛔ ঢাকা ঘরের মানে খুঁজে মিল পাওয়া যায় — জাতীয় পরিচয়পত্র ফাঁস।');
        $open = (string) $this->get(route('governance.audit.record', ['trail' => $hidden->id, 'q' => 'Storekeeper']))->assertOk()->getContent();
        $this->assertStringNotContainsString(e(__('governance::message.nothing_yet')), $open, '⛔ খোলা ঘরের মানও খোঁজা যায় না।');
    }

    public function test_the_user_form_has_a_history_tab_with_the_changes_of_that_person(): void
    {
        $this->actingAs($this->owner);
        $person = User::query()->where('email', 'accounts@abos.test')->firstOrFail();
        app(AuditEngine::class)->record($person, 'updated', ['name' => ['Old', 'New']]);

        $html = (string) $this->get(route('system_admin.user.edit', $person))->assertOk()->getContent();
        $this->assertStringContainsString('data-user-tab-button="history"', $html, '⛔ ব্যবহারকারীর ফর্মে "ইতিহাস" ট্যাব নেই।');
        $this->assertMatchesRegularExpression('/data-user-tab="history"(?:(?!<\/section>).)*data-user-history-row(?:(?!<\/section>).)*'.preg_quote(e(AuditTrail::actionInWords('updated')), '/').'/su', $html, '⛔ ইতিহাস ট্যাবে এই মানুষের বদল নেই।');
    }

    /** @param list<string> $keys */
    private function userWith(array $keys): User
    {
        $role = Role::query()->forceCreate(['name' => 'Only '.implode(' ', $keys), 'guard_name' => 'web', 'company_id' => $this->company->id]);
        $role->givePermissionTo(array_map(fn (string $k) => Permission::findOrCreate($k, 'web'), $keys));
        $user = User::factory()->create(['current_company_id' => $this->company->id, 'current_branch_id' => $this->company->defaultBranch()?->id]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);
        $user->assignRole($role);

        return $user;
    }
}
