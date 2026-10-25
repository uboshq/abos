<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * ⭐ ব্যাংক ফাইলের (BEFTN) নিজের চাবি — `accounts.voucher.bank_file` (fe, ২৫ অক্টোবর ২০২৬; SAP F110-এর payment medium সবচেয়ে
 * স্পর্শকাতর টাকার কাগজ — প্রাপকের পুরো হিসাব নম্বর)।
 *
 * ⓘ লাইভের কোম্পানিতে চাবিটা পায় কেবল যে ভূমিকা আগে থেকেই পরিশোধ পাকা করতে পারে (`accounts.voucher.update`) — নতুন কোম্পানির
 * ছাঁচে হিসাবরক্ষক ([[module.php]] `roles`); সুপার অ্যাডমিন আর মালিক সব চাবি এমনিই পান। কোন ভূমিকা পেল, লগে লেখা থাকে।
 * ⓘ "বছর বন্ধ"-এর নিজের চাবির একই ছাঁচ (2027_02_15_100000)।
 */
return new class extends Migration
{
    public function up(): void
    {
        $guard = 'web';
        $from = DB::table('permissions')->where('name', 'accounts.voucher.update')->where('guard_name', $guard)->value('id');
        $to = $this->permission('accounts.voucher.bank_file', $guard);
        $given = [];

        if ($from !== null) {
            foreach (DB::table('role_has_permissions')->where('permission_id', $from)->pluck('role_id') as $roleId) {
                if (DB::table('role_has_permissions')->where('role_id', $roleId)->where('permission_id', $to)->exists()) {
                    continue;
                }

                DB::table('role_has_permissions')->insert(['permission_id' => $to, 'role_id' => $roleId]);
                $given[] = (int) $roleId;
            }
        }

        Log::info('Bank file: roles given accounts.voucher.bank_file (those that post payments)', ['roles' => $given]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        DB::table('role_has_permissions')
            ->whereIn('permission_id', DB::table('permissions')->where('name', 'accounts.voucher.bank_file')->select('id'))
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
