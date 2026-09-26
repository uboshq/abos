<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Module\ModuleRegistry;
use App\Core\Services\MenuSwitches;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * প্রতিটা রিপোর্ট নিজের সংজ্ঞায় সেই চাবিই বলে, যা তার ওয়েবের দরজা চায়।
 *
 * ── ⛔ কী ঘটছিল, ২৭ সেপ্টেম্বর ২০২৬ ─────────────────────────────────────
 * ৬৫টা রিপোর্টের একটাও [[ReportDefinition::$permission]] ঘোষণা করত না।
 * ওয়েব নিজের কন্ট্রোলারে চাবি দেখত, কিন্তু সূচি, নামানো আর ফোন দেখত কেবল
 * সংজ্ঞা — আর null মানে ধরা হত "সবাই"। ফল: কেবল সূচির চাবি হাতে যে কেউ
 * নিজের জন্য লাভ-ক্ষতি সূচি করে ফাইলটা পেতেন।
 *
 * ── ⭐ মাপা হয় আচরণ, লেখা নয় ──────────────────────────────────────────
 * একজন ভূমিকাহীন মানুষ, সংজ্ঞার চাবিগুলো হাতে — দরজা খুলতেই হবে। তারপর
 * **একই মানুষ** থেকে একটা একটা চাবি কাড়া — প্রতিবার ৪০৩ হতেই হবে
 * ([[same-user-key-off-then-on]])। ⓘ দুই দিকই ধরা পড়ে: সংজ্ঞা কম চাইলে
 * প্রথম ধাপ লাল (দরজা আরও চায়), বেশি চাইলে দ্বিতীয় ধাপ লাল (দরজা
 * কাড়া চাবি ছাড়াই খোলে)।
 *
 * ⛔ কোনো ছাড় নেই — ওয়েবে দরজা নেই এমন রিপোর্টও লাল, কারণ তার চাবি
 * তখন কেউ মেলাতে পারত না।
 */
final class EveryReportNamesTheKeyItsWebDoorAsksForTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        // ⓘ [[EveryReportScreenOpensInEveryModuleTest]]-এর একই কারণে: বন্ধ পর্দা
        // অনুমতির আগেই ৪০৪ দেয়, আর তখন চাবির প্রশ্নটা কখনো জিজ্ঞেসই হত না
        app(SettingsService::class)->set('inventory.batch_enabled', true);

        foreach (app(ModuleRegistry::class)->all() as $module) {
            app(SettingsService::class)->set(app(MenuSwitches::class)->forModule($module->code), true);
        }
    }

    public function test_every_registered_report_declares_a_key(): void
    {
        $engine = app(ReportEngine::class);
        $bare = array_values(array_filter($engine->keys(), fn (string $key): bool => $engine->get($key)->permissions() === []));

        $this->assertGreaterThan(40, count($engine->keys()), 'রিপোর্ট প্রায় নেই — খোঁজাটাই ভেঙেছে।');
        $this->assertSame([], $bare, "এই রিপোর্টগুলো কোনো চাবি ঘোষণা করে না — সূচি আর ফোন এদের সুপার অ্যাডমিন ছাড়া কাউকে দেবে না:\n".implode("\n", $bare));
    }

    public function test_every_report_names_exactly_the_keys_its_web_door_asks_for(): void
    {
        $engine = app(ReportEngine::class);
        $doors = $this->webDoors();
        $wrong = [];

        foreach ($engine->keys() as $key) {
            if (! isset($doors[$key])) {
                $wrong[] = "{$key}: ওয়েবে কোনো দরজা নেই";

                continue;
            }

            [$route, $slug] = $doors[$key];
            $url = route($route, ['slug' => $slug]);
            $keys = $engine->get($key)->permissions();

            $user = $this->member();
            foreach ($keys as $permission) {
                $this->give($user, $permission);
            }

            $status = $this->open($user, $url);
            if ($status !== 200) {
                $wrong[] = "{$key}: [".implode(', ', $keys)."] হাতে দরজা {$status} দিল — দরজা আরও কিছু চায়";

                continue;
            }

            foreach ($keys as $permission) {
                $this->take($user, $permission);
                $status = $this->open($user, $url);
                $this->give($user, $permission);

                if ($status !== 403) {
                    $wrong[] = "{$key}: '{$permission}' কেড়ে নিলেও দরজা {$status} দিল — সংজ্ঞা দরজার চেয়ে বেশি চায়";
                }
            }
        }

        $this->assertSame([], $wrong, implode("\n", array_merge(
            ['রিপোর্টের সংজ্ঞা আর ওয়েবের দরজা আলাদা চাবি চায়:', ''],
            $wrong,
            ['', '⛔ সংজ্ঞার চাবি সূচি, নামানো আর ফোনের একমাত্র পাহারা — ওয়েব যা চায় হুবহু সেটাই লিখুন।'],
        )));
    }

    /** @return array<string, array{0: string, 1: string}> রিপোর্ট → [রুট, স্লাগ] */
    private function webDoors(): array
    {
        $doors = [];

        foreach (app('router')->getRoutes() as $route) {
            $name = $route->getName();

            if ($name === null || ! str_ends_with($name, '.report.show')) {
                continue;
            }

            $controller = strtok($route->getActionName(), '@');
            if (! class_exists($controller)) {
                continue;
            }

            foreach ((new ReflectionClass($controller))->getConstants()['SLUGS'] ?? [] as $slug => $target) {
                // ⓘ ক্রয়ের তালিকায় প্রতিটা স্লাগ নিজের চাবিসহ: ['key' => …, 'permission' => …]
                $doors[is_array($target) ? $target['key'] : $target] = [$name, (string) $slug];
            }
        }

        return $doors;
    }

    private function member(): User
    {
        $user = User::factory()->create(['current_company_id' => $this->company->id, 'is_active' => true]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);

        return $user;
    }

    private function give(User $user, string $permission): void
    {
        CompanyContext::forCompany($this->company->id, fn () => $user->givePermissionTo(Permission::findOrCreate($permission, 'web')));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function take(User $user, string $permission): void
    {
        CompanyContext::forCompany($this->company->id, fn () => $user->revokePermissionTo($permission));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function open(User $user, string $url): int
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user->fresh())->get($url)->getStatusCode();
    }
}
