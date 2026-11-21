<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\PermissionRegistrar;

/**
 * Everyone in the company may talk — the plan's default (owner "ok", 21 Nov 2026): `messaging.use` on every existing
 * role. ⓘ `messaging.group.create` and `messaging.audit` go to nobody here: the owner (super_admin) has every key, and
 * hands the rest out from the roles page.
 *
 * ⓘ The key row itself may not exist yet on a fresh deploy (keys are synced from module.php); it is created here if
 * missing, the same way the sync would.
 */
return new class extends Migration
{
    public function up(): void
    {
        $id = DB::table('permissions')->where('name', 'messaging.use')->where('guard_name', 'web')->value('id');

        if ($id === null) {
            $id = DB::table('permissions')->insertGetId([
                'name' => 'messaging.use', 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $given = 0;
        foreach (DB::table('roles')->where('guard_name', 'web')->get(['id', 'name']) as $role) {
            if ($role->name === 'super_admin'
                || DB::table('role_has_permissions')->where('role_id', $role->id)->where('permission_id', $id)->exists()) {
                continue;
            }
            DB::table('role_has_permissions')->insert(['permission_id' => $id, 'role_id' => $role->id]);
            $given++;
        }

        Log::info(sprintf('Messenger for everyone: %d roles given messaging.use', $given));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // ⓘ not taken back — a role that had the key before cannot be told apart; the owner removes it from the roles page
    }
};
