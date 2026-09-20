<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\SystemAdmin;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * এক কোম্পানির মালিক অন্য কোম্পানির ভিতরে হাত দিতে পারতেন।
 *
 * ── ⛔ যা ভাঙা ছিল (abos-8b-র অডিট, খ১ ও খ২) ─────────────────────────
 * `Company`-তে টেন্যান্ট স্কোপ নেই, আর সেটা ইচ্ছাকৃত — অন্য কোম্পানিতে
 * যেতে হলে আগে তাকে দেখতে পাওয়া লাগে। ⚠️ কিন্তু তার মানে রুট বাইন্ডিং
 * ইনস্টলের **যেকোনো** কোম্পানি ধরে আনত, আর একমাত্র পাহারা ছিল
 * `can:system_admin.company.manage` — যেটা **প্রতিটা কোম্পানির নিজের
 * super_admin ধরে রাখেন**।
 *
 * ⓘ ফল: ক কোম্পানির মালিক খ কোম্পানির নাম, বিআইএন ও ঠিকানা পড়তে ও
 * বদলাতে পারতেন, আর নিষ্ক্রিয় করে খ-এর **সব ব্যবহারকারীকে তালাবন্ধ**
 * করে দিতে পারতেন।
 *
 * ⚠️ পরীক্ষাটা **মালিক নয়** এমন একজন দিয়ে লেখা — আগের পরীক্ষাগুলো
 * super_admin হিসেবে কাজ করত, তাই প্রত্যাখ্যানটা কখনো মাপাই হয়নি।
 */
final class OneCompanyCouldReachIntoAnotherTest extends TestCase
{
    use RefreshDatabase;

    private Company $mine;

    private Company $theirs;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->mine = Company::query()->where('code', 'TDEPOT')->firstOrFail();

        /* ⓘ ডেমোতে দ্বিতীয় কোম্পানি আছে; না থাকলে নিজেরই একটা */
        $this->theirs = Company::query()->where('id', '!=', $this->mine->id)->first()
            ?? Company::query()->create([
                'code' => 'OTHER',
                'name_en' => 'Other Traders',
                'name_bn' => 'অন্য ট্রেডার্স',
                'is_active' => true,
            ]);

        CompanyContext::set($this->mine->id, $this->mine->defaultBranch()?->id);

        /*
         * ⚠️ একজন সত্যিকারের কোম্পানি-প্রশাসক — কেবল **নিজের** কোম্পানিতে।
         * ⓘ ক্ষমতাটা আসল, কারণ প্রশ্নটা "অনুমতি আছে কি না" নয়, "অনুমতি
         * থাকলেও সীমানা মানে কি না"।
         */
        $this->admin = User::factory()->create(['current_company_id' => $this->mine->id]);
        $this->admin->companies()->attach($this->mine->id, ['is_active' => true]);

        $role = Role::findOrCreate('company_admin', 'web');
        $role->givePermissionTo('system_admin.company.manage');
        $this->admin->assignRole($role);

        $this->actingAs($this->admin);
    }

    /**
     * ⛔ অন্য কোম্পানির সেটিংসের পাতা খোলেই না।
     */
    public function test_another_companys_settings_page_does_not_open(): void
    {
        $this->get(route('system_admin.company.edit', $this->theirs))->assertNotFound();

        // ⭐ নিজেরটা ঠিকই খোলে — পাহারাটা যেন সব দরজা বন্ধ না করে দেয়
        $this->get(route('system_admin.company.edit', $this->mine))->assertOk();
    }

    /**
     * ⛔⛔ সবচেয়ে খারাপটা: অন্যের ব্যবসা বন্ধ করে দেওয়া।
     *
     * ⓘ `toggle()` চাপলে খ কোম্পানি নিষ্ক্রিয় হয়ে যেত, আর তাতে তাদের
     * প্রতিটা ব্যবহারকারী তালাবন্ধ।
     */
    public function test_another_companys_business_cannot_be_shut_down(): void
    {
        $this->post(route('system_admin.company.toggle', $this->theirs))->assertNotFound();

        $this->assertTrue($this->theirs->fresh()->is_active,
            'অন্য কোম্পানিটা বন্ধ হয়ে গেছে — তাদের সবাই এখন তালাবন্ধ।');
    }

    /**
     * ⛔ অন্য কোম্পানির নাম-ঠিকানা বদলানো যায় না।
     */
    public function test_another_companys_details_cannot_be_rewritten(): void
    {
        $before = $this->theirs->name_en;

        $this->put(route('system_admin.company.update', $this->theirs), [
            'code' => $this->theirs->code,
            'name_en' => 'Taken Over Ltd',
            'name_bn' => 'দখল করা লিমিটেড',
        ])->assertNotFound();

        $this->assertSame($before, $this->theirs->fresh()->name_en);
    }

    /**
     * ⛔ অন্য কোম্পানির ভিতরে শাখা বানানো যায় না।
     */
    public function test_a_branch_cannot_be_planted_in_another_company(): void
    {
        $this->post(route('system_admin.company.branch.store', $this->theirs), [
            'code' => 'SNEAK',
            'name_en' => 'Sneaky Branch',
        ])->assertNotFound();
    }

    /**
     * ⛔ তালিকাটাও অন্য কোম্পানির নাম দেখায় না।
     *
     * ⚠️ কেবল দরজাগুলো বন্ধ করলে যথেষ্ট হত না — তালিকাতেই নাম, কোড আর
     * বিআইএন পড়ে ফেলা যেত।
     */
    public function test_the_list_shows_only_your_own_companies(): void
    {
        $this->get(route('system_admin.company.index'))
            ->assertOk()
            ->assertSee($this->mine->code)
            ->assertDontSee($this->theirs->code);
    }

    /**
     * ⛔⛔ নিজেকে অন্য কোম্পানিতে যুক্ত করে নেওয়া যায় না (খ২)।
     *
     * ⓘ ক্ষতিটা এখনই পুরো নয় — ভূমিকা কোম্পানি-ভিত্তিক, তাই ঢুকেও অনুমতি
     * থাকত না। ⚠️ কিন্তু দেয়ালটা ভাঙা, আর অনুমতিহীন খোলা যেকোনো পাতার
     * সাথে জুড়লে এটাই সিঁড়ি।
     */
    public function test_an_admin_cannot_add_themselves_to_another_company(): void
    {
        $this->admin->givePermissionTo('system_admin.user.manage');

        $this->put(route('system_admin.user.update', $this->admin), [
            'name' => $this->admin->name,
            'email' => $this->admin->email,
            'login_id' => $this->admin->login_id,
            'locale' => 'bn',
            'roles' => ['company_admin'],
            'companies' => [$this->mine->id, $this->theirs->id],
        ])->assertSessionHasErrors('companies.1');

        $this->assertFalse(
            $this->admin->fresh()->canAccessCompany((int) $this->theirs->id),
            'অ্যাডমিন নিজেকে অন্য কোম্পানিতে ঢুকিয়ে নিয়েছেন।',
        );
    }
}
