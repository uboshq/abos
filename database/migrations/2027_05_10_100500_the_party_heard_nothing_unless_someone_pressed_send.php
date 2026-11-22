<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * ⭐ নিজে থেকে পাঠানো — বাকির তাগাদা আর টাকা পাওয়ার খবর (মালিকের "ok", ২৩ নভেম্বর ২০২৬; নমুনা HUXqpfhxng8823eEFrvH54, ট্যাব ঙ)।
 *
 * ── ⚠️ আগে কী ছিল ────────────────────────────────────────────────────
 * পার্টি কোনো খবর পেতেন কেবল তখন, যখন কেউ কাগজের পাতায় "পাঠান" চাপতেন — বাকি পড়ে থাকলে কেউ মনে করাত না, টাকা দিলে কেউ
 * "পেয়েছি" বলত না, যদি না হাতে বলা হত।
 *
 * ── ⭐ এই migration কী করে ───────────────────────────────────────────
 * কেবল চাবি: `notification.party_auto` — নিয়ম বদলানো আর "বাদ রাখা পার্টি"। ⓘ ডিপ্লয় অনুমতি মেলায় না, তাই মালিক (super_admin)
 * চাবিটা এখানেই পান — আর কোনো ভূমিকা নয় (পার্টির কাছে নিজে থেকে কী যায়, সেটা মালিকের সিদ্ধান্ত)। ⓘ নিয়মগুলো সেটিং, টেবিল নয়;
 * "বাদ রাখা" টেবিল ভিত্তির migration-এ (2027_05_10_100000)। দুই নিয়মই শুরুতে বন্ধ — চাবি পেলেও কিছু নিজে থেকে যায় না।
 */
return new class extends Migration
{
    private const KEY = 'notification.party_auto';

    public function up(): void
    {
        $guard = 'web';
        $id = DB::table('permissions')->where('name', self::KEY)->where('guard_name', $guard)->value('id')
            ?? DB::table('permissions')->insertGetId([
                'name' => self::KEY, 'guard_name' => $guard, 'public_id' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        $supers = DB::table('roles')->where('name', 'super_admin')->where('guard_name', $guard)->pluck('id');

        foreach ($supers as $roleId) {
            if (! DB::table('role_has_permissions')->where('role_id', $roleId)->where('permission_id', $id)->exists()) {
                DB::table('role_has_permissions')->insert(['permission_id' => $id, 'role_id' => $roleId]);
            }
        }

        Log::info(sprintf('Party auto-send key: %d super admin role(s) given %s', $supers->count(), self::KEY));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        DB::table('role_has_permissions')
            ->whereIn('permission_id', DB::table('permissions')->where('name', self::KEY)->select('id'))
            ->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
