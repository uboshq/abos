<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⛔ ক্রয়ের বিলে উৎসে কর্তনের কোনো ঘর ছিল না — হিসাব #১ (গুরুতর), ২০ অক্টোবর ২০২৬ (fe; gaps-accounts.md সারি ১; [[WithheldTax]])।
 *
 * ⓘ আয়কর আইন ২০২৩ ধারা ৮৯: সরবরাহের দাম পরিশোধ বা হিসাবে জমা — যেটা আগে — তখনই আয়কর কাটা; মূসক ও SD আইন ২০১২ ধারা ৪৯: VDS।
 * বিল পাকা হওয়াই হিসাবে জমা, তাই কর্তনও বিলে। ঘর ছাড়া পুরো বিলটা সরবরাহকারীর দেনা হয়ে বসত, অথচ তাঁর হাতে যায় নিট — কাটা অংশ
 * সরকারের।
 *
 * ⓘ টাকার অঙ্কই রাখা হয়, হার নয়: হার প্রতি অর্থবছরে বদলায়, আর পুরনো বিল নতুন হারে গুনলে খাতা আর কাগজ আলাদা হত।
 * ⓘ ডিফল্ট ০ — পুরনো প্রতিটা বিল অবিকল আগের মতো (কর্তন নেই)।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pur_bills', function (Blueprint $table) {
            $table->decimal('ait_amount', 18, 4)->default(0)->after('total');
            $table->decimal('vds_amount', 18, 4)->default(0)->after('ait_amount');
        });
    }

    public function down(): void
    {
        Schema::table('pur_bills', function (Blueprint $table) {
            $table->dropColumn(['ait_amount', 'vds_amount']);
        });
    }
};
