<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * বিলের সারি আদেশের সারির সাথেও জুড়তে পারবে।
 *
 * ── কেন এটা লাগল ────────────────────────────────────────────────────
 * এতদিন বিলের সারি কেবল **চালানের** সারির সাথে জুড়ত। তাই পথ ছিল একটাই:
 * আদেশ → চালান → বিল। যে ডিপো মাল গ্রহণের কাগজ ব্যবহার করে না (আর
 * Control Panel-এ ওই পর্দাটা বন্ধও করা যায়), তার আদেশ কখনো বিলে
 * পৌঁছাতই না — আদেশটা তৈরি হয়ে চিরকাল ঝুলে থাকত।
 *
 * ── আর কেন শুধু ফর্ম ভরালে হত না ────────────────────────────────────
 * ঘরগুলো ভরে দেওয়া সহজ ছিল। কিন্তু "অপেক্ষমাণ আদেশ" রিপোর্ট গোনে
 * কেবল চালানের পরিমাণ (PurchaseReports::pendingOrders)। জোড়া না থাকলে
 * বিল হয়ে যাওয়ার পরেও আদেশটা ওই তালিকায় থেকে যেত, আর কেউ বুঝত না
 * মালটা আসলে এসে গেছে কি না। অর্ধেক ফিচারের চেয়ে সেটা খারাপ: সংখ্যাটা
 * ভুল, অথচ পর্দা ঠিক দেখায়।
 *
 * দুইটা জোড়াই ঐচ্ছিক ও পরস্পর-বিকল্প: একটা সারি হয় চালান ধরে আসে, নয়
 * আদেশ ধরে, নয় কোনোটাই (সরাসরি ক্রয়)।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pur_bill_lines', function (Blueprint $table) {
            $table->foreignId('purchase_order_line_id')
                ->nullable()
                ->after('purchase_receipt_line_id')
                ->constrained('pur_order_lines')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pur_bill_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('purchase_order_line_id');
        });
    }
};
