<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * তিনজনের কাছে দর চাওয়া হলো, আর কিছুই রাখা হলো না।
 *
 * ── ⭐ মালিকের স্পেক, ২৩ সেপ্টেম্বর ২০২৬ ──────────────────────────────
 * *"RFQ · Supplier Quotation · Quotation Comparison · Negotiation"* —
 * আর তুলনায় দেখা যাবে দর · ছাড় · কর · ভাড়া · সরবরাহের সময় · শর্ত।
 *
 * ── ⛔ আজ পর্যন্ত যা হত ──────────────────────────────────────────────
 * দর চাওয়া হত ফোনে বা হোয়াটসঅ্যাপে, আর জবাবগুলো থাকত কারও ইনবক্সে।
 * ⚠️ ক্রয়াদেশে কেবল **জেতা দরটা** বসত; বাকি দুইজন কত চেয়েছিলেন তা
 * কোথাও থাকত না।
 *
 * ⓘ ফল তিনটা, আর তিনটাই নীরব:
 *   ১. *"সবচেয়ে কম দর নেওয়া হয়েছিল তো?"* — প্রমাণ নেই
 *   ২. ছয় মাস পরে একই মাল কিনতে গিয়ে আবার শূন্য থেকে দর চাওয়া
 *   ৩. যিনি বাছলেন তিনিই একমাত্র সাক্ষী
 *
 * ── ⚠️ তিনটা টেবিল, আর কেন তিনটাই লাগে ──────────────────────────────
 * ⓘ `pur_rfqs` — **আমরা কী চেয়েছি**, একবার লেখা
 * ⓘ `pur_rfq_suppliers` — **কাকে কাকে জিজ্ঞেস করেছি**; ⚠️ এটা ছাড়া
 *   *"তিনজনের কাছে চেয়েছিলাম"* দাবিটা প্রমাণহীন থাকত
 * ⓘ `pur_quotations` — **কে কী বলল**, আর প্রত্যেকের নিজের শর্ত
 *
 * ⛔ দর সরাসরি RFQ-র সারিতে বসালে একজন সরবরাহকারীর বেশি ধরা যেত না,
 * আর তুলনা বলে কিছুই থাকত না।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pur_rfqs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();

            $table->string('document_no', 40);
            $table->date('trx_date');

            /*
             * ⭐ কবের মধ্যে জবাব চাই।
             *
             * ⚠️ তারিখ ছাড়া RFQ একটা অনুরোধ মাত্র, আর অনুরোধের কোনো
             * শেষ নেই। ⓘ তারিখ থাকলে *"কার জবাব আসেনি"* প্রশ্নটার
             * উত্তর দেওয়া যায় — আর ওটাই তাগাদার ভিত্তি।
             */
            $table->date('respond_by')->nullable();

            /* ⓘ কোথায় দিতে হবে — দরে ভাড়া ধরা থাকলে এটাই তার ভিত্তি */
            $table->foreignId('warehouse_id')->nullable()
                ->constrained('inv_warehouses')->nullOnDelete();

            /*
             * ⓘ যে চাহিদা থেকে জন্মেছে — খালি হতে পারে।
             *
             * ⚠️ ক্রয় বিভাগ চাহিদা ছাড়াও দর চাইতে পারে (বাজার যাচাই),
             * ⛔ তাই বাধ্যতামূলক করা হয়নি।
             */
            $table->foreignId('purchase_requisition_id')->nullable()
                ->constrained('pur_requisitions')->nullOnDelete();

            $table->text('terms')->nullable();
            $table->text('narration')->nullable();
            $table->string('status', 20);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();

            /* ⛔ `char(36)` — [[HasPublicId]] একটা UUID বসায় */
            $table->char('public_id', 36)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'status'], 'pur_rfq_state');
            $table->index(['company_id', 'trx_date'], 'pur_rfq_when');
            $table->unique(['company_id', 'document_no'], 'pur_rfq_no');
            $table->unique('public_id', 'pur_rfq_public');
        });

        Schema::create('pur_rfq_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rfq_id')->constrained('pur_rfqs')->cascadeOnDelete();

            $table->unsignedInteger('line_no');
            $table->foreignId('product_id')->constrained('inv_products')->cascadeOnDelete();
            $table->decimal('qty', 18, 4);

            /* ⓘ কী চাই তার বিবরণ — মাপ, ব্র্যান্ড, গুণমান */
            $table->string('specification', 500)->nullable();


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

            $table->index(['rfq_id', 'line_no'], 'pur_rfq_line_order');
            $table->index(['company_id', 'product_id'], 'pur_rfq_line_item');
        });

        /*
         * ⭐ কাকে কাকে জিজ্ঞেস করা হলো।
         *
         * ⚠️ এই টেবিলটাই *"তিনজনের কাছে চেয়েছিলাম"* দাবিটাকে প্রমাণে
         * পরিণত করে। ⛔ ছাড়া কেবল যাঁরা **জবাব দিয়েছেন** তাঁদের জানা
         * যেত, আর যিনি জবাব দেননি তিনি ইতিহাস থেকেই মুছে যেতেন — অথচ
         * *"ওঁরা কেউ জবাব দেননি"* কথাটাও একটা তথ্য।
         */
        Schema::create('pur_rfq_suppliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rfq_id')->constrained('pur_rfqs')->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();

            $table->date('sent_on')->nullable();

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

            /* ⛔ একজনকে দুইবার জিজ্ঞেস করা নয় */
            $table->unique(['rfq_id', 'supplier_id'], 'pur_rfq_asked_once');
        });

        Schema::create('pur_quotations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();

            $table->string('document_no', 40);

            /*
             * ⓘ কোন RFQ-র জবাব — খালি হতে পারে।
             *
             * ⚠️ সরবরাহকারী না চাইতেও দর পাঠান (মৌসুমের শুরুতে দরপত্র),
             * ⓘ আর সেটাও রাখার মতো তথ্য।
             */
            $table->foreignId('rfq_id')->nullable()->constrained('pur_rfqs')->nullOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();

            /* ⓘ সরবরাহকারীর নিজের নম্বর — তাঁর কাগজে যা ছাপা */
            $table->string('supplier_quote_no', 60)->nullable();
            $table->date('quoted_on');

            /*
             * ⭐ কত দিন দরটা টিকবে।
             *
             * ⚠️ মেয়াদ পেরোনো দর দিয়ে আদেশ দিলে সরবরাহকারী বলতেন
             * *"ওটা তো পুরনো দর"*, আর কথাটা তাঁরই ঠিক হত।
             */
            $table->date('valid_until')->nullable();

            /* ⓘ কত দিনে মাল দিতে পারবেন — তুলনার একটা আসল কলাম */
            $table->unsignedSmallInteger('delivery_days')->nullable();
            $table->string('payment_terms', 200)->nullable();

            /*
             * ⭐ ভাড়া ও অন্যান্য খরচ — কাগজের মাথায়, সারিতে নয়।
             *
             * ⓘ সরবরাহকারী সাধারণত পুরো চালানের জন্য একটা ভাড়া বলেন,
             * পণ্য ধরে ধরে নয়। ⚠️ সারিতে ভাগ করে বসালে সংখ্যাটা
             * বানানো হত, আর তুলনায় ভুল তথ্য যেত।
             */
            $table->decimal('freight', 18, 4)->default(0);
            $table->decimal('other_charges', 18, 4)->default(0);

            $table->text('narration')->nullable();
            $table->string('status', 20);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->char('public_id', 36)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'supplier_id'], 'pur_quote_who');
            $table->index(['rfq_id', 'supplier_id'], 'pur_quote_against');
            $table->unique(['company_id', 'document_no'], 'pur_quote_no');
            $table->unique('public_id', 'pur_quote_public');
        });

        Schema::create('pur_quotation_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quotation_id')->constrained('pur_quotations')->cascadeOnDelete();

            $table->unsignedInteger('line_no');
            $table->foreignId('product_id')->constrained('inv_products')->cascadeOnDelete();

            $table->decimal('qty', 18, 4);
            $table->decimal('rate', 18, 4);

            /*
             * ⓘ ছাড় ও কর সারিতেই, কারণ সরবরাহকারীরা পণ্য ধরে ধরে ছাড়
             * দেন — ⚠️ আর একটা মোট ছাড় বসালে কোন পণ্যে কত ছাড় তা
             * হারাত, অথচ পরের বার দরাদরিতে ঠিক ওটাই লাগে।
             */
            $table->decimal('discount', 18, 4)->default(0);
            $table->decimal('tax', 18, 4)->default(0);

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

            $table->index(['quotation_id', 'line_no'], 'pur_quote_line_order');
            $table->index(['company_id', 'product_id'], 'pur_quote_line_item');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pur_quotation_lines');
        Schema::dropIfExists('pur_quotations');
        Schema::dropIfExists('pur_rfq_suppliers');
        Schema::dropIfExists('pur_rfq_lines');
        Schema::dropIfExists('pur_rfqs');
    }
};
