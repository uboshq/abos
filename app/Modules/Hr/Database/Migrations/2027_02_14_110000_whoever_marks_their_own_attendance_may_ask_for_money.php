<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * ⭐ নিজের হাজিরা যিনি দেন, তিনি নিজের খরচের দাবিও পাঠান — মালিকের আদেশ, ৭ অক্টোবর ২০২৬ ([[ExpenseClaimService]])।
 *
 * ⓘ ভূমিকার ছাঁচ চালু কোম্পানির পুরনো ভূমিকায় নতুন চাবি দেয় না (ছাঁচ কখনো চালু ভূমিকা চওড়া করে না), তাই লাইভে কেউ দাবি
 * পাঠাতে পারতেন না। এখানে স্পষ্ট করে, লগসহ:
 *   · যে ভূমিকার `hr.attendance.self` আছে → `hr.claim.self` (মাঠের কর্মী, কাউন্টার, হিসাবরক্ষক …)
 *   · যে ভূমিকার `hr.employee.manage` আছে → `hr.claim.view` (HR — সবার দাবি দেখা)
 *
 * ⛔ সইয়ের চাবি নয় — সই কোম্পানির নিজের ছকে। আগে থেকে থাকলে কিছুই নয়।
 */
return new class extends Migration
{
    public function up(): void
    {
        $guard = 'web';
        $given = [];

        foreach ([['hr.attendance.self', 'hr.claim.self'], ['hr.employee.manage', 'hr.claim.view']] as [$has, $gets]) {
            $from = DB::table('permissions')->where('name', $has)->where('guard_name', $guard)->value('id');

            if ($from === null) {
                continue;
            }

            $to = $this->permission($gets, $guard);

            foreach (DB::table('role_has_permissions')->where('permission_id', $from)->pluck('role_id') as $roleId) {
                if (DB::table('role_has_permissions')->where('role_id', $roleId)->where('permission_id', $to)->exists()) {
                    continue;
                }

                DB::table('role_has_permissions')->insert(['permission_id' => $to, 'role_id' => $roleId]);
                $given[] = ['role_id' => (int) $roleId, 'key' => $gets];
            }
        }

        Log::info('Expense claims: roles given the claim keys', ['roles' => $given]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        DB::table('role_has_permissions')
            ->whereIn('permission_id', DB::table('permissions')->whereIn('name', ['hr.claim.self', 'hr.claim.view'])->select('id'))
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
