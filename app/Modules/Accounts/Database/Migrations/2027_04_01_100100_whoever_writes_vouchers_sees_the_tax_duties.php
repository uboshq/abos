<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * ⭐ যিনি ভাউচার লেখেন, তিনিই করণীয় দেখেন আর "করা হয়েছে" দেন — কোষাগারে জমা, মূসক-৬.৬ (টাকা দেওয়ার মিল-তালিকা #৩; fe, ২২
 * অক্টোবর ২০২৬)।
 *
 * ⓘ ভূমিকার ছাঁচ চালু কোম্পানির পুরনো ভূমিকায় নতুন চাবি দেয় না, তাই লাইভের হিসাবরক্ষক পেতেন না — হাজিরা থেকে ছুটির একই সমাধান
 * (`2027_02_24_100000_whoever_marks_their_own_attendance_may_ask_for_leave`), লগসহ:
 *   · যে ভূমিকার `accounts.voucher.create` আছে → `accounts.obligation.view` আর `accounts.obligation.update`
 *
 * ⓘ মালিক (super_admin) সব চাবি এমনিই পান। আগে থেকে থাকলে কিছুই নয়; না চাইলে মালিক রোলের পর্দা থেকে তুলে নেন।
 */
return new class extends Migration
{
    private const GIVES = ['accounts.obligation.view', 'accounts.obligation.update'];

    public function up(): void
    {
        $guard = 'web';
        $given = [];

        $from = DB::table('permissions')->where('name', 'accounts.voucher.create')->where('guard_name', $guard)->value('id');

        if ($from === null) {
            return;
        }

        $roles = DB::table('role_has_permissions')->where('permission_id', $from)->pluck('role_id');

        foreach (self::GIVES as $key) {
            $to = $this->permission($key, $guard);

            foreach ($roles as $roleId) {
                if (DB::table('role_has_permissions')->where('role_id', $roleId)->where('permission_id', $to)->exists()) {
                    continue;
                }

                DB::table('role_has_permissions')->insert(['permission_id' => $to, 'role_id' => $roleId]);
                $given[] = ['role_id' => (int) $roleId, 'key' => $key];
            }
        }

        Log::info('Tax duties: voucher writers given the obligation keys', ['roles' => $given]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        DB::table('role_has_permissions')
            ->whereIn('permission_id', DB::table('permissions')->whereIn('name', self::GIVES)->select('id'))
            ->delete();

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
