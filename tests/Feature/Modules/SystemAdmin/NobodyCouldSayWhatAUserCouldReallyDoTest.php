<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\SystemAdmin;

use App\Core\Services\PermissionSyncer;
use App\Core\Support\CompanyContext;
use App\Core\Support\RoleLabel;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * একজন ব্যবহারকারী আসলে কী পারেন — আর সেটা কোথা থেকে এল।
 *
 * ── ⭐ মালিকের স্পেক §৮, ২৪ সেপ্টেম্বর ২০২৬ ───────────────────────────
 * ⓘ স্পেক এই ঘরটাকে *"সবচেয়ে দরকারি"* বলে, কারণ সাপোর্টে সবচেয়ে বেশি
 * আসা প্রশ্নটাই এটা: *"সে কেন এই পাতাটা দেখতে পাচ্ছে না?"*
 *
 * ── ⚠️ কেন কেবল ✓/✕ যথেষ্ট নয় ────────────────────────────────────────
 * স্পেকের দাগানো লাইন: *"প্রতিটা সারির পাশে 'কোথা থেকে এল' লিখতেই হবে।
 * কেবল ✓/✕ দেখালে প্রশ্নটার উত্তর মেলে না, আর পর্দাটা বানিয়েও লাভ হয় না।"*
 * ⭐ তাই এখানকার প্রতিটা দাবি **উৎসটাও** মাপে, কেবল সংখ্যাটা নয়।
 */
final class NobodyCouldSayWhatAUserCouldReallyDoTest extends TestCase
{
    use RefreshDatabase;

    private User $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->subject = User::query()
            ->where('email', '!=', 'owner@abos.test')
            ->whereHas('companies', fn ($q) => $q->whereKey($company->id))
            ->firstOrFail();
    }

    /**
     * ⭐ সারিটা বলে কয়টা, আর বলে কোন রোল থেকে।
     *
     * ── ⚠️ দুইটা দাবি একসাথে, আর সেটা ইচ্ছাকৃত ──────────────────────
     * ⛔ কেবল সংখ্যাটা মাপলে একটা পর্দা সবুজ থাকত যেখানে উৎসের লাইনটা
     * নেই — আর ঠিক সেই লাইনটার জন্যই ঘরটা বানানো।
     */
    public function test_the_screen_says_which_role_gave_the_access(): void
    {
        $role = $this->aRoleHolding('sales');

        $this->subject->syncRoles([$role]);

        $this->get(route('system_admin.user.edit', $this->subject))
            ->assertOk()
            ->assertSee(__('system_admin::permission.effective_access'))
            ->assertSee(__('system_admin::permission.from_roles', [
                'roles' => RoleLabel::for($role->name),
            ]));
    }

    /**
     * ⛔ আর সরাসরি দেওয়া অনুমতিও গোনা হয় — এটাই সবচেয়ে নীরব ফাঁকটা।
     *
     * ── ⚠️ কেন ─────────────────────────────────────────────────────
     * ⓘ spatie একজনকে রোল ছাড়াও অনুমতি দিতে দেয়
     * (`givePermissionTo`)। ⛔ ওগুলো না গুনলে পর্দাটা বলত *"এটা তার
     * নেই"*, অথচ সে দিব্যি পাতাটা খুলতে পারতেন।
     *
     * ⚠️ আর ভুলটা ধরা পড়ত না: সংখ্যাটা ছোট দেখাত, আর ছোট সংখ্যা কেউ
     * সন্দেহ করে না।
     */
    public function test_a_permission_given_straight_to_the_person_is_counted(): void
    {
        $this->subject->syncRoles([]);
        $this->subject->givePermissionTo($this->aPermissionOf('sales'));

        $this->get(route('system_admin.user.edit', $this->subject))
            ->assertOk()
            ->assertSee(__('system_admin::permission.granted_directly'));
    }

    /**
     * ⭐ আর পাল্টা-দাবি: যেখানে কিছুই নেই, সেখানে সেটাও লেখা থাকে।
     *
     * ── ⛔ কেন এই দাবিটা ছাড়া উপরের দুইটা অর্ধেক ──────────────────────
     * ⚠️ একটা কোড যেটা **সব** মডিউলে একটা রোলের নাম লিখে দিত, সে উপরের
     * দাবিগুলো পাস করত। ⓘ স্পেকের নমুনাতেও সারিটা আছে — *"অর্থ ✕"* —
     * কারণ *"সে এটা পারে না"* নিজেই একটা উত্তর।
     */
    public function test_a_module_the_person_cannot_touch_says_so(): void
    {
        $this->subject->syncRoles([]);
        $this->subject->syncPermissions([]);

        $this->get(route('system_admin.user.edit', $this->subject))
            ->assertOk()
            ->assertSee(__('system_admin::permission.from_nowhere'));
    }

    /** ⓘ একটা রোল যার হাতে ঐ মডিউলের অন্তত একটা অনুমতি আছে। */
    private function aRoleHolding(string $module): Role
    {
        $role = Role::query()
            ->where('company_id', CompanyContext::id())
            ->where('name', '!=', PermissionSyncer::SUPER_ADMIN_ROLE)
            ->firstOrFail();

        $role->syncPermissions([$this->aPermissionOf($module)]);

        return $role;
    }

    /**
     * ⓘ ঐ মডিউলের একটা সত্যিকারের অনুমতি — নামটা হাতে লেখা হয় না।
     *
     * ⛔ `'sales.invoice.view'` টাইপ করলে দাবিটা লাল হত যেদিন ঐ পর্দার
     * নাম বদলায় — অথচ আসল ক্ষমতার ঘরে কোনো ভুল হয়নি।
     */
    private function aPermissionOf(string $module): Permission
    {
        return Permission::query()
            ->where('guard_name', 'web')
            ->where('name', 'like', $module.'.%')
            ->orderBy('name')
            ->firstOrFail();
    }
}
