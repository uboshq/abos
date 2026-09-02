<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Dashboard\DashboardEngine;
use App\Core\Module\ModuleRegistry;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * প্রতিটা মডিউলের ড্যাশবোর্ডের নিজের দরজা আছে, আর সেটা সত্যিই বন্ধ।
 *
 * ── কোন ভুল থেকে এই ফাইলটা এসেছে ─────────────────────────────────────
 * `dashboard/{module}` একটাই রুট, আর ৩১ আগস্ট ২০২৬-এ সেটা কেবল `auth`
 * চাইত। ইঞ্জিন চাবিহীন সংখ্যা ঢাকে আর চাবিহীন টাইল বাদ দেয়, তাই দেখে
 * মনে হত সব ঠিক আছে — কিন্তু **পাতাটা নিজে যেকোনো লগইন-করা মানুষের
 * জন্য খোলা ছিল**। মেনুতে সারিটা না দেখলেও ঠিকানা টাইপ করলেই শিরোনাম,
 * উপশিরোনাম আর কাঠামোটা দেখা যেত: কোন মডিউল চালু আছে, তাতে কী কী
 * সংখ্যা রাখা হয়, কয়টা তালিকা। ওটা তথ্যই।
 *
 * ── কেন `EveryRouteIsGuardedTest` যথেষ্ট নয় ───────────────────────────
 * সে **কোড পড়ে** — `can:` মিডলওয়্যার আছে কি না, `authorize()` ডাকা
 * হয়েছে কি না। কিন্তু এই রুটে চাবির নামটা স্থির নয়, মডিউল থেকে আসে।
 * `permissionFor()` ভুল নাম ফেরালে, বা `null` ফিরে ফেল-ওপেন হলে, ওই
 * পরীক্ষা তবু সবুজ থাকত — কারণ `authorize(` লেখাটা তো ফাইলে আছে।
 *
 * তাই এখানে **দরজাটা দুইদিক থেকে ঠেলে দেখা হয়**: চাবি ছাড়া বন্ধ থাকে
 * কি না, আর ঠিক চাবিটা দিলে খোলে কি না। দ্বিতীয় অর্ধেকটা বাদ দিলে
 * "সবকিছু ৪০৩" লিখেও পরীক্ষা পাস করানো যেত।
 *
 * "সব মডিউলের উপর" চালানো হয়, নাম ধরে নয় — নতুন মডিউল এলে এই ফাইলটা
 * ছুঁতে হবে না (§১৯.৭)।
 */
class EveryModuleDashboardHasItsOwnDoorTest extends TestCase
{
    use RefreshDatabase;

    /**
     * ঘোষণা ছাড়া কোনো ড্যাশবোর্ড নেই।
     *
     * চাবির নামটা মডিউলের নিজের মেনু-সারি থেকে আসে। সারিটা লিখতে ভুলে
     * গেলে `permissionFor()` `null` ফেরে আর কন্ট্রোলার সবাইকে ফেরায় —
     * অর্থাৎ পাতাটা কারও জন্যই খোলে না। সেটা নিরাপদ, কিন্তু নীরব; এই
     * পরীক্ষাটা সেই নীরবতা ভাঙে।
     */
    public function test_every_dashboard_names_the_key_that_opens_it(): void
    {
        $engine = app(DashboardEngine::class);
        $nameless = [];

        foreach (app(ModuleRegistry::class)->all() as $module) {
            if ($module->dashboard === null) {
                continue;
            }

            $permission = $engine->permissionFor($module->code);

            if ($permission === null) {
                $nameless[] = $module->code;

                continue;
            }

            /*
             * চাবিটা মডিউল নিজেই ঘোষণা করেছে তো?
             *
             * অন্য মডিউলের চাবি লিখলে দরজাটা কাজ করত, কিন্তু ভুল
             * মানুষের হাতে — আর ভুলটা ধরা পড়ত কেবল সেদিন, যেদিন
             * কারও একটা মডিউল আছে অথচ অন্যটা নেই।
             */
            if (! in_array($permission, $module->permissions, true)) {
                $nameless[] = "{$module->code}: '{$permission}' এই মডিউলের ঘোষিত অনুমতি নয়";
            }
        }

        $this->assertSame([], $nameless, implode("\n", array_merge(
            ['এই মডিউলগুলোর ড্যাশবোর্ড আছে, কিন্তু দরজার চাবির নাম নেই:'],
            $nameless,
            ['module.php-এর menu.dashboard সারিতে permission লিখুন।'],
        )));
    }

    /**
     * চাবি ছাড়া বন্ধ, চাবি দিলে খোলা — দুইটাই।
     */
    public function test_a_dashboard_refuses_a_user_without_its_key_and_opens_for_one_with_it(): void
    {
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        foreach (app(ModuleRegistry::class)->all() as $module) {
            foreach ($module->permissions as $permission) {
                Permission::findOrCreate($permission, 'web');
            }
        }

        $engine = app(DashboardEngine::class);
        $wrong = [];

        foreach (app(ModuleRegistry::class)->all() as $module) {
            if ($module->dashboard === null) {
                continue;
            }

            $permission = $engine->permissionFor($module->code);

            if ($permission === null) {
                continue; // উপরের পরীক্ষাটা এটা আলাদা করে বলে
            }

            $url = route('module.dashboard', ['module' => $module->code]);

            /*
             * ── প্রতিবার নতুন ব্যবহারকারী ────────────────────────────
             * একজনকে ঘুরিয়ে-ফিরিয়ে ব্যবহার করলে আগের মডিউলের চাবি
             * তাঁর হাতে থেকে যেত, আর পরেরটার "চাবি নেই" অবস্থাটা আর
             * সত্যি থাকত না। spatie অনুমতি ক্যাশ করে বলেই ভুলটা
             * ধরাও পড়ত না।
             */
            $stranger = User::factory()->create(['current_company_id' => $company->id]);
            $stranger->companies()->attach($company->id);

            $closed = $this->actingAs($stranger)->get($url)->getStatusCode();

            if ($closed !== 403) {
                $wrong[] = "{$module->code}: চাবি ছাড়াও পাতাটা খুলল — {$closed} (৪০৩ হওয়ার কথা)";
            }

            $holder = User::factory()->create(['current_company_id' => $company->id]);
            $holder->companies()->attach($company->id);
            $holder->givePermissionTo($permission);

            $open = $this->actingAs($holder->fresh())->get($url)->getStatusCode();

            if ($open !== 200) {
                $wrong[] = "{$module->code}: '{$permission}' হাতে থাকা সত্ত্বেও খুলল না — {$open}";
            }
        }

        $this->assertSame([], $wrong, implode("\n", array_merge(
            ['ড্যাশবোর্ডের দরজা ঠিকভাবে কাজ করছে না:'],
            $wrong,
        )));
    }

    /**
     * মডিউল বন্ধ মানে তার ড্যাশবোর্ডও নেই।
     *
     * ── কেন এটা আলাদা করে পাহারা দিতে হয় ─────────────────────────────
     * `RefuseSwitchedOffScreens` রুটের **নাম** দেখে বলে সেটা কোন মডিউলের
     * (`purchase.` দিয়ে শুরু মানে purchase-এর)। কিন্তু বারোটা মডিউলের
     * ড্যাশবোর্ড একই রুটে — `module.dashboard` — আর ওই নাম কোনো মডিউলের
     * উপসর্গ বহন করে না।
     *
     * ফলে ৩ সেপ্টেম্বর ২০২৬ পর্যন্ত **বন্ধ মডিউলের ড্যাশবোর্ডও ঠিকানা
     * দিলে খুলে যেত** — কন্ট্রোল প্যানেলের সুইচটা আড়াল ছিল, বাধা নয়।
     * ঠিক এই ভুলটাই ১৩ আগস্ট Direct Purchase Invoice-এ ধরা পড়েছিল, আর
     * ওই মিডলওয়্যারটা তারই উত্তর।
     *
     * ── দুইটা দিকই দেখা হয় ──────────────────────────────────────────
     * বন্ধেরটা ৪০৪, **আর পাশেরটা তখনো ২০০**। দ্বিতীয় অর্ধেকটা বাদ
     * দিলে ধরা পড়ত না যে একটা মডিউল বন্ধ করলে সবগুলোর ড্যাশবোর্ড বন্ধ
     * হয়ে যাচ্ছে — আর ঠিক সেটাই হচ্ছিল, কারণ গ্রুপের উপসর্গ `module.`
     * তেরোটা মডিউলে একই চাবিতে লিখত।
     */
    public function test_switching_a_module_off_closes_its_dashboard_and_only_its_own(): void
    {
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        foreach (app(ModuleRegistry::class)->all() as $module) {
            foreach ($module->permissions as $permission) {
                Permission::findOrCreate($permission, 'web');
            }
        }

        $user = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $user->givePermissionTo(Permission::all());
        $user = $user->fresh();

        $engine = app(DashboardEngine::class);

        $withDashboards = array_values(array_filter(
            app(ModuleRegistry::class)->all(),
            fn ($module) => $module->dashboard !== null && $engine->permissionFor($module->code) !== null,
        ));

        $this->assertGreaterThan(1, count($withDashboards), 'তুলনা করার মতো দুইটা মডিউলই নেই।');

        $settings = app(SettingsService::class);
        $wrong = [];

        foreach ($withDashboards as $module) {
            $settings->set($module->code.'.enabled', false);

            foreach ($withDashboards as $other) {
                $url = route('module.dashboard', ['module' => $other->code]);
                $status = $this->actingAs($user)->get($url)->getStatusCode();
                $want = $other->code === $module->code ? 404 : 200;

                if ($status !== $want) {
                    $wrong[] = "{$module->code} বন্ধ থাকতে {$other->code}-এর ড্যাশবোর্ড {$status}, {$want} হওয়ার কথা";
                }
            }

            $settings->set($module->code.'.enabled', true);
        }

        $this->assertSame([], $wrong, implode("\n", array_merge(
            ['মডিউলের সুইচ ও ড্যাশবোর্ডের দরজা একমত নয়:'],
            $wrong,
        )));
    }
}
