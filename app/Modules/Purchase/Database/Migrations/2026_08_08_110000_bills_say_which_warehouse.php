<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * সরাসরি ক্রয় — মাল ঢোকা, দাম বসা আর বিক্রয়মূল্য ঠিক হওয়া, একই কাগজে।
 *
 * ── কেন গুদামের কলামটা এতদিন লাগেনি ─────────────────────────────────
 * মাল ঢুকত কেবল "মাল বুঝে নেওয়া"র নথিতে, আর সেখানে গুদাম বলা থাকত।
 * বিল শুধু দায় বসাত, তাই তার গুদাম জানার দরকার ছিল না।
 *
 * ── কেন এখন লাগছে ───────────────────────────────────────────────────
 * মালিকের সিদ্ধান্ত (২০২৬-০৮-০৭): চালান ছাড়া সরাসরি বিল বসানো চলবে,
 * কারণ ডিপোতে অনেক সময় মাল আর বিল একসাথেই আসে। তখন বিলকেই মাল ঢোকাতে
 * হবে — নইলে খাতা বলত মাল এসেছে আর গুদাম বলত আসেনি, যেটা পর্দা চালিয়ে
 * দেখতে গিয়েই ধরা পড়েছিল।
 *
 * গুদামের ঘরটা nullable: যে বিলের প্রতিটা লাইনের পেছনে চালান আছে সেটা
 * কোনো মাল ঢোকায় না, মাল আগেই ঢুকে গেছে। তাকে গুদাম বলতে বাধ্য করলে
 * ব্যবহারকারী এমন একটা ঘর ভরতেন যেটার কোনো প্রভাব নেই, আর একদিন ভুল
 * গুদাম বেছে ভাবতেন মাল সেখানে গেছে।
 *
 * ── আর বিক্রয়মূল্য কেন ক্রয়ের কাগজে ─────────────────────────────────
 * মালিকের কথা: "direct purchase-এর সময়েই sales price দেব।" কারণটা
 * ডিপোর বাস্তব — ট্রাক গেটে দাঁড়িয়ে, নতুন দরে মাল এসেছে, আর ওই দরেই
 * ঠিক হবে আজ কত দামে বেচা হবে। আলাদা পর্দায় পাঠালে দুইটা কাজ দুই সময়ে
 * হত, আর মাঝের সময়টুকু পুরনো দামে বিক্রি চলত।
 *
 * কলামটা লাইনে, পণ্যে নয় — পণ্যের দামটা বদলায় বটে, কিন্তু কোন কাগজে
 * বদলাল সেটা এখানে লেখা থাকে। ছয় মাস পর "দামটা কবে থেকে ১৫৫ হলো"
 * প্রশ্নের উত্তর তখন একটা চালানের নম্বর।
 *
 * শূন্য নয়, null — শূন্য মানে "বিনামূল্যে বেচব", আর null মানে "দাম
 * বদলাব না"। দুইটা এক করে ফেললে দাম না বদলাতে চাওয়া প্রতিটা লাইন
 * পণ্যটার দাম শূন্য করে দিত।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pur_bills', function (Blueprint $table) {
            $table->foreignId('warehouse_id')
                ->nullable()
                ->after('supplier_id')
                ->constrained('inv_warehouses')
                ->nullOnDelete();
        });

        Schema::table('pur_bill_lines', function (Blueprint $table) {
            $table->decimal('sales_price', 18, 4)->nullable()->after('rate');
        });
    }

    public function down(): void
    {
        Schema::table('pur_bill_lines', function (Blueprint $table) {
            $table->dropColumn('sales_price');
        });

        Schema::table('pur_bills', function (Blueprint $table) {
            $table->dropConstrainedForeignId('warehouse_id');
        });
    }
};
