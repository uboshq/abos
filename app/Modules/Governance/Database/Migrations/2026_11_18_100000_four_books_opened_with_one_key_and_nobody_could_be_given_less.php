<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * চারটা খাতা এক চাবিতে খুলত, তাই কাউকে কম দেওয়ার উপায় ছিল না।
 *
 * ── ⛔ নিরীক্ষার ফলাফল ৩.৪, ১২ সেপ্টেম্বর ২০২৬ ─────────────────────────
 * *"Governance মডিউলে মাত্র ২টি অনুমতি — অডিট ট্রেইল, রপ্তানি লগ, লগইন
 * ইতিহাস ও ত্রুটি লগ একই চাবিতে খোলে। চারটি আলাদা চাবিতে ভাগ করুন।"*
 *
 * ⓘ চাবি দুইটা নতুন বসেছে: `governance.export.view` ও `governance.login.view`।
 *
 * ── ⚠️ কেন এই মাইগ্রেশনটা **অবশ্যই** লাগে ─────────────────────────────
 * চাবি ভাগ করা মানে পর্দাটা নতুন চাবি চায়। ⛔ কিন্তু লাইভের ভূমিকাগুলোয়
 * কেবল পুরনো চাবিটা আছে — অর্থাৎ কোড লাইভে যাওয়ার মুহূর্তে যাঁরা আজ
 * রপ্তানির খাতা ও লগইন ইতিহাস দেখেন, তাঁরা **সেটা হারাতেন**।
 *
 * ⓘ আর হারানোটা নীরব হত না, হত বিভ্রান্তিকর: মেনু থেকে সারিটা উধাও, আর
 * কেউ বলতে পারত না কেন। ⚠️ "নিরাপত্তা কড়া করা" নাম দিয়ে মানুষের কাজ
 * কেড়ে নেওয়াটাই সবচেয়ে সহজ ভুল।
 *
 * ── ⭐ তাই নিয়মটা এক লাইনে ────────────────────────────────────────────
 * **আজ যে ভূমিকা অডিট দেখে, সে আজও রপ্তানি ও লগইন দেখবে।** ভাগটা কেবল
 * আগামীকালের জন্য — যাতে নতুন কাউকে **কম** দেওয়া যায়।
 */
return new class extends Migration
{
    /** @var list<string> */
    private const NEW_KEYS = [
        'governance.export.view',
        'governance.login.view',
    ];

    private const OLD_KEY = 'governance.audit.view';

    public function up(): void
    {
        $guard = 'web';

        foreach (self::NEW_KEYS as $key) {
            $id = DB::table('permissions')
                ->where('name', $key)
                ->where('guard_name', $guard)
                ->value('id');

            if ($id === null) {
                $id = DB::table('permissions')->insertGetId([
                    'name' => $key,
                    'guard_name' => $guard,
                    'public_id' => (string) Str::uuid(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            /*
             * ⓘ যে ভূমিকাগুলোর কাছে পুরনো চাবিটা আছে, ঠিক তাদেরই নতুনটা।
             *
             * ⚠️ সবাইকে দিয়ে দেওয়া হয় না — তাহলে ভাগ করাটাই অর্থহীন হত।
             */
            $roles = DB::table('role_has_permissions')
                ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
                ->where('permissions.name', self::OLD_KEY)
                ->pluck('role_has_permissions.role_id');

            foreach ($roles as $roleId) {
                $already = DB::table('role_has_permissions')
                    ->where('permission_id', $id)
                    ->where('role_id', $roleId)
                    ->exists();

                if (! $already) {
                    DB::table('role_has_permissions')->insert([
                        'permission_id' => $id,
                        'role_id' => $roleId,
                    ]);
                }
            }
        }
    }

    /**
     * ⛔ ফেরত যাওয়া মানে চাবি দুইটা তুলে নেওয়া।
     *
     * ⓘ পুরনো কোড আবার `governance.audit.view` চাইবে, আর সেটা কারো কাছ
     * থেকে কাড়া হয়নি — তাই ফেরত গেলে কেউ কিছু হারায় না।
     */
    public function down(): void
    {
        $ids = DB::table('permissions')
            ->whereIn('name', self::NEW_KEYS)
            ->pluck('id');

        DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('model_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
