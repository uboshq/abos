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
 * ⭐ চেক ছাপা — মালিকের অনুমোদিত নমুনা (W63BXTDuqM65tDaKRDnvwR, ১০ নভেম্বর ২০২৬: "Ok final cheek daw tarpor koro")।
 *
 * - `acc_cheque_layouts` — ব্যাংক ধরে চেকের পাতার মাপ: পাতার চওড়া-উঁচু আর প্রতিটা ঘর কোথায় (mm), পুরো ছাপা সরানোর ডান-নিচ।
 * - `acc_cheque_books` — চেক বই: কোন ব্যাংক হিসাবের (খাতার খাত), নম্বরের সীমা, পরের নম্বর, কোন মাপ। ⓘ `off_books` = মালিকের
 *   নিজের বই — খাতার কোনো খাতে বাঁধা নয় ("hisab chara sudu print", মালিক, ১০ নভেম্বর ২০২৬)।
 * - `acc_cheque_prints` — ছাপা চেকের খাতা: কোন বইয়ের কোন নম্বর, কাকে, কত, কবে, কে ছাপল, কতবার; নষ্ট পাতা। টাকার হিসাব নয় —
 *   জালিয়াতির পাহারা। একই বইয়ের একই নম্বর একবারই (নষ্ট হলেও সারিটা থাকে)।
 *
 * চাবি: `accounts.cheque.print` (ভাউচারের চেক ছাপা — যাঁরা চেক সামলান, `accounts.cheque.manage`, তাঁদের ভূমিকায় দেওয়া হয়),
 * `accounts.cheque.print_free` (হিসাব ছাড়া — শুরুতে কেবল মালিক/সুপার অ্যাডমিন, যাঁরা সব চাবি এমনিই পান),
 * `accounts.cheque.layout` (মাপ আর বই বসানো — একই, শুরুতে কেবল মালিক)।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acc_cheque_layouts', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('name', 120);
            $table->decimal('width_mm', 6, 1);
            $table->decimal('height_mm', 6, 1);
            // ⓘ ঘরগুলো: date, payee, words, figures, crossing — প্রতিটায় x, y, w (mm), size (pt); তারিখে box (এক ঘরের চওড়া), crossing-এ angle
            $table->json('fields');
            $table->decimal('offset_x', 5, 1)->default(0);
            $table->decimal('offset_y', 5, 1)->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'name']);
        });

        Schema::create('acc_cheque_books', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('bank_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->boolean('off_books')->default(false);
            $table->string('label', 160);
            $table->unsignedBigInteger('first_no');
            $table->unsignedBigInteger('last_no');
            $table->unsignedBigInteger('next_no');
            // ⓘ নম্বরের অঙ্ক কটা — 0045112-এর আগের শূন্য রাখতে
            $table->unsignedTinyInteger('digits')->default(7);
            $table->foreignId('layout_id')->constrained('acc_cheque_layouts');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'is_active']);
        });

        Schema::create('acc_cheque_prints', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('cheque_book_id')->constrained('acc_cheque_books');
            $table->unsignedBigInteger('cheque_no');
            $table->date('cheque_date')->nullable();
            $table->string('payee', 160)->nullable();
            $table->decimal('amount', 18, 4)->nullable();
            $table->boolean('crossed')->default(true);
            $table->boolean('off_books')->default(false);
            $table->foreignId('voucher_id')->nullable()->constrained('vouchers')->nullOnDelete();
            // ⓘ চেকের খাতার দেওয়া চেক — সরবরাহকারীর পরিশোধ যেটা বসায় ([[ChequePrinter::forCheque()]])
            $table->foreignId('cheque_id')->nullable()->constrained('acc_cheques')->nullOnDelete();
            $table->string('purpose', 200)->nullable();
            // ⓘ printed = ছাপা হয়েছে; spoiled = নষ্ট পাতা (নম্বরটা আর কখনো নয়)
            $table->string('status', 12)->default('printed');
            $table->unsignedSmallInteger('times')->default(1);
            $table->string('reason', 200)->nullable();
            $table->foreignId('printed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['cheque_book_id', 'cheque_no']);
            $table->index(['company_id', 'created_at']);
            $table->index('voucher_id');
            $table->index('cheque_id');
        });

        $this->keys();
    }

    public function down(): void
    {
        Schema::dropIfExists('acc_cheque_prints');
        Schema::dropIfExists('acc_cheque_books');
        Schema::dropIfExists('acc_cheque_layouts');

        DB::table('role_has_permissions')
            ->whereIn('permission_id', DB::table('permissions')->whereIn('name', ['accounts.cheque.print', 'accounts.cheque.print_free', 'accounts.cheque.layout'])->select('id'))
            ->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function keys(): void
    {
        $guard = 'web';
        $from = DB::table('permissions')->where('name', 'accounts.cheque.manage')->where('guard_name', $guard)->value('id');
        $print = $this->permission('accounts.cheque.print', $guard);
        $free = $this->permission('accounts.cheque.print_free', $guard);
        $layout = $this->permission('accounts.cheque.layout', $guard);
        $given = 0;

        /*
         * ⭐ সুপার অ্যাডমিন (মালিক) — তিনটা চাবিই। ⓘ ডিপ্লয় কেবল migrate চালায়, চাবি মেলানো (abos:sync-permissions) নয়; লাইভের
         * সুপার অ্যাডমিন ভূমিকায় সব চাবি থাকে, নতুনটা কেউ না দিলে নয় — স্থানীয় কপিতে "শুধু চেক ছাপা" মালিককেও ৪০৩ দিয়েছিল।
         */
        $supers = DB::table('roles')->where('name', 'super_admin')->where('guard_name', $guard)->pluck('id');

        foreach ($supers as $roleId) {
            foreach ([$print, $free, $layout] as $permissionId) {
                if (! DB::table('role_has_permissions')->where('role_id', $roleId)->where('permission_id', $permissionId)->exists()) {
                    DB::table('role_has_permissions')->insert(['permission_id' => $permissionId, 'role_id' => $roleId]);
                }
            }
        }

        Log::info(sprintf('Cheque print keys: %d super admin role(s) given print, print_free and layout', $supers->count()));

        if ($from !== null) {
            $roles = DB::table('role_has_permissions as rp')
                ->join('roles as r', 'r.id', '=', 'rp.role_id')
                ->leftJoin('companies as c', 'c.id', '=', 'r.company_id')
                ->where('rp.permission_id', $from)
                ->orderBy('r.company_id')->orderBy('r.id')
                ->get(['r.id', 'r.name', 'r.company_id', 'c.code']);

            foreach ($roles as $role) {
                if (DB::table('role_has_permissions')->where('role_id', $role->id)->where('permission_id', $print)->exists()) {
                    continue;
                }

                DB::table('role_has_permissions')->insert(['permission_id' => $print, 'role_id' => $role->id]);
                $given++;
                Log::info(sprintf('Cheque print key given: company=%s role=%s team_id=%s', (string) ($role->code ?? '?'), (string) $role->name, (string) $role->company_id));
            }
        }

        Log::info(sprintf('Cheque print key: %d role(s) given accounts.cheque.print (those that manage cheques)', $given));
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
