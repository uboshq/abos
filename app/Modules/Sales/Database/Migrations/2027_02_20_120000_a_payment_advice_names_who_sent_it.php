<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * জমার বিজ্ঞপ্তি কে পাঠালেন — টাকার পরিকল্পনা ৩ (সমন্বয়ক, ৭ অক্টোবর ২০২৬)।
 *
 * ⓘ কর্মী (SR, ফোন বা ওয়েব) পাঠালে তাঁর id; দোকানি পোর্টাল থেকে পাঠালে খালি — সেখানে বিজ্ঞপ্তির গ্রাহকই পাঠানেওয়ালা।
 * ⭐ এর উপর দাঁড়ায় "যিনি পাঠালেন তিনি নিজে গ্রহণ করেন না" ([[DepositClaim]]-এর পাহারা, সুইচ `sales.advice_four_eyes`)।
 * ⓘ আগের বিজ্ঞপ্তিগুলো যেমন ছিল — খালি; কর্মীর নাম তাদের নোটে লেখা আছে ("[পাঠালেন: …]"), সেখান থেকে পেছনে ভরা হয় না।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sal_deposit_claims', function (Blueprint $table) {
            $table->foreignId('submitted_by')->nullable()->after('customer_id')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sal_deposit_claims', function (Blueprint $table) {
            $table->dropConstrainedForeignId('submitted_by');
        });
    }
};
