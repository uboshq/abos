<?php

declare(strict_types=1);

use App\Core\Services\DealerScope;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * ⭐ জমার খবর — স্লিপ কয়েক হাত ঘুরে আসে (মালিক, ১৬ নভেম্বর ২০২৬: *"ডিলার জমা দিয়ে এসআরের কাছে পাঠায় দেয় … আত্মীয় স্বজন বন্ধুবান্ধব
 * … ম্যানেজারকে পাঠায় … বস আরএসএম বা ডিএসএমের কাছে … তারা আবার অ্যাপসে এন্ট্রি দেয়"*)।
 *
 *   · `depositor` — টাকা কে জমা দিলেন (ঐচ্ছিক লেখা)
 *   · `lookalike_claim_id` — একই চেহারার স্লিপের আগের দাবি ([[DepositSlip::markLookalike()]]); সতর্কতা, আটকানো নয়
 *   · চাবি `sales.claim.submit` — যে ভূমিকার আদায়ের চাবি আছে, আর মাঠের সব ভূমিকা ([[DealerScope::FIELD_ROLES]]) পায়;
 *     ভূমিকার ছাঁচ চালু কোম্পানির পুরনো ভূমিকায় নতুন চাবি দেয় না, তাই এখানে (লগসহ)। আগে থেকে থাকলে কিছুই নয়।
 */
return new class extends Migration
{
    private const KEY = 'sales.claim.submit';

    public function up(): void
    {
        Schema::table('sal_deposit_claims', function (Blueprint $table) {
            if (! Schema::hasColumn('sal_deposit_claims', 'depositor')) {
                $table->string('depositor', 120)->nullable()->after('note');
            }
            if (! Schema::hasColumn('sal_deposit_claims', 'lookalike_claim_id')) {
                $table->unsignedBigInteger('lookalike_claim_id')->nullable()->after('depositor');
            }
        });

        $guard = 'web';
        $to = $this->permission(self::KEY, $guard);
        $from = DB::table('permissions')->where('name', 'sales.collection.create')->where('guard_name', $guard)->value('id');

        $roles = DB::table('roles')->whereIn('name', DealerScope::FIELD_ROLES)->pluck('id');
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

        Log::info('Deposit report: roles given sales.claim.submit', ['roles' => $given]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        DB::table('role_has_permissions')
            ->whereIn('permission_id', DB::table('permissions')->where('name', self::KEY)->select('id'))
            ->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Schema::table('sal_deposit_claims', function (Blueprint $table) {
            foreach (['lookalike_claim_id', 'depositor'] as $column) {
                if (Schema::hasColumn('sal_deposit_claims', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
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
