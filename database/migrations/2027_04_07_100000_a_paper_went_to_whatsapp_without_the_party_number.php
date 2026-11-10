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
 * ⭐ পার্টিকে WhatsApp — পার্টির নম্বরে, বেছে নেওয়া লেখায়, বাংলা বা ইংরেজিতে; আর কে কাকে কী পাঠাল তার খাতা।
 * (মালিকের "ok", নমুনা U1X687u5MkwVfEL7ci7MGv, ১০ নভেম্বর ২০২৬: *"bn/en switch + templates … user je template select
 * korbe oi format e jabe"*)
 *
 * ── ⚠️ আগে কী ছিল ────────────────────────────────────────────────────
 * "পাঠান" চাপলে লিংকটা হত, আর WhatsApp বোতামটা খুলত `wa.me/?text=` — **নম্বর ছাড়া**। ⓘ অর্থাৎ প্রতিবার মানুষটাকে
 * WhatsApp-এ পার্টিকে খুঁজে বের করতে হত, লেখাটা সবসময় এক, ইংরেজিতে কিছু নেই, আর কাকে পাঠানো হলো তার কোনো খাতা নেই।
 *
 * ── ⭐ কেন আলাদা টেবিল, নোটিফিকেশনের টেমপ্লেটে নয় ──────────────────────
 * নোটিফিকেশনের টেমপ্লেট নিজের মানুষদের জন্য — তার চ্যানেলের তালিকা ধরে বিলি-ব্যবস্থা পাঠায় ([[DeliveryRouter]])।
 * ⛔ সেখানে "whatsapp" ঢোকালে নিয়মগুলো এমন চ্যানেলে পাঠাতে চাইত যার কোনো সরবরাহকারী নেই। পার্টির বার্তা আলাদা
 * জিনিস: মানুষটা নিজে পাঠান, ব্যবস্থা নয়।
 *
 * ⚠️ খাতায় "পাঠানো হয়েছে" লেখা হয় না, লেখা হয় "WhatsApp খোলা হয়েছে" — ব্রাউজার জানতে পারে না মানুষটা শেষে
 * পাঠান চেপেছেন কি না। ⛔ যা জানি না তা দাবি করা হয় না।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('party_message_templates', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('channel', 16);
            // ⓘ খালি = যেকোনো কাগজে চলে
            $table->string('document_type', 40)->nullable();
            $table->string('name', 120);
            $table->text('body_bn');
            $table->text('body_en');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(100);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'channel', 'document_type']);
        });

        Schema::create('party_messages', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('channel', 16);
            $table->string('document_type', 40);
            $table->unsignedBigInteger('document_id');
            $table->string('document_no', 60)->nullable();
            $table->string('party_type', 40)->nullable();
            $table->unsignedBigInteger('party_id')->nullable();
            $table->string('party_name', 191)->nullable();
            // ⓘ আন্তর্জাতিক রূপে, কেবল অঙ্ক (8801…)
            $table->string('phone', 20);
            $table->string('locale', 2);
            $table->foreignId('template_id')->nullable()->constrained('party_message_templates')->nullOnDelete();
            $table->text('body');
            $table->foreignId('share_id')->nullable()->constrained('doc_shares')->nullOnDelete();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'created_at']);
            $table->index(['company_id', 'document_type', 'document_id']);
        });

        /*
         * ⓘ শুরুর লেখা এখানে বসানো হয় না: যে কোম্পানি পরে খোলা হবে তার লেখা থাকত না। ⭐ প্রথম যেদিন কোম্পানিটা
         * বাক্সটা বা লেখার পাতা খোলে, সেদিন বসে ([[PartyMessageTemplate::ensureStarters()]])।
         */
        $this->keys();
    }

    public function down(): void
    {
        Schema::dropIfExists('party_messages');
        Schema::dropIfExists('party_message_templates');

        DB::table('role_has_permissions')
            ->whereIn('permission_id', DB::table('permissions')->whereIn('name', ['notification.party_templates', 'notification.party_messages'])->select('id'))
            ->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * ⚠️ ডিপ্লয় অনুমতি মেলায় না (কেবল migrate আর optimise), তাই মালিক (super_admin) দুইটা চাবি এখানেই পান।
     * ⓘ পাঠানোর জন্য আলাদা চাবি নেই — যে কাগজটা পাঠাতে পারেন, তিনিই WhatsApp-এ পাঠান ([[PaperShareController]])।
     */
    private function keys(): void
    {
        $guard = 'web';
        $templates = $this->permission('notification.party_templates', $guard);
        $history = $this->permission('notification.party_messages', $guard);
        $supers = DB::table('roles')->where('name', 'super_admin')->where('guard_name', $guard)->pluck('id');

        foreach ($supers as $roleId) {
            foreach ([$templates, $history] as $permissionId) {
                if (! DB::table('role_has_permissions')->where('role_id', $roleId)->where('permission_id', $permissionId)->exists()) {
                    DB::table('role_has_permissions')->insert(['permission_id' => $permissionId, 'role_id' => $roleId]);
                }
            }
        }

        Log::info(sprintf('Party message keys: %d super admin role(s) given party_templates and party_messages', $supers->count()));
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
