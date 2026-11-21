<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * ⭐ ডেলিভারি খরচের রিপোর্ট — নিজের চাবি `sales.delivery_cost.report` (মালিক, ২১ নভেম্বর ২০২৬; নমুনা v6 খ)।
 *
 * ⓘ রিপোর্টটা খরচ দেখায়, তাই মার্জিনের রিপোর্টের মতো আলাদা চাবি। যে ভূমিকার `sales.margin.report` আছে সে পায়, আর
 *   "Manager" নামের ভূমিকা; ভূমিকার ছাঁচ চালু কোম্পানির পুরনো ভূমিকায় নতুন চাবি দেয় না, তাই এখানে (লগসহ)। আগে থেকে থাকলে কিছুই নয়।
 */
return new class extends Migration
{
    private const KEY = 'sales.delivery_cost.report';

    public function up(): void
    {
        $guard = 'web';
        $to = $this->permission(self::KEY, $guard);
        $from = DB::table('permissions')->where('name', 'sales.margin.report')->where('guard_name', $guard)->value('id');

        $roles = DB::table('roles')->whereIn('name', ['Manager'])->pluck('id');
        if ($from !== null) {
            $roles = $roles->merge(DB::table('role_has_permissions')->where('permission_id', $from)->pluck('role_id'));
        }

        $given = [];
        foreach ($roles->unique() as $roleId) {
            if (DB::table('role_has_permissions')->where('role_id', $roleId)->where('permission_id', $to)->exists()) {
                continue;
            }
            DB::table('role_has_permissions')->insert(['permission_id' => $to, 'role_id' => $roleId]);
            $given[] = (int) $roleId;
        }

        Log::info('Delivery cost report: roles given sales.delivery_cost.report', ['roles' => $given]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->where('name', self::KEY)->pluck('id');

        DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('model_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function permission(string $name, string $guard): int
    {
        $id = DB::table('permissions')->where('name', $name)->where('guard_name', $guard)->value('id');

        if ($id !== null) {
            return (int) $id;
        }

        return (int) DB::table('permissions')->insertGetId([
            'name' => $name, 'guard_name' => $guard, 'public_id' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
};
