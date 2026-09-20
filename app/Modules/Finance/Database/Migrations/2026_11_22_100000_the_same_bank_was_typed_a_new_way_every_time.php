<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * আর্থিক প্রতিষ্ঠান — ব্যাংক, আর্থিক প্রতিষ্ঠান/লিজিং, বীমা, মোবাইল ব্যাংকিং।
 *
 * ── ⛔ কী ছিল, ২০ সেপ্টেম্বর ২০২৬ ───────────────────────────────────────
 * মালিক জিজ্ঞেস করলেন: যে ব্যাংক বা ফিন্যান্স কোম্পানি ঋণ দেয়, FDR-DPS
 * যেখানে, আর বীমা কোম্পানি — এদের নামের তালিকা কোথায়? ⓘ ছিল না।
 * ব্যাংকের সুবিধায় (`fin_bank_facilities.bank`) আর আমানতে
 * (`fin_deposits.institution`) নামটা প্রতিবার হাতে লেখা হত — ফলে একই
 * ব্যাংক "IBBL", "Islami Bank", "ইসলামী ব্যাংক" তিন রূপে বসত, আর "এই
 * ব্যাংকের কাছে আমাদের মোট কত" প্রশ্নের উত্তর মেলানো যেত না।
 *
 * ⭐ মালিকের সিদ্ধান্ত: তালিকাটা কেবল অর্থ মডিউলে — *"eta sudu ekhanei
 * bebohar hobe"*।
 *
 * ── `name_key` কেন ─────────────────────────────────────────────────────
 * একই নামে দুইটা প্রতিষ্ঠান হয় না। ⓘ তুলনাটা হয় ছোট হাতের অক্ষরে, ফাঁকা
 * মিলিয়ে, আর বিরামচিহ্ন ছাড়া — "Islami Bank Ltd." আর "islami bank ltd"
 * একই। ⚠️ তবে বানান আলাদা হলে ("IBBL" বনাম "Islami Bank") জোর করে এক
 * করা হয় না — ওটা মানুষের সিদ্ধান্ত।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_institutions', function (Blueprint $table) {
            $table->id();
            $table->publicId();

            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            // bank · nbfi · insurance · mfs
            $table->string('kind', 16);

            $table->string('name_en', 160);
            $table->string('name_bn', 160)->nullable();
            $table->string('name_key', 160);
            $table->string('short_code', 32)->nullable();

            $table->string('branch_name', 120)->nullable();
            $table->string('contact_person', 120)->nullable();
            $table->string('phone', 40)->nullable();

            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'name_key']);
            $table->index(['company_id', 'kind', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_institutions');
    }
};
