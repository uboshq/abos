<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\SystemAdmin;

use App\Core\Services\PermissionSyncer;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * রোলের পর্দা — প্রতিটা অনুমতি ছকে একবার, আর যা ছিল তাই চালু।
 *
 * ── ⛔ কেন এই পাহারা, ১৯ সেপ্টেম্বর ২০২৬ ─────────────────────────────
 * মালিকের নকশা মেনে পর্দাটা কাঁচা নামের তালিকা থেকে ছকে বদলাল — জিনিস
 * এক সারি, পাশে দেখা · তৈরি · সম্পাদনা · মোছা, বাকিগুলো "বিশেষ" কলামে।
 *
 * ⚠️ ছকের বিপদ একটাই: যে অনুমতি চার কলামে ধরে না সেটা **নীরবে বাদ
 * পড়তে পারে**। তখন পর্দায় টিক দেওয়ার কোনো ঘরই থাকত না, আর সংরক্ষণ
 * করলে `syncPermissions()` আগে দেওয়া ঐ অনুমতিটা **কেড়ে নিত** — কারণ
 * ফর্ম সেটা পাঠায়নি। ⛔ কেউ কিছু না বদলে কেবল "সংরক্ষণ" চাপলেও।
 *
 * ⓘ তাই দুইটা মাপ: প্রতিটা অনুমতির ঠিক একটা ঘর, আর কিছু না বদলে
 * পাঠালে রোলটা হুবহু একই থাকে।
 */
final class TheRolePageShowsEveryPermissionTest extends TestCase
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

    public function test_every_permission_has_exactly_one_switch(): void
    {
        $html = $this->actingAs($this->owner)
            ->get(route('system_admin.role.create'))
            ->assertOk()
            ->getContent();

        preg_match_all('/name="permissions\[\]" value="([^"]+)"/', $html, $m);
        $shown = array_count_values($m[1]);

        $all = Permission::query()->where('guard_name', 'web')->pluck('name')->all();

        $this->assertGreaterThan(100, count($all), 'অনুমতিই বসেনি — পরীক্ষাটা তখন কিছু দেখছে না।');
        $this->assertSame([], array_values(array_diff($all, array_keys($shown))),
            'এই অনুমতিগুলোর কোনো ঘর নেই — সংরক্ষণ করলেই রোল থেকে কেটে যাবে।');
        $this->assertSame([], array_keys(array_filter($shown, fn (int $n) => $n > 1)),
            'এই অনুমতিগুলো দুইবার — এক ঘর বন্ধ করলে অন্যটা চালু রেখে দেয়।');
    }

    public function test_saving_without_touching_anything_changes_nothing(): void
    {
        $role = Role::query()
            ->where('company_id', CompanyContext::id())
            ->where('name', '!=', PermissionSyncer::SUPER_ADMIN_ROLE)
            ->whereHas('permissions')
            ->firstOrFail();

        $before = collect($role->getPermissionNames())->sort()->values()->all();

        $html = $this->actingAs($this->owner)
            ->get(route('system_admin.role.edit', $role))
            ->assertOk()
            ->getContent();

        /*
         * ⓘ ব্রাউজার যা পাঠাত — কেবল চালু থাকা ঘরগুলো।
         *
         * ── ⛔ ছাঁচটা ভাঙা ছিল, ২২ সেপ্টেম্বর ২০২৬ ──────────────────
         * আগে লেখা ছিল `value="…"\s+class="…"\s+checked` — অর্থাৎ
         * `class`-এর **ঠিক পরেই** `checked` থাকতে হত। ⚠️ কিন্তু
         * [[role/partials/switch.blade.php]] মাঝে `data-permission-cell`
         * বসায়, তাই ছকের একটা ঘরও কোনোদিন মিলত না — কেবল "বিশেষ
         * অধিকার"-এর চিপগুলো মিলত, কারণ ওদের ঐ বৈশিষ্ট্যটা নেই।
         *
         * ⛔ আর যেদিন পরীক্ষার ভূমিকাটায় একটাও বিশেষ অধিকার রইল না,
         * সেদিন দাবিটা লাল হলো — কোড বদলায়নি, **ডেটা বদলেছে**।
         * ⓘ অর্থাৎ এতদিন সে ছকের সারিগুলো মাপছিল বলে মনে হত, অথচ
         * মাপছিল কেবল চিপগুলো।
         *
         * ⭐ এখন যেকোনো ক্রমে বৈশিষ্ট্য চলে, কেবল `>` পেরোনো যায় না —
         * নাহলে পরের `<input>`-এর `checked` এই ঘরটার বলে গোনা হত।
         */
        preg_match_all('/name="permissions\[\]" value="([^"]+)"[^>]*\schecked/', $html, $m);

        $this->assertNotEmpty($m[1], 'চালু থাকা একটা ঘরও পড়া যায়নি — ছাঁচটা পর্দার সাথে মেলেনি।');

        /*
         * ⭐ আর ছকের সারিও ধরা পড়ে, কেবল চিপ নয়।
         *
         * ⛔ এই লাইনটা ছাড়া ছাঁচটা আবার নীরবে সরু হয়ে যেতে পারত, আর
         * দাবিটা "কিছু তো পেয়েছি" বলে সবুজ থাকত।
         */
        $this->assertNotEmpty(
            preg_grep('/^(?!.*\.manage$).*/', $m[1]) ?: [],
            '⛔ কেবল "বিশেষ অধিকার"-এর ঘরগুলো মিলেছে — ছকের সারিগুলো ছাঁচে পড়ছে না।',
        );

        $this->actingAs($this->owner)
            ->put(route('system_admin.role.update', $role), [
                'name' => $role->name,
                'permissions' => $m[1],
            ])
            /*
             * ⭐ সংরক্ষণের পর রোলটাতেই থাকা — ২৪ সেপ্টেম্বর ২০২৬।
             *
             * ⓘ আগে তালিকায় ফিরত, কারণ তালিকা আর সম্পাদনা আলাদা পর্দা
             * ছিল। ⚠️ এখন একটাই পর্দা, তাই কারণটা আর নেই
             * ([[RoleController::store()]])।
             */
            ->assertRedirect(route('system_admin.role.edit', $role));

        $this->assertSame($before, collect($role->fresh()->getPermissionNames())->sort()->values()->all());
    }

    public function test_the_page_speaks_in_words_not_permission_codes(): void
    {
        app()->setLocale('bn');

        $this->actingAs($this->owner)
            ->get(route('system_admin.role.create'))
            ->assertOk()
            ->assertSee('ক্রয় বিল')
            ->assertSee('ভাউচার')
            ->assertDontSee('>accounts.voucher.update<', false);
    }
}
