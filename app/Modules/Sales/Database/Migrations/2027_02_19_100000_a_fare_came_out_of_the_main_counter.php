<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * গাড়ির ভাড়া কোন খাত থেকে, কে দিলেন — মালিক, ৭ অক্টোবর ২০২৬।
 *
 * ⛔ মালিকের কথা: *"এখন ভাড়া তুললে Main Counter থেকে paid দেখায়, কোনো খাত বাছার সুযোগ নেই। কোথা থেকে কে দিল,
 * সেই ব্যবস্থা লাগবে। আর কাউন্টারে বিল করার সময় পূর্ণাঙ্গ খরচ (expense) ইস্যু করে যাতে হয়।"* ⓘ আগে চালান পাকা
 * হলে ভাড়া সরাসরি খাতায় বসত (Dr ৫২১৭ / Cr প্রধান টিল) — কোনো ভাউচার, নম্বর, সই বা টাকার খাত ছাড়া।
 *
 * ঘরগুলো ([[FarePayment]]):
 *  - `fare_rule` — `voucher` হলে নতুন নিয়ম; null মানে পুরনো কাগজ, আগের পথেই চলে (কিছুই বদলায় না);
 *  - `fare_status` — `now` (এখনই দিলাম, পূর্ণাঙ্গ খরচ ভাউচার) বা `due` (পরে দেব — বাহকের নামে প্রদেয় পরিবহনে);
 *  - `fare_account_id` — টাকার খাত (নগদ টিল, ব্যাংক বা MFS); `fare_reference` — ব্যাংক বা MFS-এর TrxID;
 *  - `fare_payer_id` — কে দিলেন; `fare_voucher_id` — ভাড়ার ভাউচার (EV, বা পরে দিলে PV)।
 *
 * ⓘ সব nullable — কোনো পুরনো সারি বদলায় না।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sal_challans', function (Blueprint $table): void {
            $table->string('fare_rule', 16)->nullable()->after('fare_paid_by');
            $table->string('fare_status', 16)->nullable()->after('fare_rule');
            $table->foreignId('fare_account_id')->nullable()->after('fare_status')->constrained('accounts')->nullOnDelete();
            $table->string('fare_reference', 64)->nullable()->after('fare_account_id');
            $table->foreignId('fare_payer_id')->nullable()->after('fare_reference')->constrained('users')->nullOnDelete();
            $table->foreignId('fare_voucher_id')->nullable()->after('fare_payer_id')->constrained('vouchers')->nullOnDelete();

            // "ভাড়া বাকি" তালিকা — নাম ছোট, ৬৪-র অনেক নিচে
            $table->index(['company_id', 'fare_status'], 'sal_ch_fare_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('sal_challans', function (Blueprint $table): void {
            $table->dropIndex('sal_ch_fare_status_idx');
            $table->dropConstrainedForeignId('fare_voucher_id');
            $table->dropConstrainedForeignId('fare_payer_id');
            $table->dropColumn('fare_reference');
            $table->dropConstrainedForeignId('fare_account_id');
            $table->dropColumn(['fare_status', 'fare_rule']);
        });
    }
};
