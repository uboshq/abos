<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * কোন পণ্য কোন শাখায় বিক্রি হয় — ৩০ সেপ্টেম্বর ২০২৬।
 *
 * ── ⛔ কেন ─────────────────────────────────────────────────────────────
 * UNIVER BANGLADESH-এর সাত শাখা সাতটা আলাদা ব্যবসা, প্রত্যেকের নিজের মাল। হেডারে শাখা
 * বাছলেও পণ্যের তালিকা আর বিক্রি-কেনার পিকার সব শাখার মাল দেখাত।
 *
 * ── ⭐ মালিকের সিদ্ধান্ত (খ), ৩০ সেপ্টেম্বর ─────────────────────────────
 * কিছু পণ্য একাধিক শাখায় বিক্রি হয় — তাই শাখায়-পাওয়া-যায় তালিকা, পণ্যে একটা শাখার ঘর নয়।
 * ⓘ কোনো সারি না থাকলে পণ্যটা **সব শাখার**; সারি থাকলে কেবল সেগুলোর। পণ্য একটাই থাকে —
 * দাম, লট, খরচের স্তর, কোম্পানির রিপোর্ট অক্ষত।
 *
 * ⚠️ কেবল দেখানোর তালিকা আর পিকার ছাঁকে; মজুদ, খরচ আর পোস্টিং গোটা কোম্পানির।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_product_branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('inv_products')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->timestamps();

            // ⓘ একই শাখা একই পণ্যে দুইবার নয় — নামটা ৬৪ অক্ষরের নিচে
            $table->unique(['product_id', 'branch_id'], 'inv_product_branch_unique');
            $table->index(['branch_id', 'product_id'], 'inv_product_branch_by_branch');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_product_branches');
    }
};
