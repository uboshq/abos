<?php

declare(strict_types=1);

use App\Core\Services\DealerScope;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * ⛔ মাঠের রোলগুলো জানত না যে তারা কেবল নিজের ডিলার দেখে — ⛔১৬, ২ অক্টোবর ২০২৬।
 *
 * ⓘ রোলের ছাঁচ পুরনো রোল চওড়া করে না, ইচ্ছা করে ([[PermissionSyncer::applyRoleTemplates()]])। তাই
 * নতুন কোম্পানির মাঠের রোল চিহ্নটা ছাঁচ থেকে পায়, কিন্তু **চলতি** কোম্পানির রোল পেত না — আর
 * দেয়ালটা লাইভে কারো উপরেই খাটত না, অথচ সুইচ "চালু" দেখাত।
 *
 * ⭐ মালিকের উত্তর "ক" (৩ অক্টোবর ২০২৬): চলতি SR, TSM আর ASM রোল চিহ্নটা নিজে থেকে পাবে।
 * কাকে — নাম ধরে, [[DealerScope::FIELD_ROLES]]-এর তালিকায় (ছাঁচের মাঠের রোল আর মালিকের ডাকনাম),
 * বড়-ছোট হাতের অক্ষর না মেনে। ⛔ কখনো নয়: সুপার অ্যাডমিন, [[DealerScope::NEVER_MARKED]]-এর অফিসের
 * রোল, আর যে রোলের "সব ডিলার" চাবি আছে।
 *
 * ⓘ কারা পেল তা লগে লেখা থাকে (কোম্পানি · রোল) — চালুর দিন কেউ শূন্য দেখলে প্রথম প্রশ্ন সেটাই।
 * ⓘ কোম্পানির সুইচ ডিফল্ট বন্ধ (৪ অক্টোবর ২০২৬), তাই চিহ্ন বসলেও লাইভে কিছু বদলায় না — মালিক ডিলার
 * বেঁধে সুইচ চালু করলে তবেই দেয়াল ওঠে। বাঁধার পর্দার আগাম দেখায় বাঁধনহীনরা লাল।
 */
return new class extends Migration
{
    public function up(): void
    {
        $guard = 'web';

        $mark = $this->permission(DealerScope::OWN, $guard);
        $all = $this->permission(DealerScope::ALL, $guard);

        $field = array_map('mb_strtolower', DealerScope::FIELD_ROLES);
        $never = array_map('mb_strtolower', DealerScope::NEVER_MARKED);
        $given = [];

        foreach (DB::table('roles')->where('guard_name', $guard)->orderBy('id')->get(['id', 'name', 'company_id']) as $role) {
            $name = mb_strtolower(trim((string) $role->name));

            if (! in_array($name, $field, true) || in_array($name, $never, true)) {
                continue;
            }

            $seesAll = DB::table('role_has_permissions')->where('role_id', $role->id)->where('permission_id', $all)->exists();
            $marked = DB::table('role_has_permissions')->where('role_id', $role->id)->where('permission_id', $mark)->exists();

            if ($seesAll || $marked) {
                continue;
            }

            DB::table('role_has_permissions')->insert(['permission_id' => $mark, 'role_id' => $role->id]);
            $given[] = ['company_id' => $role->company_id, 'role' => $role->name];
        }

        Log::info('⛔১৬ dealer wall: the field roles were given '.DealerScope::OWN, ['roles' => $given]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $id = DB::table('permissions')->where('name', DealerScope::OWN)->value('id');

        if ($id !== null) {
            DB::table('role_has_permissions')->where('permission_id', $id)->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function permission(string $name, string $guard): int
    {
        $id = DB::table('permissions')->where('name', $name)->where('guard_name', $guard)->value('id');

        if ($id !== null) {
            return (int) $id;
        }

        return (int) DB::table('permissions')->insertGetId([
            'name' => $name,
            'guard_name' => $guard,
            'public_id' => (string) Str::uuid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
