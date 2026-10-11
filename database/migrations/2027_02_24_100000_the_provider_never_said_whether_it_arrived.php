<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ প্রোভাইডারের ফেরত-খবর — পৌঁছানোর রসিদ, bounce, অভিযোগ (মালিকের স্পেক §৭ "Delivery Status, Bounce, Delivery Receipt",
 * §১১ `notification_provider_events`, §১৩ "Callback Signature"; বিজ্ঞপ্তি ব্যবস্থাপনা, ধাপ ২-এর অনুসরণ)।
 *
 * ── ⛔ কী ছিল ─────────────────────────────────────────────────────────
 * "পাঠানো হয়েছে" মানে কেবল প্রোভাইডার নিয়েছে — ঠিকানাটা ভুল ছিল কি না (bounce), বা ফোনে সত্যিই পৌঁছাল কি না, জানা যেত না।
 *
 * ── ⭐ এখন ───────────────────────────────────────────────────────────
 *   · `notification_provider_events` — প্রোভাইডারের প্রতিটা ফেরত-খবর, একবারই (`payload_hash` অনন্য)।
 *   · `notification_jobs.delivered_at` / `receipt` — প্রোভাইডার যা বলল (delivered · bounced · complained · failed)।
 * ⓘ ফেরত-খবর আসে স্বাক্ষর করা অনুরোধে — মাধ্যমের গোপন `webhook_secret` দিয়ে HMAC; স্বাক্ষর না মিললে কিছুই বসে না।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_provider_events', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('job_id')->nullable()->constrained('notification_jobs')->nullOnDelete();
            $table->string('channel', 16);
            $table->string('provider', 32)->nullable();
            /* delivered · bounced · complained · failed */
            $table->string('event', 16);
            $table->string('provider_ref', 191);
            /* ⛔ ছাঁটা লেখা — ঠিকানা বা টোকেন নয় */
            $table->string('reason', 255)->nullable();
            /* একই ফেরত-খবর দুইবার এলে একবারই */
            $table->char('payload_hash', 64);
            $table->timestamp('occurred_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['company_id', 'payload_hash'], 'notify_provider_once');
            $table->index(['company_id', 'channel', 'event'], 'notify_provider_kind');
        });

        Schema::table('notification_jobs', function (Blueprint $table) {
            $table->timestamp('delivered_at')->nullable()->after('sent_at');
            $table->string('receipt', 16)->nullable()->after('delivered_at');
        });
    }

    public function down(): void
    {
        Schema::table('notification_jobs', function (Blueprint $table) {
            $table->dropColumn(['delivered_at', 'receipt']);
        });

        Schema::dropIfExists('notification_provider_events');
    }
};
