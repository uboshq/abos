<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * নোটিশের কোনো নম্বর ছিল না, অবস্থা ছিল না, ওজনও ছিল না।
 *
 * ── ⭐ মালিকের স্পেক, ২৩ সেপ্টেম্বর ২০২৬ ─────────────────────────────
 * *"A secure, governed, multi-company, multi-branch, workflow-driven
 * enterprise communication platform — not a simple notice board."*
 *
 * ── ⓘ আগে যা ছিল ────────────────────────────────────────────────────
 * `notices` টেবিলে ছিল শিরোনাম, লেখা, দুইটা তারিখ আর দুইটা সুইচ
 * (`is_active`, `in_ticker`)। ⚠️ ওটুকু একটা **বোর্ড**-এর জন্য যথেষ্ট,
 * কিন্তু একটা **কাগজ**-এর জন্য নয়।
 *
 * ⛔ যা বলা যেত না: নোটিশটা কে অনুমোদন করেছেন, কবে প্রকাশ হলো, কতটা
 * জরুরি, কোন নোটিশ কোনটাকে বাতিল করেছে, আর *"NOTICE-2026-0058"* বলতে
 * কোনটা বোঝায়।
 *
 * ── ⚠️ কেন `is_active` রয়ে গেল ──────────────────────────────────────
 * ⓘ `status` আসার পর ওটা অপ্রয়োজনীয় মনে হয়, আর এক টানে মুছে দেওয়ার
 * লোভ হয়। ⛔ কিন্তু লাইভে সারি আছে, আর পুরনো কোড এখনো ওটা পড়ে
 * ([[NoticeBoard]])। ⚠️ একই মাইগ্রেশনে কলাম যোগ **আর** পুরনো পথ ভাঙা
 * করলে ভাঙাটা ধরা পড়ত ডিপ্লয়ের পরে।
 *
 * ⓘ তাই এখানে কেবল যোগ, আর পুরনো সারিগুলোকে একটা সঙ্গত অবস্থা দেওয়া।
 * ওটা তোলার কাজ চেকলিস্টের ধাপ ৩-এ।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notice_categories', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('code', 32);
            $table->string('name_en', 120);
            $table->string('name_bn', 120)->nullable();

            /*
             * ⓘ ক্যাটাগরির নিজের ডিফল্ট — লেখার সময় বসে যায়, বদলানো যায়।
             *
             * ⚠️ *"নিরাপত্তা সতর্কতা"* লিখতে গিয়ে প্রতিবার হাতে
             * `CRITICAL` বাছতে হলে কোনো একদিন কেউ ভুলতেন, আর ঐ নোটিশটা
             * বারেই যেত না।
             */
            $table->string('default_priority', 16)->nullable();

            /*
             * ⭐ এই ক্যাটাগরির নোটিশে সই লাগে কি না।
             *
             * ⓘ অনুমোদনের আসল প্রবাহ [[ApprovalFlow]]-এ; এটা কেবল বলে
             * *"এখানে সই লাগবে"*। ⚠️ দুইটা আলাদা রাখা হয়েছে যাতে
             * প্রবাহের নিয়ম বদলালে ক্যাটাগরি ছুঁতে না হয়।
             */
            $table->boolean('needs_approval')->default(false);

            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'code'], 'ntc_cat_code');
        });

        Schema::table('notices', function (Blueprint $table) {
            /*
             * ⚠️ নম্বরটা nullable, আর সেটা লাইভের কারণে।
             *
             * ⛔ `NOT NULL` দিলে মাইগ্রেশনটাই থামত — পুরনো সারিগুলোর
             * কোনো নম্বর নেই, আর মাইগ্রেশনের ভিতরে নম্বর সিরিজ ডাকা
             * মানে খাতার সিরিজ এগিয়ে দেওয়া। ⓘ পুরনো নোটিশের নম্বর
             * না থাকাই সৎ: ওগুলো নম্বরের আগের যুগের।
             */
            $table->string('document_no', 32)->nullable()->after('company_id');

            /* ⓘ ডিফল্ট `published`, কারণ টেবিলে যা আছে সব ইতিমধ্যে প্রকাশিত */
            $table->string('status', 24)->default('published')->after('document_no');

            $table->foreignId('notice_category_id')->nullable()->after('status')
                ->constrained('notice_categories', indexName: 'ntc_cat_fk')->nullOnDelete();

            $table->string('type', 40)->nullable()->after('notice_category_id');
            $table->string('priority', 16)->default('normal')->after('type');

            /*
             * ⓘ সারমর্ম — বারে ও তালিকায় এটাই যায়, গোটা লেখাটা নয়।
             *
             * ⚠️ `body` থেকে কেটে নেওয়া যেত, কিন্তু কাটা লেখা প্রায়ই
             * মাঝপথে থামে আর অর্থ উল্টে দেয়: *"বাকি দেওয়া যাবে"* …
             * *"না"*। ⛔ বারের জন্য লেখাটা মানুষেরই লেখা উচিত।
             */
            $table->string('summary', 300)->nullable()->after('body');

            /*
             * ⚠️ `published_at` আর `starts_on` এক নয়।
             *
             * ⓘ `starts_on` হলো *"কবে থেকে দেখাবে"* — মানুষের সিদ্ধান্ত।
             * `published_at` হলো *"কখন সত্যিই প্রকাশ হলো"* — ঘটনার
             * স্মৃতি। ⛔ একটা কলামে দুইটা রাখলে সময় ধরে প্রকাশের পর
             * বলা যেত না কাজটা আদৌ চলেছে কি না।
             */
            $table->timestamp('published_at')->nullable()->after('ends_on');
            $table->timestamp('expires_at')->nullable()->after('published_at');

            $table->timestamp('recalled_at')->nullable()->after('expires_at');
            $table->foreignId('recalled_by')->nullable()->after('recalled_at')
                ->constrained('users', indexName: 'ntc_recall_fk')->nullOnDelete();
            $table->string('recall_reason', 300)->nullable()->after('recalled_by');

            /*
             * ⭐ কোন নোটিশ একে বাতিল করেছে — মোছার বদলে।
             *
             * ⓘ স্পেকের ধারা ২৬: ভুল নোটিশ মোছা হয় না, নতুনটা দিয়ে
             * ঢাকা হয়। ⚠️ সুতোটা থাকলে ছয় মাস পরেও বলা যায় ভুলটা কী
             * ছিল আর কে শুধরেছেন।
             */
            $table->foreignId('superseded_by')->nullable()->after('recall_reason')
                ->constrained('notices', indexName: 'ntc_supersede_fk')->nullOnDelete();

            /* ⓘ পড়া আর মেনে নেওয়া এক নয় — ধাপ ৪-এ ব্যবহার হবে */
            $table->boolean('read_required')->default(false)->after('in_ticker');
            $table->boolean('ack_required')->default(false)->after('read_required');
            $table->timestamp('ack_deadline')->nullable()->after('ack_required');

            /*
             * ⚠️ সূচকের নাম হাতে দেওয়া — ৬৪ অক্ষরের সীমা।
             *
             * ⓘ এই খাতায় লম্বা নাম আগে `migrate:fresh` ভেঙেছে, তাই
             * ছোট নাম দেওয়াটাই অভ্যাস।
             */
            $table->unique(['company_id', 'document_no'], 'ntc_no');
            $table->index(['company_id', 'status', 'priority'], 'ntc_live');
        });
    }

    public function down(): void
    {
        Schema::table('notices', function (Blueprint $table) {
            $table->dropUnique('ntc_no');
            $table->dropIndex('ntc_live');
            $table->dropForeign('ntc_supersede_fk');
            $table->dropForeign('ntc_recall_fk');
            $table->dropForeign('ntc_cat_fk');

            $table->dropColumn([
                'document_no', 'status', 'notice_category_id', 'type', 'priority',
                'summary', 'published_at', 'expires_at',
                'recalled_at', 'recalled_by', 'recall_reason', 'superseded_by',
                'read_required', 'ack_required', 'ack_deadline',
            ]);
        });

        Schema::dropIfExists('notice_categories');
    }
};
