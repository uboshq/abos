<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\SystemAdmin;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ভূমিকা বসেছিল এক কোম্পানিতে, আর অন্যটায় পর্দা ফাঁকা।
 *
 * ── ⛔ কী ঘটেছিল, আর কেন কোড সারালেই শেষ হয় না ───────────────────────
 * অনুমতির দলটা কোম্পানি, তাই পুরনো `syncRoles()` সারিটা লিখত কেবল
 * **প্রশাসক তখন যে কোম্পানিতে বসে ছিলেন** তার নামে। ⓘ কোডটা
 * `08e96392`-তে সারানো — কিন্তু **পুরনো সারিগুলো নিজে থেকে সরে না**।
 *
 * ⚠️ লাইভে মালিক নিজের কোম্পানিতে ঢুকে **ফাঁকা মেনু** পেয়েছেন, আর
 * সারানোর একমাত্র পথ ছিল প্রতিটা মানুষের ফর্ম খুলে একবার সেভ করা।
 *
 * ── ⛔ এই পরীক্ষার আসল কাজ ───────────────────────────────────────────
 * *"ফাঁকা কোম্পানি ভরে"* দাবিটা সহজ। ⚠️ **আসল ঝুঁকি উল্টো দিকে**: একটা
 * মেরামতি কমান্ড যদি ভূমিকাগুলোর মিলন বসিয়ে দেয়, তবে যিনি এক
 * কোম্পানিতে প্রশাসক আর অন্যটায় দর্শক, তিনি **দুই জায়গাতেই প্রশাসক**
 * হয়ে যাবেন — লাইভ ডেটায় নীরবে ক্ষমতা বেড়ে যাওয়া, আর কেউ টিকও দেয়নি।
 *
 * ⓘ তাই এখানে সেই দাবিটাই আলাদা করে মাপা হয়েছে।
 */
final class TheirRolesSatInOneCompanyAndTheOtherWentBlankTest extends TestCase
{
    use RefreshDatabase;

    private Company $alpha;

    private Company $beta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        /*
         * ⚠️ `orderBy` ছাড়া `first()` ইঞ্জিনের খেয়াল — MySQL আর MariaDB
         * আলাদা সারি ফেরত দিতে পারে, আর তখন একই পরীক্ষা এক মেশিনে সবুজ
         * আর অন্যটায় লাল হয়। ⛔ ২২ সেপ্টেম্বর ২০২৬-এ ঠিক তা-ই হয়েছে:
         * আমার ডাটাবেসে পাস, সঙ্গীর ডাটাবেসে ফেল।
         */
        $this->alpha = Company::query()->where('code', 'TDEPOT')->firstOrFail();

        $this->beta = Company::query()
            ->where('code', '!=', 'TDEPOT')
            ->orderBy('id')
            ->firstOrFail();

        CompanyContext::set($this->alpha->id, $this->alpha->defaultBranch()?->id);
    }

    // ── আসল দাবি ──────────────────────────────────────────────────────

    public function test_a_company_with_no_roles_at_all_gets_filled(): void
    {
        $user = $this->ownerOfBoth();

        // ⛔ পুরনো বাগটা হুবহু বানানো: β-র সারিগুলো মুছে দেওয়া।
        $this->wipeRolesIn($this->beta, $user);

        $this->assertSame([], $this->rolesIn($this->beta, $user),
            'β আগে থেকেই ফাঁকা করা যায়নি — তাহলে পরের মাপটার মানে নেই।');

        $before = $this->rolesIn($this->alpha, $user);

        $this->artisan('abos:roles-in-every-company', ['--force' => true, '--user' => $user->email])
            ->assertSuccessful();

        $this->assertSame($before, $this->rolesIn($this->beta, $user),
            'ফাঁকা কোম্পানিটা ভরেনি — মানুষটা ওখানে ঢুকে এখনো ফাঁকা পর্দা পাবেন।');
    }

    /** ⓘ `--force` ছাড়া কিছুই বসে না — শুকনো দৌড়টা সত্যিই শুকনো। */
    public function test_without_force_nothing_is_written(): void
    {
        $user = $this->ownerOfBoth();
        $this->wipeRolesIn($this->beta, $user);

        $this->artisan('abos:roles-in-every-company', ['--user' => $user->email])
            ->assertSuccessful();

        $this->assertSame([], $this->rolesIn($this->beta, $user),
            'শুকনো দৌড়েই সারি বসে গেছে।');
    }

    // ── ⛔ আর যেটা ঘটতেই পারে না ──────────────────────────────────────

    /**
     * যেখানে ভূমিকা আছে, সেখানে হাত পড়ে না।
     *
     * ── ⚠️ এই পরীক্ষাটা আজ কিছুই ধরে না, আর কথাটা লিখে রাখা দরকার ───
     * প্রথমে একে "এই ফাইলের সবচেয়ে জরুরি দাবি" লিখেছিলাম। ⛔ তারপর
     * ইচ্ছা করে কমান্ডটা ভেঙে — ফাঁকা কোম্পানির বদলে **সবগুলোতে**
     * বসিয়ে — চালিয়ে দেখা গেল **চারটাই সবুজ**।
     *
     * ⓘ কারণটা এখন স্পষ্ট: আসল পাহারাটা `array_diff` নয়, বরং
     * [[RolesInEveryCompany::namesFor()]] — সে কোম্পানিভেদে ভূমিকা
     * আলাদা দেখলেই মানুষটাকে **ছেড়ে দেয়**। ⚠️ আর সব কোম্পানিতে
     * ভূমিকা এক হলে ভরা কোম্পানিতে আবার একই জিনিস বসানো কিছুই বদলায়
     * না — তাই এই পথে ক্ষমতা বাড়ানোর কোনো উপায়ই থাকে না।
     *
     * ⭐ তবু পরীক্ষাটা রাখা হলো: এটা **ইচ্ছাটা পেরেক দিয়ে আটকায়**।
     * ⛔ কেউ যদি কাল `namesFor()`-এর কড়া নিয়মটা আলগা করেন, তখন
     * `array_diff`-টাই শেষ বাধা, আর এই পরীক্ষাটা তখন সত্যিই কামড়াবে।
     *
     * ⓘ আজকের আসল কামড়টা নিচের পরীক্ষায় — ওটা ভাঙলে লাল হয়।
     */
    public function test_a_company_that_already_has_roles_is_left_alone(): void
    {
        $user = $this->ownerOfBoth();

        // ⓘ β-তে ইচ্ছা করে **আলাদা** একটা ভূমিকা বসানো।
        $small = $this->aRoleUnlike($this->beta, $this->hisOwnRoles($user));

        $this->wipeRolesIn($this->beta, $user);

        DB::table('model_has_roles')->insert([
            'role_id' => $small->id,
            'model_type' => $user->getMorphClass(),
            'model_id' => $user->getKey(),
            'company_id' => $this->beta->id,
        ]);

        $this->assertSame([$small->name], $this->rolesIn($this->beta, $user));

        $this->artisan('abos:roles-in-every-company', ['--force' => true, '--user' => $user->email])
            ->assertSuccessful();

        $this->assertSame([$small->name], $this->rolesIn($this->beta, $user),
            'ছোট ভূমিকার জায়গায় বড়টা বসে গেছে — কেউ টিক না দিয়েই ক্ষমতা পেয়ে গেলেন।');
    }

    /**
     * ⛔ কোম্পানিভেদে ভূমিকা আলাদা হলে মানুষটাকে ছুঁয়ে দেখাও হয় না।
     *
     * ⭐ **এটাই এই ফাইলের আসল পাহারা** — উপরেরটা নয়। ⓘ মেপে দেখা:
     * `namesFor()`-কে মিলন ফেরাতে বদলালে এই পরীক্ষাটা লাল হয়, আর
     * উপরেরটা সবুজ থেকে যায়।
     *
     * ⚠️ এখানে ভুল হলে সেটা ত্রুটি নয়, **নীরব ক্ষমতা বৃদ্ধি**: যিনি
     * এক কোম্পানিতে প্রশাসক আর অন্যটায় দর্শক, তিনি দুই জায়গাতেই
     * প্রশাসক হয়ে যেতেন — আর ধরা পড়ত কেবল তখন, যখন তিনি এমন কিছু
     * করে ফেলতেন যা তাঁর করার কথা ছিল না।
     *
     * ⓘ তাই দুইটা আলাদা মাপ: বার্তাটা এসেছে কি না, **আর** তৃতীয়
     * কোম্পানিটা সত্যিই ফাঁকা থেকেছে কি না। ⛔ কেবল বার্তা মাপলে
     * একদিন কেউ বার্তাটা রেখে কাজটা বদলে ফেলতে পারতেন।
     */
    public function test_a_person_with_different_roles_per_company_is_skipped(): void
    {
        $user = $this->ownerOfBoth();
        $small = $this->aRoleUnlike($this->beta, $this->hisOwnRoles($user));

        $this->wipeRolesIn($this->beta, $user);

        DB::table('model_has_roles')->insert([
            'role_id' => $small->id,
            'model_type' => $user->getMorphClass(),
            'model_id' => $user->getKey(),
            'company_id' => $this->beta->id,
        ]);

        // ⓘ তৃতীয় একটা কোম্পানি, যেখানে তিনি আছেন কিন্তু ভূমিকা নেই।
        $third = Company::create(['code' => 'THIRD', 'name_en' => 'Third Co']);
        $user->companies()->attach($third->id);

        $this->artisan('abos:roles-in-every-company', ['--force' => true, '--user' => $user->email])
            ->expectsOutputToContain('ছেড়ে দেওয়া হলো')
            ->assertSuccessful();

        $this->assertSame([], $this->rolesIn($third, $user),
            'দুই রকম ভূমিকা দেখেও কমান্ড একটা বেছে বসিয়ে দিয়েছে।');
    }

    // ── হাতিয়ার ────────────────────────────────────────────────────────

    private function ownerOfBoth(): User
    {
        $user = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        $this->assertGreaterThanOrEqual(2, $user->companies()->count(),
            'মালিক দুই কোম্পানিতে নেই — তাহলে এই ফাইলটা কিছুই মাপছে না।');

        return $user;
    }

    /**
     * ⓘ মানুষটা α-তে যে ভূমিকাগুলো ধরে আছেন।
     *
     * ⚠️ এটাই সেই তালিকা যার **চেয়ে আলাদা** কিছু β-তে বসাতে হবে —
     * নাহলে "কোম্পানিভেদে আলাদা" দৃশ্যটাই তৈরি হয় না।
     *
     * @return list<string>
     */
    private function hisOwnRoles(User $user): array
    {
        $mine = $this->rolesIn($this->alpha, $user);

        $this->assertNotSame([], $mine,
            'মালিকের α-তে কোনো ভূমিকাই নেই — তাহলে এই পরীক্ষাটা যে দৃশ্যটা বানাতে চায় '
            .'সেটাই বানানো যায় না, আর লালটা বাগের কথা বলত না।');

        return $mine;
    }

    /** @return list<string> */
    private function rolesIn(Company $company, User $user): array
    {
        $names = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_type', $user->getMorphClass())
            ->where('model_has_roles.model_id', $user->getKey())
            ->where('model_has_roles.company_id', $company->id)
            ->pluck('roles.name')
            ->all();

        sort($names);

        return array_values($names);
    }

    private function wipeRolesIn(Company $company, User $user): void
    {
        DB::table('model_has_roles')
            ->where('model_type', $user->getMorphClass())
            ->where('model_id', $user->getKey())
            ->where('company_id', $company->id)
            ->delete();
    }

    /**
     * ⓘ এই কোম্পানির এমন একটা ভূমিকা যেটা মানুষটার **অন্য কোম্পানির
     * ভূমিকার সাথে মেলে না**।
     *
     * ── ⛔ কেন নামটা হিসাব করে বাছা হয়, আন্দাজে নয় ──────────────────
     * আগে কেবল "সুপার-অ্যাডমিন ছাড়া প্রথমটা" নেওয়া হত, আর ধরে নেওয়া
     * হত সেটা মালিকের ভূমিকার চেয়ে আলাদা। ⚠️ **ধরে নেওয়াটাই ছিল
     * ফাঁদ**: ভূমিকা দুইটা মিলে গেলে গোটা দৃশ্যটাই ভেঙে যায় — তখন
     * কোম্পানিভেদে ভূমিকা আর "আলাদা" থাকে না, কমান্ড কাউকে ছাড়ে না,
     * আর পরীক্ষাটা এমন একটা কারণে লাল হয় যার সাথে বাগের সম্পর্ক নেই।
     *
     * ⭐ তাই শর্তটা এখন হাতে বসানো, আর না মিললে পরীক্ষাটা **স্পষ্ট
     * করে সেটাই বলে** — রহস্যময় লালের বদলে।
     *
     * @param  list<string>  $notThese
     */
    private function aRoleUnlike(Company $company, array $notThese): Role
    {
        $role = Role::query()
            ->where('company_id', $company->id)
            ->whereNotIn('name', $notThese)
            ->orderBy('id')
            ->first();

        $this->assertNotNull(
            $role,
            'এই কোম্পানিতে এমন কোনো ভূমিকা নেই যেটা মানুষটার নিজের ভূমিকার চেয়ে আলাদা — '
            .'তাহলে "কোম্পানিভেদে ভূমিকা আলাদা" দৃশ্যটাই বানানো যায় না, আর পরীক্ষাটা কিছুই মাপছে না।'
        );

        return $role;
    }
}
