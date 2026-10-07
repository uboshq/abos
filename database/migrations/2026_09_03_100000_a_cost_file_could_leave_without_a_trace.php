<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ক্রয়মূল্যের ফাইল বাইরে গেলে কোনো চিহ্ন থাকত না।
 *
 * ── কেন এটা আজ জরুরি হলো ────────────────────────────────────────────
 * ABOS অনেক যত্নে ক্রয়মূল্য ও মুনাফা ঢেকেছে: কলাম ধরে, `columnsFor()`
 * দিয়ে, পর্দা-রপ্তানি-ছাপা তিন পথেই। কিন্তু যাঁর **অনুমতি আছে** তিনি
 * পুরো তালিকাটা এক ক্লিকে নামিয়ে নিতে পারেন, আর সেই ফাইলটা কোথায় গেল
 * তার কোনো রেকর্ড নেই।
 *
 * আজ পর্যন্ত ঝুঁকিটা তাত্ত্বিক ছিল, কারণ **রিপোর্টের রপ্তানি আসলে কাজই
 * করত না** — `?export=csv` চুপচাপ HTML পাতাটাই ফেরত দিত। সেটা সারানোর
 * পর ঝুঁকিটা বাস্তব হলো: এখন সত্যিই ফাইল নামে। তাই খাতাটা এখনই।
 *
 * ── কেন `audit_trails`-এ নয় ─────────────────────────────────────────
 * ওই খাতার প্রতিটা সারি একটা **রেকর্ডের বদল** — কোন সারির কোন ঘর, আগে
 * কী ছিল, এখন কী। রপ্তানি কোনো রেকর্ড বদলায় না; ওটা একটা পড়ার ঘটনা।
 * জোর করে ঢোকালে `auditable_type`/`auditable_id` ফাঁকা রাখতে হত, আর
 * তখন অডিটের পর্দা এমন সারি দেখাত যেগুলোয় ক্লিক করে কোথাও যাওয়া যায় না।
 *
 * ── কেন ছাঁকনিগুলোও রাখা হয় ─────────────────────────────────────────
 * "করিম রপ্তানি করেছেন" যথেষ্ট নয়। **কোন তারিখের, কোন শাখার, কোন
 * পণ্যের** — ওটাই বলে দেয় তিনি নিজের কাজের জন্য নামিয়েছেন নাকি গোটা
 * বছরের মুনাফার তালিকা নিয়ে গেছেন। ছাঁকনি ছাড়া খাতাটা কেবল বলত কিছু
 * একটা গেছে।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('export_log', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();

            /*
             * কে — আর সারিটা থেকে যায় ব্যবহারকারী মুছে ফেললেও।
             *
             * `nullOnDelete` ইচ্ছাকৃত: যিনি ফাইলটা নিয়ে গেছেন তাঁকে সরিয়ে
             * দিয়ে চিহ্নটাও মুছে ফেলা যাবে না। নামটা তাই আলাদা করেও
             * জমা থাকে।
             */
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('user_name', 191)->nullable();

            /*
             * কোন পর্দা — রুটের নাম।
             *
             * `sales.report.show` নয়, বরং যেটা মানুষ চিনবে সেটাই দরকার;
             * কিন্তু রুটের নামটাই একমাত্র জিনিস যা প্রতিটা পর্দায় আছে
             * আর কখনো ফাঁকা নয়। পর্দায় দেখানোর সময় শিরোনামটা এর সাথে
             * বসানো হয়।
             */
            $table->string('route', 191);
            $table->string('title', 191)->nullable();

            /*
             * কোন ছাঁকনিতে — যেমন `{"from":"2026-01-01","to":"2026-12-31"}`।
             *
             * JSON, কারণ প্রতিটা পর্দার ছাঁকনি আলাদা। কলাম বানালে নতুন
             * একটা ছাঁকনি এলে মাইগ্রেশন লাগত, আর ততদিন ওই ছাঁকনিটা
             * খাতায় বসত না — অর্থাৎ ঠিক যে তথ্যটা দরকার সেটাই বাদ যেত।
             */
            $table->json('filters')->nullable();

            /*
             * কয়টা সারি গেছে।
             *
             * দশ সারির একটা ফাইল আর দশ হাজার সারির ফাইল — দুইটার মানে
             * সম্পূর্ণ আলাদা, অথচ খাতায় দুইটাই "একটা রপ্তানি"।
             */
            $table->unsignedInteger('row_count')->default(0);

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();

            $table->uuid('public_id')->unique();

            /*
             * কেবল `created_at` — একটা রপ্তানি সম্পাদনা হয় না।
             *
             * `updated_at` রাখলে সেটা চিরকাল ফাঁকা বসে থাকত, আর কেউ
             * ভাবতে পারত সারিটা বদলানো যায়। `audit_trails`-এও একই নিয়ম।
             */
            $table->timestamp('created_at')->useCurrent();

            $table->index(['company_id', 'created_at']);
            $table->index(['company_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('export_log');
    }
};
