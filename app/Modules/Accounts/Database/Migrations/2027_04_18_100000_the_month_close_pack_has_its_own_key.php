<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * ⭐ মাস শেষের হিসাবের চাবি — চালু কোম্পানিতে যাঁদের চূড়ান্ত হিসাবের চাবি (`accounts.report.final`) আছে, তাঁদের ভূমিকায় (মালিক,
 * ১৭ নভেম্বর ২০২৬; [[MonthCloseController]])। ⓘ ছাঁচ কেবল নতুন কোম্পানিতে লাগে, তাই লাইভের হিসাবরক্ষক এটা দিয়েই পান।
 * ⓘ দুইবার চালালেও একটাই জোড়া; প্রতিটা ভূমিকার এক লাইন লগে। ⓘ মালিক super_admin — চাবি ছাড়াই খোলেন।
 * ↩ ফেরানো: চাবির সব জোড়া মোছে (চাবিটা থাকে — ঘোষণা module.php-তে)।
 */
return new class extends Migration
{
    public function up(): void
    {
        $guard = 'web';
        $from = DB::table('permissions')->where('name', 'accounts.report.final')->where('guard_name', $guard)->value('id');
        $to = $this->permission('accounts.report.month_close', $guard);
        $given = 0;

        if ($from !== null) {
            $roles = DB::table('role_has_permissions as rp')
                ->join('roles as r', 'r.id', '=', 'rp.role_id')
                ->leftJoin('companies as c', 'c.id', '=', 'r.company_id')
                ->where('rp.permission_id', $from)
                ->orderBy('r.company_id')->orderBy('r.id')
                ->get(['r.id', 'r.name', 'r.company_id', 'c.code']);

            foreach ($roles as $role) {
                if (DB::table('role_has_permissions')->where('role_id', $role->id)->where('permission_id', $to)->exists()) {
                    continue;
                }

                DB::table('role_has_permissions')->insert(['permission_id' => $to, 'role_id' => $role->id]);
                $given++;

                Log::info(sprintf('Month close key given: company=%s role=%s team_id=%s', (string) ($role->code ?? '?'), (string) $role->name, (string) $role->company_id), [
                    'company' => $role->code, 'role' => $role->name, 'team_id' => (int) $role->company_id,
                ]);
            }
        }

        Log::info(sprintf('Month close key: %d role(s) given accounts.report.month_close (those with accounts.report.final)', $given), ['given' => $given]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        DB::table('role_has_permissions')
            ->whereIn('permission_id', DB::table('permissions')->where('name', 'accounts.report.month_close')->select('id'))
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
