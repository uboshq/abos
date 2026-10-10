<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * একটা ব্যাংক স্লিপে একটাই চালু জমার বিজ্ঞপ্তি — টাকার পরিকল্পনা ৪ (সমন্বয়ক, ৭ অক্টোবর ২০২৬)।
 *
 * ── ⛔ আগের তালা কী ধরত, কী ধরত না ─────────────────────────────────────
 * `sal_claim_reference_once` (company, customer, reference) — একই দোকান একই রেফারেন্স দুইবার পাঠাতে পারত না, কিন্তু:
 *   • অন্য দোকানের নামে একই স্লিপ চলে যেত (SR একবার, দোকানি পোর্টালে আবার) — একই টাকা দুই খাতায়;
 *   • প্রত্যাখ্যাত বিজ্ঞপ্তির রেফারেন্স আর কোনোদিন পাঠানো যেত না — ভুল ধরে আবার পাঠানোর পথ বন্ধ, ডাটাবেজের ভাঙা বার্তা;
 *   • "trx-1" আর "TRX 1" আলাদা ধরা হত।
 *
 * ── ⭐ এখন ─────────────────────────────────────────────────────────────
 * নিয়মটা সেবায়, ব্যাংক খাতের সারিতে তালা দিয়ে ([[DepositClaimService::assertReferenceIsFree()]]): একই খাত আর একই রেফারেন্সে
 * একটাই চালু (প্রত্যাখ্যাত নয়) বিজ্ঞপ্তি, যে দোকানের নামেই হোক। এখানে কেবল পুরনো তালা তোলা, আর খোঁজার জন্য সাধারণ index।
 *
 * ⛔ ডাটাবেজে unique নয় কেন: "প্রত্যাখ্যাত বাদে" শর্তওয়ালা unique MySQL/MariaDB-তে কেবল generated column দিয়ে হয়, আর
 * `bank_account_id`-এর foreign key-তে SET NULL থাকায় STORED generated column বসে না (১২১৫ — ৮ অক্টোবর ২০২৬-এ সবার পরীক্ষা
 * ভেঙেছিল)। ⓘ নতুন index আগে, পুরনোটা পরে — `company_id`-এর foreign key-র সহায় index মাঝপথে হারায় না।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sal_deposit_claims', function (Blueprint $table) {
            $table->index(['company_id', 'bank_account_id', 'reference'], 'sal_claim_bank_ref_idx');
        });

        Schema::table('sal_deposit_claims', function (Blueprint $table) {
            $table->dropUnique('sal_claim_reference_once');
        });
    }

    public function down(): void
    {
        Schema::table('sal_deposit_claims', function (Blueprint $table) {
            $table->unique(['company_id', 'customer_id', 'reference'], 'sal_claim_reference_once');
        });

        Schema::table('sal_deposit_claims', function (Blueprint $table) {
            $table->dropIndex('sal_claim_bank_ref_idx');
        });
    }
};
