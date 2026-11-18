<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/*
 * ⭐ দিন শেষের হিসাবের দুই চাবি চালু কোম্পানির ভূমিকায় (১৮ নভেম্বর ২০২৬; মালিক: "এখনই করে লাইভে দাও")।
 *
 * ⓘ ভূমিকার ছাঁচ কেবল নতুন কোম্পানিতে লাগে — লাইভের ভূমিকা এখানে:
 *   · দেখা (`accounts.day_close.view`) — যে ভূমিকার হিসাবের রিপোর্টের চাবি আছে (`accounts.report`);
 *   · দিন বন্ধ (`accounts.day_close.close`) — যে ভূমিকার চূড়ান্ত হিসাবের চাবি আছে (`accounts.report.final`), অর্থাৎ হিসাবরক্ষক।
 * ⓘ মালিক সুপার অ্যাডমিন — সব চাবি তাঁর আগেই আছে।
 */
return new class extends Migration
{
    private const GRANTS = [
        'accounts.day_close.view' => 'accounts.report',
        'accounts.day_close.close' => 'accounts.report.final',
    ];

    public function up(): void
    {
        $guard = 'web';

        foreach (self::GRANTS as $key => $whoHas) {
            $to = $this->permission($key, $guard);
            $from = DB::table('permissions')->where('name', $whoHas)->where('guard_name', $guard)->value('id');

            if ($from === null) {
                continue;
            }

            $given = [];

            foreach (DB::table('role_has_permissions')->where('permission_id', $from)->pluck('role_id')->unique() as $roleId) {
                if (DB::table('role_has_permissions')->where('role_id', $roleId)->where('permission_id', $to)->exists()) {
                    continue;
                }

                DB::table('role_has_permissions')->insert(['permission_id' => $to, 'role_id' => $roleId]);
                $given[] = (int) $roleId;
            }

            Log::info('Day closing: roles given '.$key, ['roles' => $given]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        DB::table('role_has_permissions')
            ->whereIn('permission_id', DB::table('permissions')->whereIn('name', array_keys(self::GRANTS))->select('id'))
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
