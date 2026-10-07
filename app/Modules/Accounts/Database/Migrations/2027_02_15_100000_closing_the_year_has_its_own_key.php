<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * ⛔ বছর বন্ধের নিজের চাবি — পুরো-ERP অডিট, ৬ অক্টোবর ২০২৬ (হিসাব ⚠️৭; [[ClosingTheYearHasItsOwnKeyTest]])।
 *
 * ⓘ আগে বছর বন্ধ হত চূড়ান্ত হিসাব দেখার চাবিতে (`accounts.report.final`)। এখন `accounts.year.close`। চালু কোম্পানিতে চাবিটা পায়
 * কেবল সেই ভূমিকা যার মাস বন্ধের চাবি (`accounts.period.close`) আছে — বছর বন্ধ মাস বন্ধেরই বড় ভাই; কেবল দেখার ভূমিকা পায় না।
 * মালিক (সুপার অ্যাডমিন) চাবি ছাড়াই পারেন। আগে থেকে থাকলে কিছুই নয়; লগে কে পেল।
 */
return new class extends Migration
{
    public function up(): void
    {
        $guard = 'web';
        $from = DB::table('permissions')->where('name', 'accounts.period.close')->where('guard_name', $guard)->value('id');
        $given = [];

        if ($from !== null) {
            $to = $this->permission('accounts.year.close', $guard);

            foreach (DB::table('role_has_permissions')->where('permission_id', $from)->pluck('role_id') as $roleId) {
                if (DB::table('role_has_permissions')->where('role_id', $roleId)->where('permission_id', $to)->exists()) {
                    continue;
                }

                DB::table('role_has_permissions')->insert(['permission_id' => $to, 'role_id' => $roleId]);
                $given[] = (int) $roleId;
            }
        }

        Log::info('Year close: roles given accounts.year.close', ['roles' => $given]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        DB::table('role_has_permissions')
            ->whereIn('permission_id', DB::table('permissions')->where('name', 'accounts.year.close')->select('id'))
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
