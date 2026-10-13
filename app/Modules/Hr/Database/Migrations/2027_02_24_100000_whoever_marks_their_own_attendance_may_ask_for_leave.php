<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * ⭐ নিজের হাজিরা যিনি দেন, তিনি ফোনে নিজের ছুটিও চান আর নিজের বেতনের স্লিপ দেখেন — মালিকের আদেশ, ১৩ অক্টোবর ২০২৬
 * (বাকি মডিউল অ্যাপে; [[LeaveApiController]], [[PayslipApiController]])।
 *
 * ⓘ ভূমিকার ছাঁচ চালু কোম্পানির পুরনো ভূমিকায় নতুন চাবি দেয় না, তাই লাইভে কেউ পেতেন না — খরচের দাবির একই সমাধান
 * (`2027_02_14_110000_whoever_marks_their_own_attendance_may_ask_for_money`), লগসহ:
 *   · যে ভূমিকার `hr.attendance.self` আছে → `hr.leave.self` আর `hr.payslip.self`
 *
 * ⛔ কেবল নিজের — অন্যের ছুটি বসানো (`hr.leave.manage`) বা সবার বেতন (`hr.payroll.view`) নয়। আগে থেকে থাকলে কিছুই নয়।
 * ⓘ চাবিটা না চাইলে মালিক রোলের পর্দা থেকে তুলে নেন — ছাঁচ বা এই ধাপ আবার বসায় না।
 */
return new class extends Migration
{
    private const GIVES = ['hr.leave.self', 'hr.payslip.self'];

    public function up(): void
    {
        $guard = 'web';
        $given = [];

        $from = DB::table('permissions')->where('name', 'hr.attendance.self')->where('guard_name', $guard)->value('id');

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

        Log::info('Own leave and payslip on the phone: roles given the self keys', ['roles' => $given]);

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
