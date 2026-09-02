<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Module\ModuleRegistry;
use App\Core\Services\MenuBuilder;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * মেনুতে ঘোষিত প্রতিটা সারি সত্যিই কোথাও নিয়ে যায় কি না।
 *
 * এই পরীক্ষাটা একটা আসল ভুল থেকে এসেছে: Customer মডিউল পাঁচটা স্ক্রিন
 * ঘোষণা করেছিল যার একটাও লেখা হয়নি। MenuBuilder অনুপস্থিত রুটে url null
 * বসায়, তাই কিছুই ভাঙত না — মেনুতে শুধু পাঁচটা সারি থাকত যেগুলোয় ক্লিক
 * করলে কিছু হয় না।
 *
 * "সব মডিউলের উপর" চালানো হয়, নাম ধরে নয় — নতুন মডিউল যোগ হলে এই
 * ফাইলটা ছুঁতে হবে না (সেকশন ১৯.৭)।
 */
class ModuleMenuTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_live_menu_item_leads_somewhere(): void
    {
        $missing = [];

        foreach (app(ModuleRegistry::class)->all() as $module) {
            foreach ($module->menu as $group => $items) {
                foreach ($items as $item) {
                    if (($item['planned'] ?? false) === false && ! Route::has($item['route'])) {
                        $missing[] = "{$module->code}: {$group} → {$item['route']}";
                    }
                }
            }
        }

        $this->assertSame([], $missing, implode("\n", array_merge(
            ['মেনুতে আছে কিন্তু রুট নেই — ক্লিক করলে কিছু হবে না:'],
            $missing,
            ["স্ক্রিনটা তৈরি করুন, নয়তো module.php-তে 'planned' => true বসান।"],
        )));
    }

    /**
     * সারিটা কেবল কোথাও নয়, **খোলে** এমন জায়গায় নিয়ে যায়।
     *
     * ── কেন উপরেরটা যথেষ্ট ছিল না ───────────────────────────────────
     * উপরের পরীক্ষাটা দেখে রুটের **নাম** নিবন্ধিত কি না। কিন্তু ন-টা
     * হিসাবের রিপোর্ট একই রুট ভাগ করে (`accounts.report.show`), আর
     * তফাত কেবল `slug` প্যারামিটারে। কন্ট্রোলারের তালিকায় ওই slug না
     * থাকলে পাতাটা ৪০৪ দেয় — অথচ রুটের নাম আছে বলে পুরনো পরীক্ষা
     * সবুজ থাকত।
     *
     * ঠিক এটাই ঘটেছিল "আদায়ের তালিকা"-য়: রিপোর্ট লেখা, ইঞ্জিনে
     * নিবন্ধিত, মেনুতে সারি — কিন্তু ঠিকানা থেকে ওই রিপোর্টে পৌঁছানোর
     * সেতুটা কেউ বসায়নি। HP-র পরীক্ষক ২২টা মেনু একে একে খুলে ধরেন
     * (১৪ আগস্ট); ততদিন কেউ জানত না।
     *
     * এখানে প্রতিটা সারি সত্যিই খোলা হয়। ৪০৩ চলে — ওটা অনুমতির কথা
     * বলে, আর ওই ব্যবহারকারীর অনুমতি নেই মানে পর্দাটা নেই তা নয়।
     */
    public function test_every_menu_row_actually_opens(): void
    {
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        /*
         * সব অনুমতিওয়ালা ব্যবহারকারী।
         *
         * কম অনুমতিতে চালালে সারিগুলো ৪০৩ দিত আর পরীক্ষাটা কিছুই
         * প্রমাণ করত না — ভাঙা পাতা আর অনুমতি-নেই পাতা দেখতে এক।
         */
        foreach (app(ModuleRegistry::class)->all() as $module) {
            foreach ($module->permissions as $permission) {
                Permission::findOrCreate($permission, 'web');
            }
        }

        $user = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $user->givePermissionTo(Permission::all());

        $broken = [];

        /*
         * ঘোষণা নয়, **তৈরি মেনু** ধরে হাঁটা।
         *
         * ── কেন ─────────────────────────────────────────────────────
         * `module.php`-এর কাঁচা তালিকায় এমন সারিও আছে যেগুলো Control
         * Panel-এ বন্ধ (কাউন্টার, বহুমুদ্রা, বহর, মেয়াদের রিপোর্ট)।
         * বন্ধ পর্দা ৪০৪ দেয় — আর সেটাই ঠিক, `RefuseSwitchedOffScreens`
         * ঠিক ওই কাজটাই করে। কাঁচা তালিকা ধরে চললে পরীক্ষাটা নিজের
         * ব্যবস্থার সঠিক আচরণকেই ভাঙা বলত।
         *
         * `MenuBuilder` ইতিমধ্যেই অনুমতি, planned ও সুইচ তিনটাই মেনে
         * তালিকা বানায় — অর্থাৎ ব্যবহারকারী যা সত্যিই দেখেন। প্রশ্নটাও
         * ঠিক সেটাই: **যা দেখা যাচ্ছে, তাতে ক্লিক করলে কি কিছু খোলে?**
         */
        foreach (app(MenuBuilder::class)->forUser($user->fresh()) as $module) {
            foreach ($module['groups'] as $group => $rows) {
                foreach ($rows as $row) {
                    if ($row['url'] === null) {
                        continue;
                    }

                    // কেবল GET — POST সারি ঠিকানা নয়, কাজ
                    if (! in_array('GET', Route::getRoutes()->getByName($row['route'])->methods(), true)) {
                        continue;
                    }

                    $status = $this->actingAs($user)->get($row['url'])->getStatusCode();

                    if ($status >= 400) {
                        $broken[] = "{$module['code']}: {$group} → {$row['url']} = {$status}";
                    }
                }
            }
        }

        $this->assertSame([], $broken, implode("\n", array_merge(
            ['মেনুতে দেখা যাচ্ছে, ক্লিক করলে ভাঙে:'],
            $broken,
        )));
    }

    /**
     * পতাকাটা যেন তুলে ফেলতে ভুল না হয়।
     *
     * স্ক্রিন তৈরি হয়ে গেছে অথচ planned => true থেকে গেছে — তাহলে কাজটা
     * শেষ, কিন্তু মেনুতে কোথাও দেখা যাবে না। এই পরীক্ষাটা না থাকলে সেই
     * ভুলটা কেউ কখনো ধরত না, কারণ ভাঙা কিছুই দেখা যেত না।
     */
    public function test_nothing_is_still_marked_planned_once_its_screen_exists(): void
    {
        $stale = [];

        foreach (app(ModuleRegistry::class)->all() as $module) {
            foreach ($module->menu as $group => $items) {
                foreach ($items as $item) {
                    if (($item['planned'] ?? false) === true && Route::has($item['route'])) {
                        $stale[] = "{$module->code}: {$group} → {$item['route']}";
                    }
                }
            }
        }

        $this->assertSame([], $stale, implode("\n", array_merge(
            ['স্ক্রিনটা তৈরি হয়ে গেছে, কিন্তু মেনুতে এখনো লুকানো:'],
            $stale,
            ["module.php থেকে 'planned' => true সরান।"],
        )));
    }

    /**
     * পরিকল্পিত সারি দেখা যায়, কিন্তু **কোথাও নিয়ে যায় না**।
     *
     * ── নিয়মটা ৩ সেপ্টেম্বর ২০২৬-এ উল্টেছে ───────────────────────────
     * আগে এই পরীক্ষা দাবি করত planned সারি মেনুতে **আসেই না**। মালিক
     * রেস্টুরেন্ট মডিউলের জন্য উল্টোটা চেয়েছেন: *"eigulo shudu fontend
     * menute rako Coming soon diye"* — সারিগুলো দেখানোই উদ্দেশ্য, কী কী
     * আসছে তা জানানোর জন্য।
     *
     * ── কিন্তু যেটা পাহারা দেওয়া দরকার ছিল, সেটা এখনো দেওয়া হচ্ছে ───
     * আসল বিপদ কখনোই "সারিটা দেখা যাচ্ছে" ছিল না — বিপদ ছিল **ক্লিক
     * করলে কী হয়**। রুটটা নেই, তাই `route()` ডাকলে ব্যতিক্রম, আর
     * `href=""` বসলে পাতাটা নিজেকেই আবার খুলত।
     *
     * তাই দাবিটা এখন দুইটা: planned সারির `url` **সবসময় `null`**, আর
     * যে সারির `url` আছে তার রুটও সত্যিই আছে। প্রথমটা না থাকলে একটা
     * মরা লিংক মেনুতে বসত; দ্বিতীয়টা না থাকলে পতাকা নামাতে ভুলে গেলে
     * ধরা পড়ত না।
     */
    public function test_planned_items_are_shown_but_never_lead_anywhere(): void
    {
        $planned = [];

        foreach (app(ModuleRegistry::class)->all() as $module) {
            foreach ($module->menu as $items) {
                foreach ($items as $item) {
                    if ($item['planned'] ?? false) {
                        $planned[] = $item['route'];
                    }
                }
            }
        }

        // সব অনুমতিওয়ালা ব্যবহারকারী — কিছু লুকানো থাকলে সেটা যেন
        // অনুমতির অভাবে না হয়, তাহলে পরীক্ষাটা কিছুই প্রমাণ করত না
        $user = User::factory()->create();

        foreach (app(ModuleRegistry::class)->all() as $module) {
            foreach ($module->permissions as $permission) {
                Permission::findOrCreate($permission, 'web');
            }
        }

        $user->givePermissionTo(Permission::all());

        $rendered = collect(app(MenuBuilder::class)->forUser($user->fresh()))
            ->flatMap(fn (array $module) => collect($module['groups'])->flatten(1));

        /*
         * ── প্রথম দাবি: planned সারি কোথাও নিয়ে যায় না ──────────────
         * এটাই আসল পাহারা। `url` থেকে গেলে মেনুতে একটা মরা লিংক বসত,
         * আর সেটা "শীঘ্রই" লেখা থাকা সত্ত্বেও ক্লিক করা যেত।
         */
        $clickable = $rendered
            ->filter(fn (array $row) => in_array($row['route'], $planned, true))
            ->filter(fn (array $row) => $row['url'] !== null)
            ->pluck('route')
            ->values()
            ->all();

        $this->assertSame([], $clickable, implode("\n", array_merge(
            ['এই সারিগুলো এখনো তৈরি হয়নি, তবু ক্লিক করা যায়:'],
            $clickable,
        )));

        /*
         * ── দ্বিতীয় দাবি: দেখানোই যদি হয়, তবে সারিটা দেখা যাক ───────
         * উপরের দাবিটা একা রাখলে "সব সারি বাদ দাও" লিখেও পাস করানো
         * যেত — আর তখন মালিকের চাওয়া জিনিসটাই আবার হারাত।
         */
        $shown = $rendered->pluck('route')->all();

        $this->assertSame(
            [], array_values(array_diff($planned, $shown)),
            'পরিকল্পিত সারিগুলো মেনুতে দেখা যাওয়ার কথা ("শীঘ্রই" লেখা নিয়ে), কিন্তু যায়নি।',
        );

        // আর যে সারিতে ঠিকানা আছে, তার রুটও সত্যিই আছে
        foreach ($rendered as $row) {
            if ($row['url'] !== null) {
                $this->assertTrue(Route::has($row['route']), "মেনুতে {$row['route']} আছে অথচ রুট নেই।");
            }
        }
    }

    /** লেবেলগুলো দুই ভাষাতেই অনুবাদ আছে কি না — নিয়ম ৯। */
    public function test_every_menu_label_is_translated_in_both_languages(): void
    {
        $missing = [];

        foreach (app(ModuleRegistry::class)->all() as $module) {
            foreach ($module->menu as $items) {
                foreach ($items as $item) {
                    foreach (['bn', 'en'] as $locale) {
                        if (! Lang::has($item['label'], $locale)) {
                            $missing[] = "{$module->code}: {$item['label']} ({$locale})";
                        }
                    }
                }
            }
        }

        $this->assertSame([], $missing, "অনুবাদ নেই:\n".implode("\n", $missing));
    }
}
