<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\SystemAdmin;

use App\Core\Services\PermissionSyncer;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * তেরোটা ভূমিকা, আর পর্দায় তারা আসত তিনগুণ হয়ে।
 *
 * ── ⓘ মালিকের প্রশ্ন, ২১ সেপ্টেম্বর ২০২৬ ─────────────────────────────
 * *"ekhane tinti kore roll keno"* — ব্যবহারকারীর পর্দায় Auditor তিনবার,
 * Warehouse তিনবার, সুপার অ্যাডমিন তিনবার।
 *
 * ── ⛔ কেন ───────────────────────────────────────────────────────────
 * ভূমিকার সারি **কোম্পানি ধরে** জমা থাকে (`roles.company_id`), তাই
 * প্রতিটা নাম কোম্পানির সংখ্যায় গুণ হয়ে যায়। ⓘ মেপে দেখা: ছাব্বিশটা
 * সারি, আলাদা নাম মাত্র চোদ্দোটা।
 *
 * ⚠️ অথচ সংরক্ষণ হয় **নামে** — তিনটার যেকোনোটায় টিক দিলে একই ফল।
 * অর্থাৎ বাড়তি সারিগুলো কোনো কাজেই লাগত না, কেবল মানুষকে ভাবাত যে
 * তিনটা আলাদা জিনিস।
 */
final class ThirteenRolesWereOfferedThirtyNineTimesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    /**
     * ⭐ প্রতিটা ভূমিকার নাম পর্দায় ঠিক একবার।
     *
     * ⚠️ দাবিটা গোনার উপর দাঁড়ায়, চোখের উপর নয়: একই নাম দুইবার থাকলে
     * এই পরীক্ষা লাল হয়, যদিও পাতাটা দেখতে ঠিকই লাগত।
     */
    public function test_each_role_name_appears_once(): void
    {
        $this->assertGreaterThan(
            Role::query()->distinct()->count('name'),
            Role::query()->count(),
            'ডেমোতে ভূমিকার নকল সারিই নেই — পরীক্ষাটা কিছুই দেখছে না।',
        );

        $html = (string) $this->get(route('system_admin.user.create'))->assertOk()->getContent();

        $box = '<input type="checkbox" name="roles[]" value="';

        // ⓘ সুপার অ্যাডমিন এখানে নেই, আর সেটাই ঠিক — নিচের দাবিটা ওটা দেখে
        $names = Role::query()->pluck('name')->unique()
            ->reject(fn (string $n) => $n === PermissionSyncer::SUPER_ADMIN_ROLE);

        foreach ($names as $name) {
            $this->assertSame(
                1,
                substr_count($html, $box.$name.'"'),
                "'{$name}' ভূমিকাটা পর্দায় একবারের বেশি আছে।",
            );
        }
    }

    /**
     * ⛔ সুপার অ্যাডমিন টিকবক্সের তালিকায় থাকে না।
     *
     * ⓘ মালিক: *"সুপার অ্যাডমিন শুধু একজনেই পাবে"*। ওটা দেওয়ার নিজস্ব
     * পাতা আছে — মালিকানা হস্তান্তর, যেখানে কাজটা দেখতেও বড়।
     */
    public function test_super_admin_is_not_a_tick_box(): void
    {
        $html = (string) $this->get(route('system_admin.user.create'))->assertOk()->getContent();

        $this->assertStringNotContainsString(
            'value="'.PermissionSyncer::SUPER_ADMIN_ROLE.'"',
            $html,
            'সুপার অ্যাডমিন এখনো সাধারণ একটা টিকবক্স।',
        );
    }

    /**
     * ⭐ যাঁর ইতিমধ্যেই ভূমিকাটা আছে, তাঁর পাতায় সেটা থাকে।
     *
     * ⚠️ নাহলে মালিককে সম্পাদনা করলেই টিকটা খসে যেত, আর সংরক্ষণের
     * সাথে সাথে তিনি নিজের চাবি হারাতেন — নীরবে।
     */
    public function test_the_one_who_has_it_still_sees_it(): void
    {
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        $this->assertTrue($owner->hasRole(PermissionSyncer::SUPER_ADMIN_ROLE), 'মালিকের ভূমিকাটাই নেই।');

        $this->get(route('system_admin.user.edit', $owner))
            ->assertOk()
            ->assertSee('value="'.PermissionSyncer::SUPER_ADMIN_ROLE.'"', escape: false);
    }
}
