<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * ⭐ ক্রয়ের খরচ রিপোর্টের চাবি — `purchase.cost.report` (মালিকের অনুমোদিত নমুনা v6, ট্যাব গ; ২১ নভেম্বর ২০২৬)।
 *
 * ⛔ কেবল `purchase.report` থাকলেই নয়: রিপোর্টের প্রতিটা সারিতে ক্রয় মূল্য, আর ক্রয়মূল্য দেখার নিজের চাবি আছে
 *   (`inventory.cost.view` — পণ্যের `purchase_price` ঢাকে, [[FieldSecurity]])। তাই যে ভূমিকার দুইটাই আছে, সে পায়;
 *   আর মার্জিন দেখার চাবি (`purchase.settlement.view`) যার আছে সে ক্রয়মূল্য এমনিতেই দেখে — সেও পায়।
 *   ⓘ 'Manager' নাম ধরে ঢালাও নয় — ব্যবস্থাপকের হাতে ক্রয়মূল্যের চাবি না থাকলে এই রিপোর্ট দামটা খুলে দিত।
 */
return new class extends Migration
{
    private const KEY = 'purchase.cost.report';

    public function up(): void
    {
        $guard = 'web';
        $to = $this->permission(self::KEY, $guard);

        $id = fn (string $name) => DB::table('permissions')->where('name', $name)->where('guard_name', $guard)->value('id');
        $holders = fn ($permissionId) => $permissionId === null
            ? collect()
            : DB::table('role_has_permissions')->where('permission_id', $permissionId)->pluck('role_id');

        $report = $holders($id('purchase.report'));
        $cost = $holders($id('inventory.cost.view'));
        $margin = $holders($id('purchase.settlement.view'));

        $roles = $report->intersect($cost)->merge($margin)->unique()->values();

        $given = [];
        foreach ($roles as $roleId) {
            if (DB::table('role_has_permissions')->where('role_id', $roleId)->where('permission_id', $to)->exists()) {
                continue;
            }
            DB::table('role_has_permissions')->insert(['permission_id' => $to, 'role_id' => $roleId]);
            $given[] = (int) $roleId;
        }

        Log::info('Purchase cost report: roles given purchase.cost.report', ['roles' => $given]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        DB::table('role_has_permissions')
            ->whereIn('permission_id', DB::table('permissions')->where('name', self::KEY)->select('id'))
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
