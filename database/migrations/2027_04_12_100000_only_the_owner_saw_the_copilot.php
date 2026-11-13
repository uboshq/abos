<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\PermissionRegistrar;

/*
 * ⭐ মালিক, ১২ নভেম্বর ২০২৬: "আমার আইডি ছাড়া অন্য কারো আইডিতেই কোপাইলট দেখাচ্ছে না" — আর নতুন DSM (আবদুল্লাহ আল কবির, ADI) মজুদ
 *   দেখতে পাচ্ছিলেন না।
 *
 * ── কী বসে ────────────────────────────────────────────────────────────
 * • super_admin বাদে প্রতিটা রোলে `assistant.use` — কোপাইলট প্রত্যেককে কেবল নিজের চাবির তথ্য দেখায়, তাই নিরাপদ। ⛔ খাতা
 *   (`assistant.audit`) মালিকের।
 * • প্রতিটা কোম্পানির DSM-এ `inventory.stock.view` আর `inventory.report` — কেনা দাম (`inventory.cost.view`) নয়।
 * ⓘ live-এ একই দিন হাতে বসানো হয়েছিল (৬০ রোল); এখানে বাকি জায়গার জন্য — যা আছে তা আবার বসে না।
 */
return new class extends Migration
{
    public function up(): void
    {
        $id = fn (string $name) => DB::table('permissions')->where('name', $name)->where('guard_name', 'web')->value('id');
        $give = function (int $roleId, ?int $permissionId): int {
            if ($permissionId === null || DB::table('role_has_permissions')->where('role_id', $roleId)->where('permission_id', $permissionId)->exists()) {
                return 0;
            }
            DB::table('role_has_permissions')->insert(['permission_id' => $permissionId, 'role_id' => $roleId]);

            return 1;
        };

        $use = $id('assistant.use');
        $stock = $id('inventory.stock.view');
        $report = $id('inventory.report');
        $given = 0;

        foreach (DB::table('roles')->where('guard_name', 'web')->get(['id', 'name']) as $role) {
            if ($role->name !== 'super_admin') {
                $given += $give((int) $role->id, $use);
            }
            if ($role->name === 'DSM') {
                $given += $give((int) $role->id, $stock) + $give((int) $role->id, $report);
            }
        }

        Log::info(sprintf('Copilot for everyone and DSM stock: %d keys given', $given));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // ⓘ ফেরানো হয় না — কোন রোলে চাবিটা আগে থেকেই ছিল আর কোনটায় এখানে বসল, আলাদা করা যায় না; মালিক নিজে রোল থেকে তুলতে পারেন
    }
};
