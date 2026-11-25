<?php

declare(strict_types=1);

namespace Tests\Feature\Speed;

use App\Core\Security\CheapPermissionRegistrar;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ One permission by name comes from an index (speed work, 25 Nov 2026; [[CheapPermissionRegistrar::getPermissions()]]) —
 * the same answer as spatie's own walk, for every key, an unknown key and the wrong guard; and a new key is seen after the
 * cache is dropped.
 */
final class AKeyIsFoundByNameWithoutWalkingTheListTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_index_answers_exactly_as_the_walk_for_every_key(): void
    {
        $this->seed(DemoSeeder::class);
        $registrar = app(PermissionRegistrar::class);
        $this->assertInstanceOf(CheapPermissionRegistrar::class, $registrar);
        $asked = 0;

        foreach (Permission::query()->get() as $row) {
            foreach (['web', 'no-such-guard'] as $guard) {
                $how = ['name' => $row->name, 'guard_name' => $guard];
                $mine = $registrar->getPermissions($how, true);
                // ⓘ the list without `onlyOne` is spatie's own path — its first row is what the walk would give
                $walk = $registrar->getPermissions($how)->values()->first();
                $this->assertSame($walk?->getKey(), $mine->first()?->getKey(), "⛔ {$row->name} ({$guard}) answered differently.");
                $asked++;
            }
        }

        $this->assertGreaterThan(400, $asked);
        $this->assertCount(0, $registrar->getPermissions(['name' => 'no.such.key', 'guard_name' => 'web'], true));
    }

    public function test_a_new_key_is_seen_after_the_cache_is_dropped(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $how = ['name' => 'claim.brand.new', 'guard_name' => 'web'];
        Permission::findOrCreate('claim.first', 'web');
        $registrar->getPermissions(['name' => 'claim.first', 'guard_name' => 'web'], true);
        $this->assertCount(0, $registrar->getPermissions($how, true));

        Permission::create($how);
        $registrar->forgetCachedPermissions();
        $this->assertCount(1, $registrar->getPermissions($how, true), '⛔ the index outlived the list it was built from.');
    }
}
