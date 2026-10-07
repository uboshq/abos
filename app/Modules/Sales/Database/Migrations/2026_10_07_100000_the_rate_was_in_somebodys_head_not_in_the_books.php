<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * স্কিম আর কমিশনের নিয়ম — হারটা কারও মাথায় ছিল, খাতায় ছিল না।
 *
 * ── কী ছিল, আর কী ছিল না ─────────────────────────────────────────────
 * ABOS-এ কমিশনের **দাবি** আগে থেকেই আছে (`sal_commission_claims`):
 * কাকে কত দেওয়া হলো, কোম্পানির কাছে কত পাওনা। কিন্তু **কত দেওয়ার কথা**
 * ছিল, সেটা কোথাও লেখা নেই — প্রতিবার কেউ হাতে হার বসিয়েছে।
 *
 * ফল: একই ডিলারের একই মাসে দুইজন দুই হার বসিয়েছেন, আর কোনটা ঠিক তা
 * বলার জায়গা নেই। মাস-শেষে কোম্পানির কাছে দাবি করার সময় প্রশ্নটা ওঠে
 * "এই হারটা কে ঠিক করল" — আর উত্তরটা একজন মানুষের স্মৃতি।
 *
 * পরিবেশনের ব্যবসা এটার উপরেই চলে। স্কিম ছাড়া ডিপো কেবল মাল বেচে;
 * স্কিম দিয়েই কোম্পানি ঠিক করে কোন পণ্য এই ত্রৈমাসিকে ঠেলা হবে।
 *
 * ── দুইটা টেবিল কেন, একটা নয় ─────────────────────────────────────────
 * স্কিম বলে **কী পুরস্কার, কার জন্য, কোন দুই তারিখের মাঝে**। হার বলে
 * **কত** — আর একই স্কিম রুটিনমাফিক SR, ASM আর DSM-কে তিন হারে, চারটা
 * বিক্রয়-স্তরে দেয়। সেটা এক সারিতে ধরে না।
 *
 * ── ধাপগুলো সারি, কলাম নয় ────────────────────────────────────────────
 * পাঁচ লাখ পর্যন্ত ২%, তার উপরে ৩% — এটা **দুইটা সারি**, দুই কলামের
 * একটা সারি নয়। ধাপ বাড়ে: চালু হওয়ার পরের মাসেই তৃতীয় ধাপটা যোগ হয়।
 * কলামে রাখলে প্রতিটা নতুন ধাপে একটা মাইগ্রেশন লাগত, আর যে গড়নে
 * মাইগ্রেশন লাগে সেই গড়ন এড়িয়ে লোকে এক্সেলে হিসাব করে।
 *
 * ── উপরের ধাপটা খোলা রাখতেই হয় ───────────────────────────────────────
 * প্রতিটা সিঁড়ির শেষ সারিতে `slab_to` খালি থাকা চাই। নাহলে বছরের
 * সবচেয়ে ভালো মাসটা ছকের উপর দিয়ে বেরিয়ে যায় আর কিছুই পায় না — যা
 * ধাপে-ধাপে স্কিমের ঠিক উল্টো।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sal_schemes', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();

            $table->string('code', 40);
            $table->string('name', 160);

            /*
             * কীসের উপর গোনা হবে।
             *
             * `value`  — টাকার উপর
             * `volume` — পরিমাণের উপর (বস্তা, কার্টন)
             * `slab`   — যত বেশি বিক্রি, তত বেশি হার; ধাপগুলো নিয়মে
             */
            $table->string('basis', 12)->default('value');

            /*
             * স্কিমটা কীসের দিকে তাক করা।
             *
             * `all` ছাড়া বাকিগুলোয় `target_id` কাকে বোঝায় সেটা বদলায় —
             * পণ্য, শ্রেণি, ব্র্যান্ড, এলাকা, বা ডিলারের স্তর।
             *
             * ---- কেন একটা সাধারণ ঘর, পাঁচটা বিদেশি চাবি নয় ----
             * পাঁচটা nullable বিদেশি চাবি রাখলে প্রতিটা সারিতে চারটা
             * খালি ঘর থাকত, আর "একসাথে দুইটা ভরা" অবস্থাটা ডাটাবেজ
             * আটকাত না। এক ঘর + এক ধরন মানে অবস্থাটা একটাই।
             */
            $table->string('applies_to', 16)->default('all');
            $table->unsignedBigInteger('target_id')->nullable();

            /*
             * দুইটা তারিখই আবশ্যক।
             *
             * শেষ তারিখ খোলা রাখা স্কিম চিরকাল চলে — দুই সপ্তাহের
             * ঈদের অফার পরের বছরও টাকা দিতে থাকে, আর কেউ কোনোদিন
             * সিদ্ধান্ত নেয়নি যে ওটা চলবে।
             */
            $table->date('valid_from');
            $table->date('valid_to');

            /*
             * কেবল `active` টাকা দেয়।
             *
             * খসড়া অবস্থায় হার বসানো যায়, কিন্তু সেটা কোনো বিলে
             * লাগে না — নাহলে অর্ধেক লেখা স্কিম চালু হয়ে যেত।
             */
            $table->string('status', 16)->default('draft');

            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code']);

            // চালু স্কিম খোঁজা হয় তারিখ ধরে, প্রতিটা বিলে
            $table->index(['company_id', 'status', 'valid_from', 'valid_to']);
        });

        Schema::create('sal_commission_rules', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('scheme_id')->constrained('sal_schemes')->cascadeOnDelete();

            /*
             * কে পায়।
             *
             * ভূমিকার তালিকাটা হাতে লেখা নয় — কোম্পানি নিজের নাম
             * বসায় (SR, ASM, DSM, ডিলার, দালাল)। খোলা তালিকা, কারণ
             * প্রতিটা পরিবেশক নিজের মতো নাম দেয়।
             */
            $table->string('earner_role', 40);

            /*
             * হার, নয়তো থোক টাকা — দুইটার একটা।
             *
             * চুক্তি দুই রকম হয়, আর দুইটাই আসল। কেবল শতাংশ রাখলে
             * থোক অঙ্কটা প্রতিবার হাতে গুনে বসাতে হত, আর গোনার ভুল
             * সরাসরি টাকার ভুল।
             */
            $table->decimal('rate_percent', 9, 4)->nullable();
            $table->decimal('fixed_amount', 18, 4)->nullable();

            /* এই হারটা কোন ধাপে খাটে — স্কিমের ভিত্তিতে (টাকা বা পরিমাণ) */
            $table->decimal('slab_from', 18, 4)->default(0);

            /*
             * খালি মানে "আর তার উপরে যা কিছু"।
             *
             * প্রতিটা সিঁড়ির একটা সারিতে এটা খালি থাকতেই হবে, নাহলে
             * বছরের সেরা মাসটা ছকের উপর দিয়ে বেরিয়ে যায়।
             */
            $table->decimal('slab_to', 18, 4)->nullable();

            /*
             * কার পরে কে — ১ যিনি বেচলেন, ২ তাঁর উপরের জন।
             *
             * পরিশোধের রিপোর্টে শৃঙ্খলটা দেখাতে হয়; নাহলে DSM-এর
             * ভাগটা দেখতে দ্বিতীয় একজন SR-এর মতো লাগে।
             */
            $table->unsignedSmallInteger('level_order')->default(1);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'scheme_id', 'earner_role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sal_commission_rules');
        Schema::dropIfExists('sal_schemes');
    }
};
