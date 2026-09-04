<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ক্রয়ে উপহার — মিল যা সাথে দিয়ে দেয়, অথচ বিলে নেই।
 *
 * ── মালিকের নির্দেশ (৪ সেপ্টেম্বর ২০২৬) ──────────────────────────────
 * *"ফ্রি পণ্য ক্রয়ে থাকবে ও stock-এ free আলাদা manage হবে"* ·
 * *"উপহার কোন পণ্যের সাথে আসল তাও manage করতে হবে, এই অনুযায়ী sales-এও
 * যাবে"*
 *
 * ── ⚠️ ফ্রি পরিমাণ আর উপহার এক জিনিস নয় ─────────────────────────────
 * দুইটা আলাদা ঘটনা, আর সেই কারণেই দুইটা আলাদা জায়গায় বসে:
 *
 *   ফ্রি পরিমাণ   **একই** পণ্যের  — "১০ কার্টন সাবানে ১ কার্টন ফ্রি"
 *                 → `pur_bill_lines.free_qty`, আজই আছে
 *   উপহার        **অন্য** পণ্যের — "সাবানের সাথে একটা বালতি"
 *                 → এই টেবিল
 *
 * একই লাইনে দুইটা রাখা যেত না: ফ্রি পরিমাণ লাইনের পণ্যকেই বোঝায়, আর
 * উপহারের নিজের একটা পণ্য আছে।
 *
 * ── ⛔ শূন্য দরে আলাদা বিল-সারি কেন নয় ───────────────────────────────
 * সবচেয়ে সহজ পথটা ছিল উপহারটাকে দর ০ দিয়ে একটা সাধারণ সারি বানানো।
 * **তাতে "কোন পণ্যের সাথে এল" চিরতরে হারাত।**
 *
 * আর হিসাবটাও ভুল হত: দশ কার্টন সাবানের সাথে এক কার্টন ফ্রি পেলে
 * সাবানের **আসল ক্রয়দর** এগারো ভাগে ভাগ হয়ে যায়, দশ ভাগে নয়।
 * সংযোগটা না থাকলে ওই হিসাবটাই করা যায় না।
 *
 * ⓘ বিক্রয়ের দিকে এই গড়নটা আগে থেকেই আছে
 * ([[App\Modules\Sales\Models\DeliveryChallanGiftLine]]) — **একই গড়ন দুই
 * দিকে**, তাই "মিল যা উপহার দিল, ডিলারকে যা উপহার দেওয়া হলো" দুইটা এক
 * ভাষায় পড়া যায়।
 *
 * ── ⛔ দামের কোনো ঘর নেই, আর সেটা ইচ্ছাকৃত ───────────────────────────
 * বিক্রয়ের মাইগ্রেশনে কারণটা লেখা: *"একটা দামের ঘর থাকলে কেউ একদিন সেটা
 * ভরত, আর তখন লাইনের যোগফল বিলের মোটের সাথে মিলত না।"* এখানেও তাই —
 * উপহারের দাম নেই, তাই ঘরও নেই।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pur_bill_gift_lines', function (Blueprint $table): void {
            $table->id();
            $table->publicId();

            $table->foreignId('purchase_bill_id')->constrained('pur_bills')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('inv_products')->restrictOnDelete();

            /*
             * কোন পণ্যের বিপরীতে উপহারটা এল।
             *
             * ⚠️ নালযোগ্য, কারণ বাস্তবে সবসময় জোড়াটা থাকে না — মিল
             * একটা ক্যালেন্ডার বা একটা ছাতা পাঠিয়ে দিতে পারে যা
             * কোনো নির্দিষ্ট পণ্যের সাথে নয়। জোড়া বাধ্যতামূলক করলে
             * ক্যাশিয়ার একটা যেকোনো পণ্য বেছে নিতেন, আর তখন হিসাবটা
             * **ভুল হত, খালি থাকার চেয়েও খারাপ**।
             */
            $table->foreignId('against_product_id')->nullable()
                ->constrained('inv_products')->nullOnDelete();

            $table->decimal('qty', 18, 4);

            /*
             * প্যাকে লেখা পরিমাণ — "১ বাক্স", "২ ডজন"।
             *
             * ⓘ [[App\Modules\Inventory\Concerns\HasEnteredPack]] এই তিনটা
             * ঘর একসাথে ব্যবহার করে: যা লেখা হয়েছিল তা-ই ফেরত দেখায়,
             * আর ভিতরে হিসাবটা মূল এককে চলে।
             */
            $table->decimal('entered_qty', 18, 4)->nullable();
            $table->foreignId('entered_unit_id')->nullable()
                ->constrained('mdm_units')->nullOnDelete();

            $table->string('remarks', 191)->nullable();
            $table->unsignedSmallInteger('line_no');
            $table->timestamps();

            $table->index(['purchase_bill_id', 'line_no']);
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pur_bill_gift_lines');
    }
};
