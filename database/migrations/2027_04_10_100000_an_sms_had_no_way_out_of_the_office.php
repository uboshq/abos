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
 * ⭐ নিজের সিমে SMS — অফিসের একটা Android ফোন ERP-র সারি থেকে SMS তুলে নিজের সিমে পাঠায়, তারপর "গেছে / যায়নি" জানায়।
 * (মালিকের "ok", নমুনা U1X687u5MkwVfEL7ci7MGv ধাপ ২, ১০ নভেম্বর ২০২৬)
 *
 * ── ⚠️ আগে কী ছিল ────────────────────────────────────────────────────
 * SMS-এর মাধ্যম ছিল, কিন্তু কোনো সরবরাহকারী বসানো নেই ([[SmsChannel::GATEWAYS]] খালি) — তাই কোনো SMS কখনো বেরোয়নি।
 *
 * ── ⭐ কেন সারি, সরাসরি পাঠানো নয় ──────────────────────────────────────
 * ফোনটা অফিসে, সার্ভার EBN-এ — সার্ভার ফোনে ঢুকতে পারে না, ফোন সার্ভারে আসে। ⓘ তাই SMS সারিতে বসে (`sms_outbox`), ফোন
 * কিছুক্ষণ পরপর এসে নিয়ে যায়, পাঠিয়ে খবর দেয়। ফোন বন্ধ থাকলে SMS অপেক্ষা করে; ফোন ফিরলে যায়।
 *
 * ── 🔒 ফোনের চাবি ────────────────────────────────────────────────────
 * প্রতিটা ফোনের নিজের লম্বা চাবি, একবারই দেখানো হয়; ডাটাবেজে কেবল তার sha256 ([[SmsGatewayPhone]])। ⓘ চাবি দিয়ে কেবল সেই
 * কোম্পানির সারি থেকে নেওয়া আর খবর দেওয়া যায় — আর কিছু নয়, কোনো মানুষের লগইন নয়।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_gateway_phones', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('name', 120);
            // ⓘ ফোনের সিমের নম্বর — কেবল দেখানোর জন্য (পাঠায় ফোনটাই)
            $table->string('sim_number', 20)->nullable();
            $table->char('token_hash', 64)->unique();
            // ⓘ দিনে কতটা — অপারেটর যেন সিম আটকে না দেয়
            $table->unsignedInteger('daily_limit')->default(200);
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->string('app_version', 20)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('sms_outbox', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            // ⓘ আন্তর্জাতিক রূপে, কেবল অঙ্ক (8801…)
            $table->string('phone', 20);
            $table->text('body');
            // ⓘ কোথা থেকে: নিজের মানুষের বিজ্ঞপ্তি (notification) বা পার্টিকে বার্তা (party)
            $table->string('source', 16);
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('status', 12)->default('waiting');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->foreignId('gateway_phone_id')->nullable()->constrained('sms_gateway_phones')->nullOnDelete();
            $table->timestamp('taken_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->string('error', 255)->nullable();
            // ⓘ ২ ঘণ্টার বেশি অপেক্ষার খবর মালিককে একবারই
            $table->timestamp('warned_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'status', 'created_at'], 'sms_outbox_company_status_idx');
            $table->index(['gateway_phone_id', 'sent_at'], 'sms_outbox_phone_sent_idx');
        });

        $this->key();
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_outbox');
        Schema::dropIfExists('sms_gateway_phones');

        DB::table('role_has_permissions')
            ->whereIn('permission_id', DB::table('permissions')->where('name', 'notification.sms_gateway')->select('id'))
            ->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** ⚠️ ডিপ্লয় অনুমতি মেলায় না, তাই মালিক (super_admin) চাবিটা এখানেই পান — আর কোনো ভূমিকা নয় */
    private function key(): void
    {
        $guard = 'web';
        $id = DB::table('permissions')->where('name', 'notification.sms_gateway')->where('guard_name', $guard)->value('id')
            ?? DB::table('permissions')->insertGetId([
                'name' => 'notification.sms_gateway', 'guard_name' => $guard, 'public_id' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        $supers = DB::table('roles')->where('name', 'super_admin')->where('guard_name', $guard)->pluck('id');

        foreach ($supers as $roleId) {
            if (! DB::table('role_has_permissions')->where('role_id', $roleId)->where('permission_id', $id)->exists()) {
                DB::table('role_has_permissions')->insert(['permission_id' => $id, 'role_id' => $roleId]);
            }
        }

        Log::info(sprintf('SMS gateway key: %d super admin role(s) given notification.sms_gateway', $supers->count()));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
