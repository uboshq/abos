<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * ⭐ চলতি মূলধন কেবল মালিকের — মালিক, 10 নভেম্বর 2026 সন্ধ্যা: "sobar switch off defolt, malik kauke dibe mone korle nijer hate anumoti dibe"।
 *
 * ⓘ নতুন চাবি finance.working_capital.view — কেবল super_admin রোল পায় (মালিক সব কোম্পানিতে super_admin)।
 * ⚠️ ডিপ্লয় অনুমতি মেলায় না (কেবল migrate আর optimise), তাই চাবিটা এখানেই বানানো ও দেওয়া; আর কোনো রোলে নয়।
 */
return new class extends Migration
{
    public function up(): void
    {
        $guard = 'web';
        $id = DB::table('permissions')->where('name', 'finance.working_capital.view')->where('guard_name', $guard)->value('id')
            ?? DB::table('permissions')->insertGetId([
                'name' => 'finance.working_capital.view', 'guard_name' => $guard, 'public_id' => (string) Str::uuid(),
                'created_at' => now(), 'updated_at' => now(),
            ]);

        $supers = DB::table('roles')->where('name', 'super_admin')->where('guard_name', $guard)->pluck('id');

        foreach ($supers as $roleId) {
            if (! DB::table('role_has_permissions')->where('role_id', $roleId)->where('permission_id', $id)->exists()) {
                DB::table('role_has_permissions')->insert(['permission_id' => $id, 'role_id' => $roleId]);
            }
        }

        Log::info(sprintf('Working capital key: %d super admin role(s) given finance.working_capital.view', $supers->count()));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        DB::table('role_has_permissions')
            ->whereIn('permission_id', DB::table('permissions')->where('name', 'finance.working_capital.view')->select('id'))
            ->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
