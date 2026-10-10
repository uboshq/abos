<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ বিজ্ঞপ্তি ব্যবস্থাপনা, ধাপ ২ — পৌঁছানোর মাধ্যম (মালিকের স্পেক §৭, §১০, §১১, §১৪; ১০ অক্টোবর ২০২৬)।
 *
 * ── ⛔ কী ছিল ─────────────────────────────────────────────────────────
 * ঘণ্টার বাইরে খবর যেত একটাই পথে — চিঠি, সাথে সাথে, একবার। SMTP সেই মুহূর্তে বন্ধ থাকলে চিঠিটা হারাত (লগে একটা লাইন),
 * কেউ আবার চেষ্টা করত না, আর কোন চিঠি গেল কোনটা গেল না তার কোনো খাতা ছিল না।
 *
 * ── ⭐ এখন ───────────────────────────────────────────────────────────
 *   · `notification_channels` — কোম্পানির প্রতিটা মাধ্যম (ইমেইল, Web Push, মোবাইল পুশ, SMS): চালু কি না, প্রোভাইডার,
 *     প্রেরকের নাম, আর গোপন চাবি — ⛔ Laravel-এর encrypter দিয়ে এনক্রিপ্ট করা, কখনো খোলা লেখায় নয়। শেষ সংযোগ পরীক্ষা।
 *   · `notification_jobs` — একটা খবর একজনের কাছে একটা মাধ্যমে: অপেক্ষায় → চলছে → পৌঁছেছে / আবার চেষ্টা / ব্যর্থ-তালিকা
 *     (dead-letter) / বাতিল। কতবার চেষ্টা, পরের চেষ্টা কখন (backoff + jitter), প্রোভাইডারের রেফারেন্স, শেষ ভুল।
 *     একই খবর একজনের কাছে একই মাধ্যমে একবারই (অনন্য)।
 *   · `notification_delivery_attempts` — প্রতিটা চেষ্টার নিজের সারি: কখন, কোন প্রোভাইডার, ফল, রেফারেন্স, ভুল, কত সময়।
 *   · `notification_subscriptions` — ব্রাউজারের Web Push সাবস্ক্রিপশন; চাবিগুলো এনক্রিপ্ট করা, মেয়াদ আর প্রত্যাহার।
 *
 * ⓘ পৌঁছানো আর পড়া আলাদা (স্পেক §১০): পৌঁছানো এখানে, পড়া প্রাপকের সারিতে (`notifications.read_at`)।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_channels', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            /* email · web_push · mobile_push · sms */
            $table->string('channel', 16);
            $table->boolean('enabled')->default(false);
            $table->string('provider', 32)->nullable();
            $table->string('sender_id', 64)->nullable();

            /* ⛔ গোপন চাবি — encrypter দিয়ে এনক্রিপ্ট করা JSON; পর্দায় কখনো ফেরত দেখানো হয় না */
            $table->text('credentials')->nullable();

            $table->timestamp('last_checked_at')->nullable();
            $table->boolean('last_check_ok')->nullable();
            $table->string('last_error', 255)->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'channel'], 'notify_channel_once');
        });

        Schema::create('notification_jobs', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('notification_id')->constrained('notifications')->cascadeOnDelete();
            $table->foreignId('event_id')->nullable()->constrained('notification_events')->nullOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->string('channel', 16);

            /* queued · processing · sent · retrying · dead · cancelled */
            $table->string('status', 16);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('max_attempts')->default(5);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('claimed_at')->nullable();

            $table->string('provider', 32)->nullable();
            $table->string('provider_ref', 191)->nullable();

            /* transient · permanent — শেষ ভুলের ধরন; স্থায়ী ভুলে আর অন্ধ চেষ্টা নয় */
            $table->string('error_kind', 16)->nullable();
            $table->string('last_error', 255)->nullable();

            $table->timestamp('sent_at')->nullable();
            $table->timestamp('dead_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('resolution', 255)->nullable();
            $table->timestamps();

            $table->unique(['notification_id', 'channel'], 'notify_job_once');
            $table->index(['status', 'next_attempt_at'], 'notify_job_due');
            $table->index(['company_id', 'channel', 'status'], 'notify_job_state');
        });

        Schema::create('notification_delivery_attempts', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('job_id')->constrained('notification_jobs')->cascadeOnDelete();
            $table->unsignedSmallInteger('attempt');
            $table->string('channel', 16);
            $table->string('provider', 32)->nullable();

            /* sent · transient · permanent */
            $table->string('outcome', 16);
            $table->string('provider_ref', 191)->nullable();
            $table->string('error', 255)->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->timestamp('created_at')->nullable();

            $table->index(['company_id', 'created_at'], 'notify_attempt_when');
            $table->index(['company_id', 'channel', 'outcome'], 'notify_attempt_outcome');
        });

        Schema::create('notification_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->text('endpoint');
            /* একই ব্রাউজার দুইবার নয় — ঠিকানার sha256 */
            $table->char('endpoint_hash', 64);

            /* ⛔ ব্রাউজারের চাবি দুইটা — এনক্রিপ্ট করা */
            $table->text('keys');
            $table->string('user_agent', 191)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->unique('endpoint_hash', 'notify_sub_endpoint');
            $table->index(['user_id', 'revoked_at'], 'notify_sub_user');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_subscriptions');
        Schema::dropIfExists('notification_delivery_attempts');
        Schema::dropIfExists('notification_jobs');
        Schema::dropIfExists('notification_channels');
    }
};
