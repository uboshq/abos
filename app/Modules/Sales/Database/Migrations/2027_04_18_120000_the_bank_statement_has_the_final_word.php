<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * ⭐ জমার খবর, ধাপ গ — শেষ কথা ব্যাংক স্টেটমেন্ট (মালিক, ১৬ নভেম্বর ২০২৬: "ok suro koro")।
 *
 *   · `statement_line_id` — কোন ব্যাংক-সারির সাথে মিলল ([[\App\Modules\Accounts\Models\BankStatementLine]], মিলকরণের একই সারি,
 *     দ্বিতীয় টেবিল নয়)। ⛔ unique: একটা ব্যাংক-সারি একটাই খবরে, আর একটা খবর একটাই সারিতে।
 *   · `matched_by` / `matched_at` — কে মেলালেন; `unmatch_reason` — শেষবার মিল খোলার কারণ (পুরো ইতিহাস নিরীক্ষায়)।
 *   · চাবি `sales.claim.match` — যে ভূমিকার `sales.claim.decide` আছে, আর হিসাবরক্ষক ও ম্যানেজার নামের ভূমিকা পায়; ভূমিকার ছাঁচ
 *     চালু কোম্পানির পুরনো ভূমিকায় নতুন চাবি দেয় না, তাই এখানে (লগসহ)। আগে থেকে থাকলে কিছুই নয়।
 */
return new class extends Migration
{
    private const KEY = 'sales.claim.match';

    public function up(): void
    {
        Schema::table('sal_deposit_claims', function (Blueprint $table) {
            if (! Schema::hasColumn('sal_deposit_claims', 'statement_line_id')) {
                $table->foreignId('statement_line_id')->nullable()->after('collection_id')
                    ->constrained('acc_bank_statement_lines')->nullOnDelete();
                $table->unique('statement_line_id', 'sal_claim_stmt_line_once');
            }
            if (! Schema::hasColumn('sal_deposit_claims', 'matched_by')) {
                $table->foreignId('matched_by')->nullable()->after('statement_line_id')->constrained('users')->nullOnDelete();
                $table->timestamp('matched_at')->nullable()->after('matched_by');
                $table->string('unmatch_reason', 500)->nullable()->after('matched_at');
            }
        });

        $guard = 'web';
        $to = $this->permission(self::KEY, $guard);
        $from = DB::table('permissions')->where('name', 'sales.claim.decide')->where('guard_name', $guard)->value('id');

        $roles = DB::table('roles')->whereIn('name', ['Accountant', 'Manager'])->pluck('id');
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

        Log::info('Deposit report: roles given sales.claim.match', ['roles' => $given]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        DB::table('role_has_permissions')
            ->whereIn('permission_id', DB::table('permissions')->where('name', self::KEY)->select('id'))
            ->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Schema::table('sal_deposit_claims', function (Blueprint $table) {
            if (Schema::hasColumn('sal_deposit_claims', 'statement_line_id')) {
                // ⓘ ঘর মুছলে তার unique সূচিও যায়; আগে সূচি মুছলে বিদেশি চাবি সূচিহীন হয়ে MySQL থামত
                $table->dropConstrainedForeignId('statement_line_id');
            }
            if (Schema::hasColumn('sal_deposit_claims', 'matched_by')) {
                $table->dropConstrainedForeignId('matched_by');
                $table->dropColumn(['matched_at', 'unmatch_reason']);
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
