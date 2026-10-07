<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ফ্রি মাল ঢোকার ঘর — চালানে ও বিলে।
 *
 * ── কী ভেঙেছিল ──────────────────────────────────────────────────────
 * ফ্রি পণ্যের নিজস্ব ভাণ্ডার আছে (`free_change`, ৮ আগস্ট), বেরোনোর পথ
 * আছে (Direct Sales-এর ফ্রি ও উপহার) — **ঢোকার পথ ছিল না**। গোটা
 * প্রকল্পে `free:` লেখা ছিল দুই জায়গায়, দুইটাই বিয়োগ।
 *
 * ফল দুইটা, দুইটাই নীরব:
 *
 *   ১. ক্রয়ে কেউ "১০ কার্টন ফ্রি" লিখলে সংখ্যাটা সেবা পর্যন্ত পৌঁছাত,
 *      তারপর হারিয়ে যেত — কলামই ছিল না। গুদামে ১০ কার্টন থাকত,
 *      ব্যবস্থায় থাকত না।
 *   ২. কাউন্টারে ফ্রি দিতে গেলে বিক্রয়টাই আটকে যেত ("যথেষ্ট ফ্রি মাল
 *      নেই"), কারণ ভাণ্ডারটা চিরকাল শূন্য।
 *
 * টেস্ট ধরেনি: `DirectSaleTest::receiveFree()` নিজেই হাতে ভাণ্ডারটা ভরে
 * নিত। জিনিসটা না থাকলেও পরীক্ষাটা পাশ করত।
 *
 * ── কেন দুই জায়গায় ─────────────────────────────────────────────────
 * মাল ঢোকে দুই পথে: GRN থাকলে চালানে (`PurchaseReceiptService`), না
 * থাকলে সরাসরি বিলে (`bringInDirectLines`)। ফ্রি মালও ঠিক সেই পথেই
 * ঢুকতে হবে, নাহলে যে ডিপো চালান ব্যবহার করে তাদের ফ্রি মাল কোথাও
 * ঢুকত না — আর ওরাই সবচেয়ে বেশি ফ্রি পায়।
 *
 * ── কেন `entered_free_qty` নেই ──────────────────────────────────────
 * প্যাকে লেখা পরিমাণ (`entered_qty`) মনে রাখা হয় কারণ কাগজে ঠিক যা
 * লেখা হয়েছিল তাই ছাপতে হয়। ফ্রি পরিমাণ সেই একই সারির একই এককে —
 * "২ বাক্স, সাথে ১ বাক্স ফ্রি"। আলাদা একক রাখলে একই সারিতে দুইটা একক
 * থাকত, আর সেটা কাগজেও দুই রকম দেখাত।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pur_receipt_lines', function (Blueprint $table): void {
            $table->decimal('free_qty', 18, 4)->default(0)->after('received_qty');
        });

        Schema::table('pur_bill_lines', function (Blueprint $table): void {
            $table->decimal('free_qty', 18, 4)->default(0)->after('qty');
        });
    }

    public function down(): void
    {
        Schema::table('pur_receipt_lines', function (Blueprint $table): void {
            $table->dropColumn('free_qty');
        });

        Schema::table('pur_bill_lines', function (Blueprint $table): void {
            $table->dropColumn('free_qty');
        });
    }
};
