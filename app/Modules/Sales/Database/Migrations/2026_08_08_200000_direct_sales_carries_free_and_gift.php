<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * সরাসরি বিক্রয় — ফ্রি পরিমাণ, উপহার, আর কাগজের নিচের ঘরগুলো।
 *
 * ── কেন চালানেই, নতুন ডকুমেন্টে নয় ──────────────────────────────────
 * "সরাসরি বিক্রয়" আলাদা কোনো কাগজ নয়, একটা আলাদা *পথ*: অর্ডার ছাড়াই মাল
 * বেরোয় আর তখনই বিল হয়। কাগজটা সেই একই ডেলিভারি চালান।
 *
 * নতুন টেবিল বানালে "কত মাল বেরিয়েছে" প্রশ্নের উত্তর দুই জায়গা থেকে গুনতে
 * হত, আর একদিন একটা রিপোর্ট একটা ভুলে যেত। DMS-এও এটা চালানই।
 *
 * ── ফ্রি পরিমাণ লাইনে, উপহার আলাদা টেবিলে ────────────────────────────
 * ফ্রি পরিমাণ একই পণ্যের — "১০ কার্টন কিনলে ১ ফ্রি", তাই লাইনেই বসে।
 * উপহার অন্য পণ্যের — ডিটারজেন্টের সাথে বালতি — তাই তার নিজের সারি লাগে,
 * আর কোন লাইনের বিপরীতে সেটাও লিখে রাখতে হয়। এক ঘরে চাপালে "কী কিসের
 * সাথে গেল" প্রশ্নের উত্তর থাকত না, অথচ প্রস্তুতকারকের কাছে হিসাব দিতে
 * ঠিক ওটাই লাগে।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sal_challans', function (Blueprint $table): void {
            /*
             * পরিবেশকের নিজের ডেলিভারি অর্ডার নম্বর — বাইরের কাগজ।
             *
             * আমাদের নম্বর সিরিজের সাথে গুলিয়ে ফেলা যাবে না, তাই আলাদা ঘর।
             */
            $table->string('do_no', 64)->nullable()->after('driver_name');

            // কাগজের নিচের ঘরগুলো — নমুনার টোটাল প্যানেল যে ক্রমে চায়
            $table->decimal('discount_amount', 18, 4)->default(0)->after('total');
            $table->decimal('expense_amount', 18, 4)->default(0)->after('discount_amount');
            $table->decimal('rounding_amount', 18, 4)->default(0)->after('expense_amount');

            /*
             * কাউন্টারে হাতে হাতে নেওয়া টাকা।
             *
             * চালানে লেখা থাকে, কিন্তু এখান থেকে খাতায় বসে না — আদায়ের
             * সারিটা আলাদা ডকুমেন্ট। চালান বাতিল হলে টাকাটাও ফেরত যেতে
             * হবে, আর দুই জায়গায় লেখা থাকলে একটা ফিরত আর অন্যটা থেকে যেত।
             */
            $table->decimal('deposit_amount', 18, 4)->default(0)->after('rounding_amount');

            // বাকির মেয়াদ — null মানে গ্রাহকের নিজের মেয়াদ, শূন্য নয়
            $table->unsignedSmallInteger('credit_period_days')->nullable()->after('deposit_amount');
        });

        Schema::table('sal_challan_lines', function (Blueprint $table): void {
            // একই পণ্যের ফ্রি অংশ — ফ্রি ভাণ্ডার থেকে কাটা যায়
            $table->decimal('free_qty', 18, 4)->default(0)->after('delivered_qty');
            $table->decimal('discount_percent', 8, 4)->default(0)->after('rate');
        });

        /*
         * উপহার — অন্য পণ্য, বিক্রির জন্য নয়।
         *
         * দামের কোনো ঘর নেই, ইচ্ছাকৃত। উপহারের দাম নেই বলেই ওটা উপহার;
         * একটা দামের ঘর থাকলে কেউ একদিন সেটা ভরত, আর তখন বিলের মোট আর
         * লাইনের যোগফল মিলত না।
         */
        Schema::create('sal_challan_gift_lines', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->foreignId('delivery_challan_id')->constrained('sal_challans')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('inv_products')->restrictOnDelete();

            // কোন লাইনের বিপরীতে উপহারটা — নমুনায় ডিটারজেন্টের সাথে বালতি
            $table->foreignId('against_product_id')->nullable()
                ->constrained('inv_products')->nullOnDelete();

            $table->decimal('qty', 18, 4);
            $table->string('remarks', 191)->nullable();
            $table->unsignedSmallInteger('line_no');
            $table->timestamps();

            $table->index(['delivery_challan_id', 'line_no']);
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sal_challan_gift_lines');

        Schema::table('sal_challan_lines', function (Blueprint $table): void {
            $table->dropColumn(['free_qty', 'discount_percent']);
        });

        Schema::table('sal_challans', function (Blueprint $table): void {
            $table->dropColumn([
                'do_no', 'discount_amount', 'expense_amount',
                'rounding_amount', 'deposit_amount', 'credit_period_days',
            ]);
        });
    }
};
