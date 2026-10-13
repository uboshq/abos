<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ অ্যাপের ভিতরে ওয়েবের পর্দা — একবারের চাবি (মালিক, ১৩ অক্টোবর ২০২৬: *"web e na dhuke jar jar kaj 100% app ei korte pare"*)।
 *
 * ── `web_handoffs` ──────────────────────────────────────────────────────
 * ফোন একটা চাবি চায় ([[WebHandoffApiController]]), আর অ্যাপের ভিতরের ওয়েব-জানালা সেটা দিয়ে একবার ঢোকে
 * ([[WebHandoffController]])। ⛔ চাবির লেখা কোথাও থাকে না — কেবল তার sha256; ⓘ ৬০ সেকেন্ডে মরে, একবার খরচ হলে আর চলে না,
 * আর মানুষ + হ্যান্ডসেট + কোম্পানি + শাখায় বাঁধা।
 *
 * ── `sync_devices.second_step_at` ───────────────────────────────────────
 * এই হ্যান্ডসেট শেষ কবে দুই ধাপের কোড পেরিয়েছে — লগইনে, বা চাবি চাওয়ার সময়। ⛔ দুই ধাপ চালু থাকলে এটা ছাড়া (বা দুই ধাপ
 * চালুর আগের হলে) ওয়েবের চাবি মেলে না — ওয়েবের লগইন যে কোড চায়, অ্যাপের ভিতরের ওয়েবও সেটা না পেরিয়ে খোলে না।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('web_handoffs', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->char('token_hash', 64)->unique('web_handoffs_token_once');
            $table->char('device_hash', 64);
            $table->string('target', 512);
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->string('consumed_ip', 45)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['company_id', 'user_id', 'created_at'], 'web_handoffs_user_time');
        });

        Schema::table('sync_devices', function (Blueprint $table) {
            $table->timestamp('second_step_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sync_devices', function (Blueprint $table) {
            $table->dropColumn('second_step_at');
        });

        Schema::dropIfExists('web_handoffs');
    }
};
