<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Services\PermissionSyncer;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ভূমিকাটা আগে থেকেই ছিল, তাই নতুন চাবিটা কোনোদিন পৌঁছাত না।
 *
 * ── ⓘ এটা ফাঁদ নয়, সিদ্ধান্ত ─────────────────────────────────────────
 * [[PermissionSyncer::applyRoleTemplates()]] সেই ভূমিকাগুলো ছোঁয় না
 * যেগুলো আগে থেকেই আছে, আর কারণটা ঐ ফাইলেই লেখা: *"নতুন মডিউলে কে কী
 * পারবে সেটা ব্যবসার সিদ্ধান্ত, আর সেটা নীরবে নিয়ে নেওয়ার চেয়ে খারাপ
 * কিছু নেই।"*
 *
 * ⚠️ ফল: ছাঁচে একটা চাবি যোগ করলে সেটা **নতুন কোম্পানিতেই** পৌঁছায়।
 * চলমান প্রতিষ্ঠানে `abos:sync-permissions` দিব্যি *"নতুন কিছু নেই"*
 * বলে, আর কর্মীরা পর্দাটা দেখতে পান না।
 *
 * ── ⭐ এই ফাইল যা পাহারা দেয় ─────────────────────────────────────────
 *   ১. সিঙ্ক সত্যিই চলমান ভূমিকা ছোঁয় না — সিদ্ধান্তটা টিকে আছে
 *   ২. হাতে ডাকা কমান্ডটা `--force` ছাড়া **কিছুই বদলায় না**
 *   ৩. `--force` দিলে অনুপস্থিত চাবিটা বসে
 *   ৪. ছাঁচে নেই এমন চাবি কমান্ডটা **কেড়ে নেয় না**
 *
 * ⓘ (১) আর (২) একসাথে বলছে: অনুমতি কোনোদিন **নিজে থেকে** বাড়ে না।
 * ⛔ ওটাই পুরো নকশাটার কথা, আর ওটা ভাঙা মানে মানুষের ক্ষমতা বেড়ে যাওয়া
 * যা কেউ বেছে নেয়নি।
 */
final class TheRoleWasAlreadyThereSoTheNewKeyNeverArrivedTest extends TestCase
{
    use RefreshDatabase;

    /** ⓘ ২৫ সেপ্টেম্বরে ছাঁচে যোগ হওয়া একটা চাবি। */
    private const LATE_KEY = 'purchase.requisition.create';

    private const ROLE = 'Warehouse';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
    }

    // ── ১ · সিঙ্ক চলমান ভূমিকা ছোঁয় না ───────────────────────────────

    public function test_syncing_permissions_does_not_widen_a_role_that_already_exists(): void
    {
        /*
         * ⭐ **ভরা ভূমিকা থেকে একটা চাবি কেড়ে** ঠিক লাইভের অবস্থাটা
         * বানানো হয়: ভূমিকাটা আছে, বাকি সব চাবিও আছে, কেবল নতুনটা নেই।
         *
         * ⛔ ভূমিকাটা মুছে মাপলে কিছুই প্রমাণ হত না — সিঙ্ক অনুপস্থিত
         * ভূমিকা এমনিতেই বসায়, আর যে অবস্থাটা আসলে ঘটে সেটা ছোঁয়াই হত না।
         */
        $role = $this->theRole();
        $role->revokePermissionTo(self::LATE_KEY);

        $this->assertFalse($this->theRole()->hasPermissionTo(self::LATE_KEY),
            'পরীক্ষার শুরুর অবস্থাটাই বসেনি — চাবিটা কাড়তেই পারেনি।');

        app(PermissionSyncer::class)->sync();

        $this->assertFalse($this->theRole()->hasPermissionTo(self::LATE_KEY),
            'সিঙ্ক চলমান একটা ভূমিকায় নতুন চাবি বসিয়ে দিয়েছে — অর্থাৎ '
            .'মানুষের অনুমতি বেড়েছে, অথচ কেউ সেটা বেছে নেয়নি।');
    }

    // ── ২ · হাতের কমান্ড, তবু ডিফল্টে চুপ ────────────────────────────

    public function test_the_command_changes_nothing_without_force(): void
    {
        $this->theRole()->revokePermissionTo(self::LATE_KEY);

        $this->artisan('abos:apply-role-templates')->assertSuccessful();

        $this->assertFalse($this->theRole()->hasPermissionTo(self::LATE_KEY),
            '`--force` ছাড়াই চাবিটা বসে গেছে — তাহলে দেখে নেওয়ার ধাপটার '
            .'কোনো মানেই থাকে না।');
    }

    // ── ৩ · --force দিলে বসে ─────────────────────────────────────────

    public function test_with_force_the_missing_key_is_granted(): void
    {
        $this->theRole()->revokePermissionTo(self::LATE_KEY);

        $this->artisan('abos:apply-role-templates', ['--force' => true])
            ->assertSuccessful();

        $this->assertTrue($this->theRole()->hasPermissionTo(self::LATE_KEY),
            'মালিক স্পষ্ট করে বলার পরেও চাবিটা ভূমিকায় বসেনি।');
    }

    // ── ৪ · কিছু কেড়ে নেয় না ─────────────────────────────────────────

    public function test_a_key_the_company_added_by_hand_is_left_alone(): void
    {
        /*
         * ⛔ এটা সিঙ্ক নয়, কেবল **যোগ**। ⚠️ ছাঁচে নেই এমন চাবি কেড়ে
         * নিলে কোনো প্রতিষ্ঠানের নিজের সিদ্ধান্ত মুছে যেত — আর মুছে
         * যাওয়াটা নীরব: তিনি পরের দিন দেখতেন কর্মী আর পর্দাটা খুলতে
         * পারছেন না।
         */
        $role = $this->theRole();
        $role->givePermissionTo('purchase.order.create');

        $this->artisan('abos:apply-role-templates', ['--force' => true])
            ->assertSuccessful();

        $this->assertTrue($this->theRole()->hasPermissionTo('purchase.order.create'),
            'প্রতিষ্ঠানের হাতে দেওয়া একটা চাবি কমান্ডটা কেড়ে নিয়েছে।');
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    private function theRole(): Role
    {
        return Role::query()
            ->where('name', self::ROLE)
            ->where('company_id', CompanyContext::id())
            ->firstOrFail();
    }
}
