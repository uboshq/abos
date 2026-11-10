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
 * ⭐ কোপাইলট, ধাপ ০ — খাতা আর চাবি (মালিকের "ok", পূর্ণ পরিকল্পনা 9a6nHmbFk5NF7YB5NbzCC2, ১১ নভেম্বর ২০২৬)।
 *
 * ── ⚠️ আগে কী ছিল ────────────────────────────────────────────────────
 * উপরের খোঁজার ঘরে কে কী খুঁজল, কোথাও লেখা হত না। মালিক, ১০ নভেম্বর: *"ke ki search korlo … control penel e alada
 * vabe rakte hobe"*।
 *
 * ── ⭐ কী বসল ─────────────────────────────────────────────────────────
 * `assistant_events` — এক খাতায় সব: খোঁজা, প্রশ্ন ও উত্তর, নজর, প্রস্তাব, খসড়া, আটকানো প্রশ্ন, "এটা ঠিক আছে", 👍👎।
 *   ⛔ পর্দা থেকে বদলানো বা মোছা যায় না; প্রশ্নের লেখার মেয়াদ কোম্পানির সেটিং, "কে কখন কী" থেকে যায়।
 * `assistant_dismissals` — "এটা ঠিক আছে": কোন নজর, কে, কেন, কতদিন চুপ।
 * চাবি: assistant.use (কোপাইলট দেখা), assistant.audit (খাতা — কেবল মালিক), assistant.settings (সেটিং — কেবল মালিক)।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            // ⓘ খালি = কোপাইলট নিজে (নজর, প্রস্তাব)
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            // search | question | watch | suggestion | draft | blocked | dismiss | feedback | brief
            $table->string('kind', 16);
            $table->string('module', 32)->nullable();
            $table->string('page', 191)->nullable();
            $table->text('text')->nullable();
            $table->text('answer')->nullable();
            // ⓘ কোন দরজা কোন তথ্য পড়ল — নাম আর মানগুলো
            $table->json('tools')->nullable();
            $table->string('model', 60)->nullable();
            $table->unsignedInteger('ms')->nullable();
            $table->string('outcome', 60)->nullable();
            $table->tinyInteger('feedback')->nullable();
            $table->string('reason', 255)->nullable();
            $table->timestamps();
            $table->index(['company_id', 'created_at'], 'assistant_events_company_time_idx');
            $table->index(['company_id', 'kind', 'created_at'], 'assistant_events_company_kind_idx');
            $table->index(['company_id', 'user_id', 'created_at'], 'assistant_events_company_user_idx');
        });

        Schema::create('assistant_dismissals', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            // ⓘ কোন নজর — "accounts.backdated", "sales.discount" …
            $table->string('finding', 80);
            $table->string('reason', 255);
            $table->date('until');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'finding', 'until'], 'assistant_dismissals_finding_idx');
        });

        $this->keys();
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_dismissals');
        Schema::dropIfExists('assistant_events');

        DB::table('role_has_permissions')
            ->whereIn('permission_id', DB::table('permissions')->whereIn('name', ['assistant.use', 'assistant.audit', 'assistant.settings'])->select('id'))
            ->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** ⚠️ ডিপ্লয় অনুমতি মেলায় না, তাই মালিক (super_admin) তিনটা চাবি এখানেই পান; বাকিদের মালিক নিজে দেন */
    private function keys(): void
    {
        $guard = 'web';
        $supers = DB::table('roles')->where('name', 'super_admin')->where('guard_name', $guard)->pluck('id');

        foreach (['assistant.use', 'assistant.audit', 'assistant.settings'] as $name) {
            $id = DB::table('permissions')->where('name', $name)->where('guard_name', $guard)->value('id')
                ?? DB::table('permissions')->insertGetId([
                    'name' => $name, 'guard_name' => $guard, 'public_id' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now(),
                ]);

            foreach ($supers as $roleId) {
                if (! DB::table('role_has_permissions')->where('role_id', $roleId)->where('permission_id', $id)->exists()) {
                    DB::table('role_has_permissions')->insert(['permission_id' => $id, 'role_id' => $roleId]);
                }
            }
        }

        Log::info(sprintf('Copilot keys: %d super admin role(s) given assistant.use, assistant.audit, assistant.settings', $supers->count()));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
