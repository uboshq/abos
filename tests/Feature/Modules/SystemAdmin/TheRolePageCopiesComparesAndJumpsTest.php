<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\SystemAdmin;

use App\Core\Services\PermissionSyncer;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ⭐ রোলের পাতা — কন্ট্রোল প্যানেলের পরিকল্পনা, মালিকের অনুমোদিত, ২১ অক্টোবর ২০২৬, দফা ১০: ছক যেমন আছে; যোগ হলো বাঁয়ে মডিউলের তালিকা
 * (এক চাপে সেখানে), "এর মতো নতুন রোল", "দুই রোল পাশাপাশি", আর মাথায় "অনুমতি ৩১৯"-এর বদলে "এই রোলে যাঁরা আছেন" ও "শেষ বদল"।
 */
final class TheRolePageCopiesComparesAndJumpsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
    }

    public function test_the_head_says_who_is_in_the_role_and_its_last_change_not_the_permission_total(): void
    {
        $role = $this->roleOf('accounts@abos.test');
        $html = (string) $this->actingAs($this->owner)->get(route('system_admin.role.edit', $role))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-role-card="permissions"', $html, '⛔ "অনুমতি ৩১৯" এখনো মাথায়।');
        $this->assertMatchesRegularExpression('/data-role-card="members"(?:(?!<\/div>\s*<\/div>).)*>\s*'.$role->users()->count().'\s*</s', $html, '⛔ "এই রোলে যাঁরা আছেন" রোলের মানুষ গোনে না।');
        $this->assertMatchesRegularExpression('/data-role-card="last-change"(?:(?!<\/div>\s*<\/div>).)*20\d{2}/s', $html, '⛔ "শেষ বদল"-এ তারিখ নেই।');

        // ⓘ মডিউলে যান: প্রতিটা মডিউলের লিংক, আর লিংক যেখানে নিয়ে যায় সেই ভাঁজ
        preg_match_all('/data-permission-module="([a-z_]+)" id="perm-\1"/', $html, $modules);
        $this->assertNotEmpty($modules[1], '⛔ মডিউলের ভাঁজে যাওয়ার ঠিকানা নেই।');
        foreach ($modules[1] as $module) {
            $this->assertStringContainsString('href="#perm-'.$module.'" data-permission-jump="'.$module.'"', $html, "⛔ \"{$module}\" মডিউলে যাওয়ার লিংক বাঁয়ে নেই।");
        }
    }

    public function test_a_new_role_like_this_opens_with_the_same_ticks_and_never_from_the_owner_or_another_company(): void
    {
        $source = $this->roleOf('accounts@abos.test');
        $held = $source->permissions()->pluck('name')->sort()->values()->all();
        $this->assertNotEmpty($held, 'ⓘ পরীক্ষার ভিত্তি: হিসাবরক্ষকের অনুমতি আছে।');

        $html = (string) $this->actingAs($this->owner)->get(route('system_admin.role.create', ['from' => $source->id]))->assertOk()->getContent();
        $this->assertSame($held, $this->ticked($html), '⛔ কপির ছক উৎসের টিকগুলো নিয়ে খোলে না।');
        $this->assertStringContainsString(e(__('system_admin::permission.copy_name', ['name' => \App\Core\Support\RoleLabel::for($source->name)])), $html, '⛔ কপির নাম "… (কপি)" নয়।');
        $this->assertSame([], $this->ticked((string) $this->get(route('system_admin.role.create'))->getContent()), '⛔ সাধারণ নতুন রোলেও টিক বসে গেল।');

        $edit = (string) $this->get(route('system_admin.role.edit', $source))->getContent();
        $this->assertStringContainsString(e(route('system_admin.role.create', ['from' => $source->id])), $edit, '⛔ রোলের পাতায় "এর মতো নতুন রোল" নেই।');

        $owner = Role::query()->where('company_id', CompanyContext::id())->where('name', PermissionSyncer::SUPER_ADMIN_ROLE)->firstOrFail();
        $this->assertSame(404, $this->get(route('system_admin.role.create', ['from' => $owner->id]))->status(), '⛔ মালিকের রোল কপি হয় — মালিক-সমান আরেকটা রোল।');
        $elsewhere = $this->aRoleElsewhere();
        $this->assertSame(404, $this->get(route('system_admin.role.create', ['from' => $elsewhere->id]))->status(), '⛔ আরেক কোম্পানির রোল কপি হয়।');
    }

    public function test_two_roles_side_by_side_show_only_where_they_differ(): void
    {
        $a = $this->roleOf('accounts@abos.test');
        $b = $this->roleOf('sales@abos.test');
        // ⓘ ডেমোর দুই রোলে মিল নেই — একটা ভাগ করা অনুমতি না দিলে "মিলগুলো লুকানো" কথাটা কখনো পরখ হত না (R8 বেঁচে গিয়েছিল)
        DB::table('role_has_permissions')->insert(['role_id' => $b->id, 'permission_id' => $a->permissions()->value('permissions.id')]);
        $heldA = $a->permissions()->pluck('name')->all();
        $heldB = $b->permissions()->pluck('name')->all();
        $onlyA = array_values(array_diff($heldA, $heldB));
        $onlyB = array_values(array_diff($heldB, $heldA));
        $both = array_values(array_intersect($heldA, $heldB));
        $this->assertNotEmpty($onlyA, 'ⓘ পরীক্ষার ভিত্তি: দুই রোল আলাদা।');
        $this->assertNotEmpty($both, 'ⓘ পরীক্ষার ভিত্তি: দুই রোলে অন্তত একটা মিল।');

        $html = (string) $this->actingAs($this->owner)->get(route('system_admin.role.compare', ['a' => $a->id, 'b' => $b->id]))->assertOk()->getContent();

        preg_match_all('/data-compare-row="([^"]+)"/', $html, $rows);
        $this->assertEqualsCanonicalizing([...$onlyA, ...$onlyB], $rows[1], '⛔ তুলনায় কেবল আলাদাগুলো নেই — মিলগুলোও এল, বা কিছু বাদ গেল।');
        $this->assertMatchesRegularExpression('/data-compare-row="'.preg_quote($onlyA[0], '/').'"(?:(?!<\/tr>).)*data-compare-a="yes"(?:(?!<\/tr>).)*data-compare-b="no"/s', $html, '⛔ কোন রোলের হাতে আছে, ভুল পাশে বসল।');
        $this->assertStringContainsString(e(__('system_admin::permission.compare_same', ['count' => count($both)])), $html, '⛔ মিলের সংখ্যা ভুল।');

        $this->assertStringContainsString(e(route('system_admin.role.compare', ['a' => $a->id])), (string) $this->get(route('system_admin.role.edit', $a))->getContent(), '⛔ রোলের পাতায় "দুই রোল পাশাপাশি" নেই।');

        $elsewhere = $this->aRoleElsewhere();
        $foreign = (string) $this->get(route('system_admin.role.compare', ['a' => $a->id, 'b' => $elsewhere->id]))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-compare-row=', $foreign, '⛔ আরেক কোম্পানির রোলের অনুমতি তুলনায় ফাঁস হলো।');
    }

    private function roleOf(string $email): Role
    {
        $user = User::query()->where('email', $email)->firstOrFail();

        return Role::query()->where('company_id', CompanyContext::id())
            ->whereIn('id', DB::table('model_has_roles')->where('model_id', $user->id)->pluck('role_id'))->where('name', '!=', PermissionSyncer::SUPER_ADMIN_ROLE)->firstOrFail();
    }

    /** ⓘ আরেক কোম্পানির একটা রোল, অনুমতিসহ — ডেমোতে থাকুক বা না থাকুক */
    private function aRoleElsewhere(): Role
    {
        $other = Company::query()->whereKeyNot(CompanyContext::id())->first() ?? Company::factory()->create();
        $role = Role::query()->forceCreate(['name' => 'Elsewhere Only', 'guard_name' => 'web', 'company_id' => $other->id]);
        DB::table('role_has_permissions')->insert(['role_id' => $role->id, 'permission_id' => DB::table('permissions')->value('id')]);

        return $role;
    }

    /** @return list<string> */
    private function ticked(string $html): array
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
        $names = [];
        foreach ((new \DOMXPath($dom))->query('//input[@name="permissions[]"][@checked]') as $input) {
            $names[] = $input->getAttribute('value');
        }
        sort($names);

        return array_values(array_unique($names));
    }
}
