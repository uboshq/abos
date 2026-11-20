<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ মাল নামানোর (আনলোডিং) খরচ — মালিক, ১৮ নভেম্বর ২০২৬ ("ওকে"): প্রতিটা মাল গ্রহণে আর সরাসরি ক্রয়ে বাধ্যতামূলক।
 *
 * ⓘ আগে লরি থেকে মাল নামানোর মজুরি কোথাও লেখা হত না — হয় হাতের টাকা থেকে চুপচাপ যেত, নয় অন্য খরচে মিশত, আর মালের
 *   দাম ততটাই কম দেখাত (IAS 2: মাল হাতে পাওয়ার খরচ মালের দামেরই অংশ)। ⭐ এখন কাগজ পাকা হওয়ার আগে চারটার একটা বাছতে হয়:
 *   নগদে দিলাম (`cash`) · হাম্মালি ঠিকাদারের কাছে বাকি (`due`, ২১১৭) · সরবরাহকারী দিয়েছেন (`supplier`) · লাগেনি (`none`, কারণসহ)।
 *
 * ঘর (মাথায় — মাল গ্রহণ `pur_receipts` আর সরাসরি ক্রয়ের বিল `pur_bills`, দুটোতেই একই):
 *  - `unload_mode`          — উপরের চারটার একটা; null = এখনো বাছা হয়নি (পুরনো কাগজ যেমন ছিল)
 *  - `unload_amount`        — যা সত্যিই দেওয়া হলো (প্রস্তাবিত অঙ্কের চেয়ে কম-বেশি হতে পারে)
 *  - `unload_rate`          — কাগজ লেখার দিনের কার্টনপ্রতি দর (নিয়ন্ত্রণ প্যানেল) — পরে দর বদলালেও কাগজ নিজেরটা মনে রাখে
 *  - `unload_cartons`       — মোট কার্টন (কেনা + ফ্রি; কার্টন = পণ্যের কেনার প্যাক)
 *  - `unload_account_id`    — নগদের খাত (টিল); `unload_payer_id` — কে দিলেন
 *  - `unload_contractor_id` — বাকি হলে কোন ঠিকাদারের নামে (সরবরাহকারীর তালিকা থেকে)
 *  - `unload_reason`        — "লাগেনি" হলে কেন
 *
 * সারিতে (`pur_receipt_lines`, `pur_bill_lines`):
 *  - `unload_rate`    — হাতে বসানো কার্টনপ্রতি দর (বড় কার্টন ১ টাকা, খুব বড় ৫ টাকা); null = বাকিদের সাথে সমান ভাগ
 *  - `unload_cartons` — এই সারির কার্টন
 *  - `unload_cost`    — এই সারির ভাগ, পয়সা পর্যন্ত; মালের দামে (খরচের স্তরে) যোগ হয় ([[UnloadingSplit]])
 *
 * ⓘ সব খালি-যোগ্য বা শূন্য ডিফল্ট — কোনো পুরনো সারি বদলায় না। টাকা DECIMAL(18,4) ([[MoneyIsNeverAFloatTest]])।
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['pur_receipts', 'pur_bills'] as $table) {
            Schema::table($table, function (Blueprint $t): void {
                $t->string('unload_mode', 16)->nullable();
                $t->decimal('unload_amount', 18, 4)->default(0);
                $t->decimal('unload_rate', 18, 4)->nullable();
                $t->decimal('unload_cartons', 18, 4)->default(0);
                $t->foreignId('unload_account_id')->nullable()->constrained('accounts')->nullOnDelete();
                $t->foreignId('unload_contractor_id')->nullable()->constrained('suppliers')->nullOnDelete();
                $t->foreignId('unload_payer_id')->nullable()->constrained('users')->nullOnDelete();
                $t->string('unload_reason', 255)->nullable();
            });
        }

        foreach (['pur_receipt_lines', 'pur_bill_lines'] as $table) {
            Schema::table($table, function (Blueprint $t): void {
                $t->decimal('unload_rate', 18, 4)->nullable();
                $t->decimal('unload_cartons', 18, 4)->default(0);
                $t->decimal('unload_cost', 18, 4)->default(0);
            });
        }
    }

    public function down(): void
    {
        foreach (['pur_receipt_lines', 'pur_bill_lines'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn(['unload_rate', 'unload_cartons', 'unload_cost']));
        }

        foreach (['pur_receipts', 'pur_bills'] as $table) {
            Schema::table($table, function (Blueprint $t): void {
                $t->dropConstrainedForeignId('unload_payer_id');
                $t->dropConstrainedForeignId('unload_contractor_id');
                $t->dropConstrainedForeignId('unload_account_id');
                $t->dropColumn(['unload_mode', 'unload_amount', 'unload_rate', 'unload_cartons', 'unload_reason']);
            });
        }
    }
};
