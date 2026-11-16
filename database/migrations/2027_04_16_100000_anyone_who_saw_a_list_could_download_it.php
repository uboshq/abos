<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\PermissionRegistrar;

/*
 * ⭐ তালিকা নামানোর নিজের চাবি — `system_admin.list.export` (পরিকল্পনা §৩.১; মালিক, ১৪ নভেম্বর ২০২৬: "ওকে, এইভাবে এই কাজটা ধরে ফেলো")।
 *
 * ── কী ছিল ─────────────────────────────────────────────────────────────────
 * যে তালিকা দেখতে পারত, সে পুরো তালিকা Excel/CSV-তে নামাতেও পারত — আলাদা অনুমতি ছিল না।
 *
 * ── এখন ─────────────────────────────────────────────────────────────────────
 * নামানো একটা চাবি ([[ExportListing]] ৪০৩ দেয়, "নামান" মেনু লুকায়)। ⓘ আজ যাঁরা নামাতে পারেন তাঁদের কারও কাজ যেন না থামে — তাই
 * প্রতিটা ভূমিকায় চাবিটা বসে; মালিক চাইলে কোনো ভূমিকা থেকে তুলে দেবেন (D365-এর "Open in Excel"-এর মতো, ভূমিকা ধরে)।
 */
return new class extends Migration
{
    public function up(): void
    {
        $name = 'system_admin.list.export';
        $permission = DB::table('permissions')->where('name', $name)->where('guard_name', 'web')->value('id');

        if ($permission === null) {
            $permission = DB::table('permissions')->insertGetId([
                'name' => $name, 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $given = 0;

        foreach (DB::table('roles')->where('guard_name', 'web')->pluck('id') as $roleId) {
            if (DB::table('role_has_permissions')->where('role_id', $roleId)->where('permission_id', $permission)->exists()) {
                continue;
            }

            DB::table('role_has_permissions')->insert(['permission_id' => $permission, 'role_id' => $roleId]);
            $given++;
        }

        Log::info(sprintf('List download key: given to %d roles', $given));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // ⓘ ফেরানো নয় — চাবি তুলে নিলে সবার নামানো একসাথে বন্ধ হত
    }
};
