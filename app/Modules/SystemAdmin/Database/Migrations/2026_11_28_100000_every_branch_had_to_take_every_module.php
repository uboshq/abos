<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * এক শাখায় যে মডিউল লাগে না, সেটাও তাকে নিতে হত।
 *
 * ── ⓘ কেন, ২৮ নভেম্বর ২০২৬ ───────────────────────────────────────────
 * মডিউল বন্ধ করার সুইচ আগে থেকেই আছে, কিন্তু সেটা **কোম্পানির** স্তরে
 * (`settings`-এ `<module>.enabled`, আর ঐ সারি কোম্পানি ধরে বসে)।
 * ⚠️ শাখার কোনো স্তর ছিল না — এক ডিপোতে LC লাগে আর অন্যটায় লাগে না,
 * এই পার্থক্যটা বলার উপায় ছিল না।
 *
 * ── ⛔ কেন `settings`-এ একটা `branch_id` ঘর নয় ───────────────────────
 * ঐ টেবিলের unique হলো `(company_id, key)`। শাখা ঢোকাতে হলে **unique
 * index বদলাতে হত**, আর সেটা লাইভ টেবিলে ঝুঁকির কাজ — সব সেটিংস ঐ
 * একটা টেবিলে বসে, কেবল মডিউলের সুইচ নয়।
 *
 * ⭐ তাই আলাদা টেবিল, আর কেবল একটা প্রশ্নের উত্তর দেয়: **এই শাখা এই
 * মডিউলটা পাবে কি না**।
 *
 * ── ⚠️ সারি না থাকা মানে "না", নয় — মানে "হ্যাঁ" ─────────────────────
 * ⛔ ডিফল্ট বন্ধ হলে এই মাইগ্রেশনটা চালানোমাত্র **প্রতিটা শাখার মেনু
 * খালি** হয়ে যেত। ⓘ তাই সারি কেবল তখনই বসে যখন কেউ ইচ্ছা করে একটা
 * মডিউল ঐ শাখায় বন্ধ করেন — অনুপস্থিতি মানে আগের আচরণ, হুবহু।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_modules', function (Blueprint $table) {
            $table->id();

            /*
             * ⓘ `company_id`-টাও রাখা হয়, যদিও শাখা থেকেই বের করা যেত।
             *
             * ⚠️ কারণটা ছাঁকার: কোম্পানির দেয়াল ([[BelongsToCompany]])
             * এই ঘরটা ধরেই কাজ করে। ⛔ না রাখলে প্রতিটা কোয়েরিকে
             * শাখার সাথে join করতে হত, আর একটা জায়গায় ভুলে গেলে এক
             * কোম্পানির সেটিংস অন্যের পর্দায় উঠত।
             */
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();

            // ⓘ `module.php`-র `code` — `accounts`, `inventory`, …
            $table->string('module', 64);

            $table->boolean('is_enabled')->default(true);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            /*
             * ⚠️ নামটা হাতে দেওয়া। ⓘ Laravel নিজে বানালে হত
             * `branch_modules_branch_id_module_unique` — ৪৬ অক্ষর, ঠিক
             * আছে; তবু নাম বসানো হলো যাতে ভবিষ্যতে কলাম যোগ হলে
             * ৬৪-র সীমা নিয়ে ভাবতে না হয় ([[LongIndexNames]])।
             */
            $table->unique(['branch_id', 'module'], 'branch_module_once');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_modules');
    }
};
