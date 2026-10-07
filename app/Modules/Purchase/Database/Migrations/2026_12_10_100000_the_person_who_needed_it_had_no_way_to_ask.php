<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * যার লাগত, তার চাওয়ার কোনো পথ ছিল না।
 *
 * ── ⭐ মালিকের স্পেক, ২৩ সেপ্টেম্বর ২০২৬ ──────────────────────────────
 * *"Purchase Requisition — Internal user/department purchase requirement
 * তৈরি করবে"*, আর অনুমোদিত চাহিদা থেকে RFQ বা PO বানানো যাবে।
 *
 * ── ⛔ আজ পর্যন্ত যা হত ──────────────────────────────────────────────
 * ক্রয়াদেশ সরাসরি লেখা হত। ⚠️ অর্থাৎ *"কে চেয়েছিল, আর কেন"* প্রশ্নটার
 * উত্তর কোথাও থাকত না — চাওয়াটা হত মুখে, হোয়াটসঅ্যাপে, বা একটা কাগজের
 * টুকরোয়। ⓘ ফল: মাস শেষে কেউ জিজ্ঞেস করলে *"এই দশটা মোটর কে চেয়েছিল"*,
 * উত্তরটা ছিল কারও স্মৃতি।
 *
 * ⛔ আর তার চেয়েও বড়: চাওয়া আর কেনা এক হয়ে থাকায় **বাজেটের আগে
 * থামার কোনো জায়গা ছিল না**। যে মুহূর্তে কাগজটা লেখা হত, সেটাই
 * প্রতিশ্রুতি।
 *
 * ── ⓘ কেন এটা ক্রয়ের, মজুদের নয় ────────────────────────────────────
 * মালিকের সীমানার টেবিল: চাহিদা · RFQ · দর · তুলনা — চারটাই **Purchase**।
 * ⚠️ মজুদ কেবল **সংকেত** দেয় (*"এটা ফুরিয়ে আসছে"*), আর সিদ্ধান্তটা
 * ক্রয়ের: কী কিনব, কার কাছ থেকে, কত দামে।
 *
 * ── ⚠️ সুইচে বন্ধ অবস্থায় যায়, আর সেটাও মালিকের সিদ্ধান্ত ─────────────
 * ⛔ ছোট দোকান চাহিদাপত্র লেখে না — মালিক নিজেই চান আর নিজেই কেনেন।
 * ⓘ তার মেনুতে সারিটা সারা বছর অব্যবহৃত পড়ে থাকত।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pur_requisitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();

            $table->string('document_no', 40);
            $table->date('trx_date');

            /*
             * ⭐ কবে লাগবে — আর এটাই কাগজটার সবচেয়ে কাজের ঘর।
             *
             * ⚠️ তারিখ ছাড়া প্রতিটা চাহিদা সমান জরুরি দেখায়, আর তখন
             * ক্রয় বিভাগ ক্রম ঠিক করে **কে বেশি বার তাগাদা দিল** তা
             * দেখে। ⓘ ঐ ক্রমটা প্রতিষ্ঠানের নয়, স্বরের।
             */
            $table->date('needed_by')->nullable();

            /*
             * ⓘ কে চেয়েছে — ব্যবহারকারী, আর বিভাগটা লেখা।
             *
             * ⚠️ বিভাগের সম্পর্ক বসালে HR-এর উপর দাঁড়াতে হত, অথচ
             * ⛔ যে প্রতিষ্ঠানে HR চালু নেই তারও চাহিদা থাকে।
             */
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('department', 120)->nullable();

            /*
             * ⭐ কেন লাগবে — ঐচ্ছিক, কিন্তু অনুমোদনের সময় এটাই পড়া হয়।
             *
             * ⛔ বাধ্যতামূলক করলে মানুষ একটা অক্ষর বসিয়ে পার পেতেন, আর
             * ঘরটা মিথ্যা হত — ⓘ ঠিক যে কারণে পরিদর্শনের মাপকাঠির ঘরটাও
             * ঐচ্ছিক।
             */
            $table->text('purpose')->nullable();
            $table->text('narration')->nullable();

            $table->string('status', 20);

            /*
             * ⓘ কোন আদেশে রূপান্তরিত হলো — খালি মানে এখনো কিছু হয়নি।
             *
             * ⚠️ সম্পর্কটা আলগা নয়, সত্যিকারের: দুইটাই ক্রয়ের নিজের
             * টেবিল, তাই সীমানা ভাঙে না।
             */
            $table->foreignId('purchase_order_id')->nullable()
                ->constrained('pur_orders')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();

            /* ⛔ `char(36)` — [[HasPublicId]] একটা UUID বসায়, ২৬ নয় */
            $table->char('public_id', 36)->nullable();
            $table->timestamps();
            $table->softDeletes();

            /* ⚠️ নাম হাতে দেওয়া — ৬৪ ছাড়ালে `migrate:fresh` সবখানে ভাঙে */
            $table->index(['company_id', 'status'], 'pur_req_state');
            $table->index(['company_id', 'trx_date'], 'pur_req_when');
            $table->unique(['company_id', 'document_no'], 'pur_req_no');
            $table->unique('public_id', 'pur_req_public');
        });

        Schema::create('pur_requisition_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_requisition_id')
                ->constrained('pur_requisitions')->cascadeOnDelete();

            $table->unsignedInteger('line_no');

            $table->foreignId('product_id')->constrained('inv_products')->cascadeOnDelete();
            $table->decimal('qty', 18, 4);

            /*
             * ⭐ আন্দাজি দর — দাম নয়, আন্দাজ।
             *
             * ⓘ যিনি চান তিনি দর জানেন না, আর জানার কথাও নয়। ⚠️ তবু
             * একটা আন্দাজ থাকলে অনুমোদনকারী বুঝতে পারেন কাগজটা দশ
             * হাজারের না দশ লাখের — আর সেটাই অনুমোদনের ছকের প্রশ্ন।
             *
             * ⛔ এই সংখ্যাটা কোনোদিন ক্রয়াদেশে যায় না: ওখানে দর আসে
             * সরবরাহকারীর কাছ থেকে।
             */
            $table->decimal('estimated_rate', 18, 4)->nullable();

            $table->string('narration', 500)->nullable();


            /*
             * ⭐ বাইরের কী সারিতেও — ২৫ সেপ্টেম্বর ২০২৬।
             *
             * ⓘ পাহারাটা ([[PublicIdTest]]) প্রতিটা ব্যবসায়িক টেবিলে চায়,
             * সারির টেবিলেও। ⚠️ কারণ ভেতরের ক্রমিক `id` কোনোদিন বাইরে
             * যায় না — API বা ফোন একটা সারি ধরে কথা বলতে চাইলে এই
             * ঘরটাই একমাত্র ঠিকানা।
             *
             * ⛔ ম্যাক্রোটা ব্যবহার করা হয়, হাতে লেখা `char()` নয়:
             * ওটা ৩৬ ঘর **আর** unique দুইটাই বসায়। ⚠️ আগে ২৬ ঘর লেখা
             * হয়েছিল একবার, আর [[HasPublicId]] একটা UUID (৩৬) বসায় —
             * ফল ছিল প্রতিটা সেভে ৫০০।
             */
            $table->publicId();
            $table->timestamps();

            $table->index(['purchase_requisition_id', 'line_no'], 'pur_req_line_order');
            $table->index(['company_id', 'product_id'], 'pur_req_line_item');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pur_requisition_lines');
        Schema::dropIfExists('pur_requisitions');
    }
};
