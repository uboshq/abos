<?php

declare(strict_types=1);

namespace Tests\Feature\Speed;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * ⛔ The home page gave 500 to everyone on a real server (demo, 23 Nov 2026 23:48) — and every test was green.
 *
 * ⓘ Why the tests missed it: phpunit runs on the `array` cache store, which keeps PHP values as they are. A real server
 * runs on `file` (or redis), which serialises, and `config/cache.php` sets `serializable_classes` => false — so a
 * remembered dashboard Widget came back as `__PHP_Incomplete_Class` and DashboardRegistry::forUser() threw on it
 * ([[DashboardFigureCache]]; fixed in a11bc0c33).
 *
 * ⭐ Claim: on a serialising store with the production setting, the owner's home opens twice (the second open reads the
 * remembered figures) and both answers are 200 with the board drawn.
 */
final class TheHomeOpensFromARealCacheTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        // ⓘ the production store and setting, in a folder of this test's own
        $this->dir = storage_path('framework/testing/dash-cache-'.bin2hex(random_bytes(4)));
        config([
            'cache.default' => 'file',
            'cache.stores.file.path' => $this->dir,
            'cache.serializable_classes' => false,
        ]);
        Cache::forgetDriver('file');
        Cache::setDefaultDriver('file');

        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_the_home_opens_twice_on_a_serialising_store(): void
    {
        $this->assertSame('file', Cache::getDefaultDriver(), 'the claim must run on a serialising store, or it measures nothing');

        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        $first = $this->actingAs($owner)->get('/');
        $first->assertOk();

        // ⓘ the second open is the one that reads the remembered figures back from the store
        $second = $this->actingAs($owner)->get('/');
        $second->assertOk();

        $this->assertNotEmpty(File::allFiles($this->dir), '⛔ nothing was remembered — the second open did not read from the store, so the claim measured nothing.');
    }
}
