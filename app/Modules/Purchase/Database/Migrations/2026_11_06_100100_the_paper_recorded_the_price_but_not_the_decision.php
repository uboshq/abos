<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * কাগজটা দামটা লিখত, সিদ্ধান্তটা নয়।
 *
 * ── কেন পণ্যের কলাম দুইটাই যথেষ্ট নয় ────────────────────────────────
 * আগের মাইগ্রেশনে (`inv_products`) পণ্য তার **চলতি নীতি** মনে রাখে —
 * "এটা ৪০% মার্জিনে বেচি"। কিন্তু নীতিটা আসে **একটা কাগজ থেকে**: কেউ
 * একদিন একটা ক্রয় বিলে ঐ শতাংশটা লিখেছিলেন।
 *
 * ⛔ লাইনে ওটা না লিখলে বিল নিশ্চিত করার সময় জানার উপায় থাকত না
 * **কোন ঘরটা মানুষ নিজে লিখেছিলেন**। ⓘ `rate` আর `sales_price` থেকে
 * markup ও margin দুইটাই বের করা যায় — কিন্তু **কোনটা তিনি বেছেছিলেন
 * তা বের করা যায় না**, আর ঠিক ওটাই নীতি।
 *
 * ⚠️ ৫০% markup আর ৫০% margin দুইটা আলাদা দাম (১৫০ বনাম ২০০)। সংখ্যা
 * দুইটা এক হলেও সিদ্ধান্ত দুইটা আলাদা।
 *
 * ── আর একটা কারণ: কাগজ বদলায় না ─────────────────────────────────────
 * পণ্যের নীতি পরের বিলে আবার বদলাতে পারে। ⓘ কিন্তু **এই বিলটা কোন
 * নীতিতে লেখা হয়েছিল** সেটা ঐ দিনের সত্য, আর নিরীক্ষায় সেটাই লাগে।
 * ⚠️ কেবল পণ্যে রাখলে পুরনো কাগজ পড়ে বোঝা যেত না কেন ঐ দাম বসেছিল।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pur_bill_lines', function (Blueprint $table) {
            $table->string('pricing_anchor', 16)->nullable()->after('sales_price');
            $table->decimal('pricing_pct', 12, 4)->nullable()->after('pricing_anchor');
        });
    }

    public function down(): void
    {
        Schema::table('pur_bill_lines', function (Blueprint $table) {
            $table->dropColumn(['pricing_anchor', 'pricing_pct']);
        });
    }
};
