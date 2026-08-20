<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * কে কোন সারি দেখবেন — ভাগ চ (RLS)।
 *
 * ── আজ পর্যন্ত যা ছিল ───────────────────────────────────────────────
 * `BelongsToCompany` কোম্পানি আলাদা রাখে, আর সেটা কঠোরভাবেই রাখে।
 * কিন্তু কোম্পানির **ভেতরে** কোনো দেয়াল নেই: নেত্রকোনার বিক্রয়
 * প্রতিনিধি লগইন করলে ঢাকার প্রতিটা বিল, প্রতিটা আদায়, প্রতিটা
 * গ্রাহকের বকেয়া দেখতে পান।
 *
 * এক শাখার ডিপোতে ওটা সমস্যা নয়। কিন্তু ডিপো দুইটা হলেই সমস্যা, আর
 * ABC-র তিনটা কোম্পানি ইতিমধ্যেই আছে।
 *
 * ── অটল নিয়ম: সারি না থাকা মানে সব দেখা ─────────────────────────────
 * কোনো ব্যবহারকারীর জন্য একটাও সারি না থাকলে তিনি সব দেখেন।
 *
 * উল্টোটা করলে (সারি না থাকা মানে কিছুই দেখা যায় না) এই মাইগ্রেশনটা
 * চালানোর মুহূর্তে **প্রতিটা ব্যবহারকারী অন্ধ হয়ে যেতেন** — মালিকসহ,
 * আর তাঁকে ঢুকিয়ে ঠিক করারও উপায় থাকত না, কারণ তিনিও কিছু দেখতেন না।
 *
 * এটা একই নিয়ম যেটা `approval.self_limit`-এ ডিফল্ট শূন্য রাখে: নতুন
 * পাহারা চালু করলে আজকের আচরণ অবিকল থাকে, আর কড়া করার সিদ্ধান্তটা
 * মালিকের, কোডের নয়।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_data_scopes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            /*
             * কোন ধরনের সীমা — আপাতত শাখা ও গুদাম।
             *
             * টেরিটরি পরে যোগ হবে, আর তখন কেবল একটা নতুন মান বসবে;
             * টেবিলটা বদলাতে হবে না। ধরনটা string বলেই সেটা সম্ভব।
             */
            $table->string('scope_type', 24);
            $table->unsignedBigInteger('scope_id');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            /*
             * একই মানুষকে একই শাখা দুইবার দেওয়া যায় না।
             *
             * দিলে কোনো ক্ষতি হত না, কিন্তু পর্দায় সারিটা দুইবার দেখাত
             * আর কেউ ভাবত কিছু একটা ভুল হয়েছে।
             */
            $table->unique(['user_id', 'company_id', 'scope_type', 'scope_id'], 'user_scope_unique');
            $table->index(['company_id', 'user_id', 'scope_type'], 'user_scope_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_data_scopes');
    }
};
