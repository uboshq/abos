<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * চুক্তির দরটা থাকত কারও স্মৃতিতে।
 *
 * ── ⭐ মালিকের স্পেক, ২৩ সেপ্টেম্বর ২০২৬ ──────────────────────────────
 * *"Purchase Contract · Rate Contract · Framework Agreement · Annual
 * Contract · Blanket PO"* — আর প্রতিটায় সরবরাহকারী · মেয়াদ · পণ্য ·
 * চুক্তির দর · পরিমাণের সীমা · টাকার সীমা · নবায়ন।
 *
 * ── ⛔ আজ পর্যন্ত যা হত ──────────────────────────────────────────────
 * বছরের শুরুতে সরবরাহকারীর সাথে দর ঠিক হত, আর সেটা থাকত একটা কাগজে
 * বা কারও মনে। ⚠️ প্রতিটা আদেশে দরটা হাতে বসানো হত, আর কেউ মেলাত না।
 *
 * ⓘ ফল তিনটা, আর তিনটাই নীরব:
 *   ১. চুক্তির চেয়ে বেশি দরে অর্ডার চলে যেত, আর কেউ ধরত না
 *   ২. চুক্তির পরিমাণ কখন শেষ হলো তা কেউ জানত না
 *   ৩. মেয়াদ শেষ হওয়ার দিনটা কারও ক্যালেন্ডারে ছিল না
 *
 * ── ⚠️ কেন সীমা দুইটা — পরিমাণ আর টাকা ──────────────────────────────
 * ⓘ কিছু চুক্তি বলে *"এই দরে দশ টন দেব"*, কিছু বলে *"এই দরে পাঁচ লাখ
 * টাকার মাল দেব"*। ⛔ একটামাত্র সীমা রাখলে দ্বিতীয় জাতের চুক্তিটা
 * লেখাই যেত না, আর মানুষ পরিমাণের ঘরে টাকার অঙ্ক বসাতেন।
 *
 * ⭐ দুইটাই ঐচ্ছিক: যে চুক্তিতে সীমা নেই, তার ঘর দুইটা খালি।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pur_contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();

            $table->string('document_no', 40);
            $table->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();

            /* ⓘ সরবরাহকারীর নিজের রেফারেন্স — তাঁর কাগজে যা ছাপা */
            $table->string('supplier_ref', 60)->nullable();

            $table->date('starts_on');
            $table->date('ends_on');

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

            /*
             * ⚠️ সূচকটা মেয়াদের শেষ তারিখ ধরে, শুরু ধরে নয়।
             *
             * ⓘ রোজকার প্রশ্নটা একটাই: *"কোন চুক্তিগুলোর মেয়াদ শেষ
             * হয়ে আসছে"*। ⛔ শুরুর তারিখ ধরে সূচক বানালে ঐ প্রশ্নটাই
             * ধীর হত।
             */
            $table->index(['company_id', 'ends_on'], 'pur_contract_until');
            $table->index(['company_id', 'supplier_id'], 'pur_contract_who');
            $table->unique(['company_id', 'document_no'], 'pur_contract_no');
            $table->unique('public_id', 'pur_contract_public');
        });

        Schema::create('pur_contract_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contract_id')->constrained('pur_contracts')->cascadeOnDelete();

            $table->unsignedInteger('line_no');
            $table->foreignId('product_id')->constrained('inv_products')->cascadeOnDelete();

            /*
             * ⭐ চুক্তির দর — আর এটাই গোটা কাগজটার কারণ।
             *
             * ⚠️ এই সংখ্যাটার বিরুদ্ধেই আদেশের দর মেলানো হয়, আর না
             * মিললে বলা হয় *"চুক্তিতে তো এই দর"*।
             */
            $table->decimal('agreed_rate', 18, 4);

            /*
             * ⓘ সীমা দুইটা, আর দুইটাই ঐচ্ছিক — উপরের docblock-এ কারণ।
             */
            $table->decimal('qty_limit', 18, 4)->nullable();
            $table->decimal('value_limit', 18, 4)->nullable();

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

            /*
             * ⛔ একই চুক্তিতে একই পণ্য দুইবার নয়।
             *
             * ⚠️ থাকলে *"চুক্তির দর কত"* প্রশ্নের দুইটা উত্তর হত, আর
             * কোনটা খাটবে তা কেউ বলতে পারত না।
             */
            $table->unique(['contract_id', 'product_id'], 'pur_contract_item_once');
            $table->index(['company_id', 'product_id'], 'pur_contract_line_item');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pur_contract_lines');
        Schema::dropIfExists('pur_contracts');
    }
};
