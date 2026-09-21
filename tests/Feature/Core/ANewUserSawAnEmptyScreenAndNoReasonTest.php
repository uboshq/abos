<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * নতুন একজন ঢুকে খালি পর্দা দেখতেন, আর কোনো কারণ লেখা থাকত না।
 *
 * ── ⓘ মালিকের প্রশ্ন, ২১ সেপ্টেম্বর ২০২৬ ─────────────────────────────
 * *"Abu Kawser user a kono kichui dekhayna keno"* — একজনকে খোলা
 * হয়েছিল, কিন্তু কোনো ভূমিকা বসানো হয়নি।
 *
 * ── ⛔ কেন কিছুই দেখাত না ────────────────────────────────────────────
 * অনুমতি আসে ভূমিকা থেকে ([[MenuBuilder::allowed()]])। ভূমিকা না থাকলে
 * মেনুর প্রতিটা সারি ছাঁকনিতে পড়ে যায় — শূন্যটা মডিউল, ফাঁকা খোলস।
 *
 * ⚠️ আর পর্দার বার্তাটা **ভুল কথা বলত**: *"দেখানোর মতো কিছু নেই"* পড়ে
 * মানুষ ভাবতেন আজ কাজ হয়নি, অথচ তাঁর তো কোনো দরজাই খোলা হয়নি।
 * ⓘ দুইটা সম্পূর্ণ আলাদা অবস্থা, আর দ্বিতীয়টার উত্তরে **কাকে বলতে
 * হবে** সেটা থাকা চাই।
 */
final class ANewUserSawAnEmptyScreenAndNoReasonTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
    }

    /**
     * ⭐ ভূমিকা না থাকলে পর্দা কারণটা বলে, আর কাকে বলতে হবে তাও।
     */
    public function test_a_user_with_no_role_is_told_why(): void
    {
        $this->actingAs($this->aNewUser());

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee(__('core.dashboard.no_module_at_all'))
            ->assertDontSee(__('core.dashboard.nothing_to_show'));
    }

    /**
     * ⛔ আর যাঁর দরজা খোলা আছে, তিনি এই বার্তাটা পান না।
     *
     * ⚠️ এই দাবিটা না থাকলে "সারানো" মানে হত সবাইকে ঐ বাক্যটা দেখানো —
     * আর তখন মালিক নিজেও পড়তেন "আপনার কোনো ভূমিকা বসানো হয়নি"।
     */
    public function test_a_user_who_can_see_modules_never_gets_that_message(): void
    {
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee(__('core.dashboard.no_module_at_all'));
    }

    /**
     * ⭐ ভূমিকা অন্য কোম্পানিতে থাকলে পর্দা **সেই কোম্পানির নাম** বলে।
     *
     * ── ⓘ লাইভে মেপে পাওয়া, ২১ সেপ্টেম্বর ২০২৬ ──────────────────────
     * একজনের ভূমিকা বসেছিল Demo-তে, আর তিনি দাঁড়িয়ে ছিলেন Test
     * Company-তে। ⛔ তাঁকে *"কোনো ভূমিকা দেওয়া হয়নি"* বলা **মিথ্যা**
     * হত — ভূমিকা তো ছিল, কেবল অন্য দরজায়।
     *
     * ⚠️ আর মিথ্যা কারণ দিলে তিনি প্রশাসকের কাছে ছোটেন, প্রশাসকও খুঁজে
     * পান না — কারণ ফর্মে সবই ঠিক দেখায়। ⭐ সঠিক উত্তরটা তাঁকে এক
     * ক্লিকে কাজে ফিরিয়ে দেয়।
     */
    public function test_a_user_whose_role_is_in_another_company_is_told_which_one(): void
    {
        $elsewhere = Company::query()->whereKeyNot($this->company->id)->firstOrFail();

        $user = $this->aNewUser();
        $user->companies()->syncWithoutDetaching([$elsewhere->id]);

        // ⓘ ভূমিকাটা বসে ঐ কোম্পানির নামে — এখানে নয়
        setPermissionsTeamId($elsewhere->id);
        $user->unsetRelation('roles')->syncRoles(['Warehouse']);
        setPermissionsTeamId($this->company->id);

        $this->actingAs(User::query()->findOrFail($user->id));

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee(__('core.dashboard.role_lives_elsewhere', ['companies' => $elsewhere->name()]))
            ->assertDontSee(__('core.dashboard.no_module_at_all'));
    }

    private function aNewUser(): User
    {
        $user = User::query()->create([
            'name' => 'Abu Kawser',
            'email' => 'kawser@abos.test',
            'password' => bcrypt('secret-for-a-test'),
            'is_active' => true,
            'locale' => 'bn',
        ]);

        /*
         * ⓘ কোম্পানিতে যোগ করা হয়, ভূমিকা দেওয়া হয় না — ঠিক যেভাবে
         * নতুন একজনকে খোলা হয় আর ভূমিকাটা বসাতে ভুলে যাওয়া হয়।
         */
        $user->companies()->syncWithoutDetaching([$this->company->id]);

        return $user->fresh();
    }
}
