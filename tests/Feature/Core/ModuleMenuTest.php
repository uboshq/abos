<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Module\ModuleRegistry;
use App\Core\Services\MenuBuilder;
use App\Models\User;
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

    /** পরিকল্পিত সারিগুলো সত্যিই কোনো ব্যবহারকারীর মেনুতে যায় না। */
    public function test_planned_items_never_reach_a_rendered_menu(): void
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
            ->flatMap(fn (array $module) => collect($module['groups'])->flatten(1))
            ->pluck('route')
            ->all();

        $this->assertSame([], array_values(array_intersect($planned, $rendered)));

        // আর যা দেখানো হচ্ছে তার প্রতিটাই সত্যিকারের রুট
        foreach ($rendered as $route) {
            $this->assertTrue(Route::has($route), "মেনুতে {$route} আছে অথচ রুট নেই।");
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
