<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * ⭐ বার্তার পথ — the owner chooses the roads of party messages: WhatsApp by button, WhatsApp by itself (Meta Cloud
 * API), SMS on the office phone (owner "ok" 23 Nov 2026, sample HUXqpfhxng8823eEFrvH54, tab ঘ).
 *
 * ⓘ The page's key `notification.party_roads` — the Meta token lives behind it, so only the owner (super_admin) gets it
 *   here; no role template carries it. ⚠️ Deploy does not sync permissions, hence the grant in a migration.
 * ⓘ No table: the Cloud API's settings live in `notification_channels` (channel `party_whatsapp`, encrypted credentials).
 */
return new class extends Migration
{
    private const KEY = 'notification.party_roads';

    public function up(): void
    {
        $guard = 'web';
        $id = DB::table('permissions')->where('name', self::KEY)->where('guard_name', $guard)->value('id')
            ?? DB::table('permissions')->insertGetId([
                'name' => self::KEY, 'guard_name' => $guard, 'public_id' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        $supers = DB::table('roles')->where('name', 'super_admin')->where('guard_name', $guard)->pluck('id');

        foreach ($supers as $roleId) {
            if (! DB::table('role_has_permissions')->where('role_id', $roleId)->where('permission_id', $id)->exists()) {
                DB::table('role_has_permissions')->insert(['permission_id' => $id, 'role_id' => $roleId]);
            }
        }

        Log::info(sprintf('Message roads key: %d super admin role(s) given %s', $supers->count(), self::KEY));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        DB::table('role_has_permissions')
            ->whereIn('permission_id', DB::table('permissions')->where('name', self::KEY)->select('id'))
            ->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
