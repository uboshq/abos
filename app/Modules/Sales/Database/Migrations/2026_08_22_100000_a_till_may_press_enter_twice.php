<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * কাউন্টার দুইবার Enter চাপলেও বিক্রয় একবার।
 *
 * ── যে ব্যর্থতাটার জন্য এটা ─────────────────────────────────────────
 * সংযোগ ধীর, পর্দায় কিছুই হচ্ছে না, ক্যাশিয়ার আবার Enter চাপেন। দুইটা
 * অনুরোধ পৌঁছায়, দুইটাই সম্পূর্ণ বৈধ, আর দুইটা বিল খাতায় বসে: মাল
 * দুইবার কমে, ক্রেতার কাছ থেকে টাকা দুইবার ওঠে।
 *
 * দুইটা অনুরোধের কোনোটাতেই কিছু ভুল নেই — **ওরা হুবহু এক, আর সেটাই
 * পুরো সমস্যা।** সার্ভারের পক্ষে "এটা দ্বিতীয় ক্রেতা, নাকি একই
 * ক্রেতার দ্বিতীয় চাপ" বলার কোনো উপায় নেই, যদি না টিল নিজে বলে দেয়।
 *
 * ── ইনডেক্সটাই আসল পাহারা ───────────────────────────────────────────
 * বসানোর আগে চাবিটা খুঁজে দেখা সাধারণ দুইবার-চাপা সামলায়। কিন্তু দুইটা
 * অনুরোধ যথেষ্ট কাছাকাছি এলে দুইজনেই খোঁজে, দুইজনেই কিছু পায় না, আর
 * দুইজনেই বসায় — ওখানে ডাটাবেজ দ্বিতীয়টাকে ফিরিয়ে দেয়। খোঁজা মাত্র
 * জানালাটা ছোট করে, বন্ধ করে না।
 *
 * ── PostgreSQL-এর আংশিক ইনডেক্স এখানে লাগে না ───────────────────────
 * অন্য পথে তৈরি বিলে চাবি থাকে না — সরাসরি বিক্রয়, ইমপোর্ট, চালান
 * থেকে বিল — আর ওগুলোর প্রতিটাতে কলামটা NULL। **MySQL-এ ইউনিক
 * ইনডেক্সে NULL কখনো NULL-এর সমান নয়**, তাই হাজারটা চাবিহীন বিল একই
 * ইনডেক্সে পাশাপাশি বসে। PostgreSQL-এ `WHERE key IS NOT NULL` লিখতে
 * হত; MySQL-এ আচরণটা এমনিতেই তাই।
 *
 * প্রথম দফায় এখানে একটা generated কলাম বসানো হয়েছিল ওই আংশিক আচরণ
 * নকল করতে — অপ্রয়োজনীয়, আর একটা কলাম বেশি মানে একটা জিনিস বেশি যা
 * ভুল হতে পারে।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sal_invoices', function (Blueprint $table) {
            if (! Schema::hasColumn('sal_invoices', 'idempotency_key')) {
                $table->string('idempotency_key', 64)->nullable()->after('document_no');
            }
        });

        Schema::table('sal_invoices', function (Blueprint $table) {
            $table->unique(['company_id', 'idempotency_key'], 'uq_sal_invoice_idempotency');
        });
    }

    public function down(): void
    {
        Schema::table('sal_invoices', function (Blueprint $table) {
            $table->dropUnique('uq_sal_invoice_idempotency');
            $table->dropColumn('idempotency_key');
        });
    }
};
