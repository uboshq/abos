<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * কার্টনের মাপ সব পণ্যে এক নয়।
 *
 * ── ⛔ কী ভাঙা ছিল, ১৯ সেপ্টেম্বর ২০২৬ ────────────────────────────────
 * রূপান্তর থাকত কেবল এককের মাস্টারে (`mdm_units.base_unit_id + factor`),
 * সব পণ্যের জন্য একটাই। ⚠️ অথচ সাবানে ১ কার্টন = ২৪ বক্স, বিস্কুটে
 * ১ কার্টন = ৪৮ পিস — "কার্টন" নামটা এক, মাপটা পণ্যের।
 *
 * ⓘ লাইভে এর ফল: Carton-এর factor ১, আর তার কোনো base-ই নেই। একটা
 * সংখ্যা বসানো যেত না যা সব পণ্যের জন্য সত্যি।
 *
 * ── ⭐ কী বদলায় ───────────────────────────────────────────────────────
 * প্রতিটা পণ্যের নিজের প্যাকের টেবিল: কোন একক, তাতে কত base, আর কোন
 * কাজে (কেনা · বেচা · POS · কাউন্টার) কোনটা আগে থেকে বাছা থাকে।
 * base = পণ্যের নিজের `unit_id` — মালিকের সিদ্ধান্তে সবচেয়ে ছোট একক।
 *
 * ⓘ এককের মাস্টারে রইল কেবল নাম, আর যে রূপান্তর **সব পণ্যে সত্যি**
 * (১ ডজন = ১২ পিস, ১ কেজি = ১০০০ গ্রাম)।
 *
 * ── ⚠️ এই মাইগ্রেশন কেবল জায়গা বানায় ─────────────────────────────────
 * টেবিলটা খালি, আর কোনো কোড এখনো পড়ে না — আচরণে শূন্য বদল। ভরাট আর
 * পড়া আলাদা আলাদা কমিটে, যাতে প্রতিটা ধাপ একা ফেরানো যায়।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_product_units', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('inv_products')->cascadeOnDelete();

            /*
             * ⚠️ restrict: একটা এককে কোনো পণ্যের প্যাক বাঁধা থাকলে এককটা
             * মোছা যায় না — মুছলে পণ্যটা নীরবে তার কার্টন হারাত।
             */
            $table->foreignId('unit_id')->constrained('mdm_units')->restrictOnDelete();

            /*
             * এই এককের একটায় কতগুলো base — base-এর নিজের সারিতে ১।
             *
             * ⓘ ছয় দশমিক, এককের মাস্টারের `factor`-এর মতোই — যাতে আজকের
             * রূপান্তর এখানে কপি হলে একটা অঙ্কও হারায় না।
             */
            $table->decimal('factor', 18, 6);

            /*
             * কোন কাজে কোনটা আগে থেকে বাছা থাকে। ⓘ চারটা আলাদা, কারণ
             * গুদামে কেনা হয় কার্টনে, দোকানে বেচা হয় পিসে — একই পণ্য।
             * "প্রতি কাজে একটাই" নিয়মটা সার্ভিসে, কলামে নয়।
             */
            $table->boolean('is_purchase_default')->default(false);
            $table->boolean('is_sales_default')->default(false);
            $table->boolean('is_pos_default')->default(false);
            $table->boolean('is_counter_default')->default(false);

            /*
             * প্যাকের নিজের বারকোড — কার্টনের গায়ে যা ছাপা, পিসেরটা নয়।
             * ⓘ স্ক্যান করলেই পণ্য আর একক দুটোই জানা যায়।
             */
            $table->string('barcode', 64)->nullable();

            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // একটা পণ্যে একটা একক একবারই — দুইটা "কার্টন" দুই মাপে নয়
            $table->unique(['product_id', 'unit_id']);

            /*
             * ⚠️ বারকোড কোম্পানিতে একটাই। MySQL-এ NULL একাধিক থাকতে পারে,
             * তাই বারকোড ছাড়া প্যাক যত খুশি। পণ্যের নিজের
             * `inv_products.barcode`-এর সাথে মেলানোটা সার্ভিসে — দুই
             * টেবিলের মধ্যে ডেটাবেস unique বসাতে পারে না।
             */
            $table->unique(['company_id', 'barcode']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_product_units');
    }
};
