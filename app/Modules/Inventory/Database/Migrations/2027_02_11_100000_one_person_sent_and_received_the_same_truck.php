<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * একই মানুষ ট্রাক পাঠাতেন আর নিজেই "পৌঁছেছে" লিখতেন — Inventory অডিট ম৪, ৫ অক্টোবর ২০২৬।
 *
 * ⓘ কে পাঠালেন তা কাগজে ছিল না (কেবল কখন), তাই "দুজনের কাজ" মাপার উপায় ছিল না। মালিকের সিদ্ধান্ত: কোম্পানির সুইচ
 * `inventory.transfer_two_people` (ডিফল্ট বন্ধ) চালু থাকলে যিনি পাঠান তিনি গ্রহণ করেন না; সুপার অ্যাডমিন পারেন, অডিটে দাগসহ।
 *
 * ⓘ MariaDB-নিরাপদ: কেবল ADD COLUMN আর একটা FK, নাম হাতে দেওয়া। পুরনো সারিতে খালি — তাদের পাঠানো মানুষ জানা নেই,
 * আর খালি থাকলে নিয়মটা তাদের থামায় না।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inv_transfers', function (Blueprint $table) {
            $table->foreignId('dispatched_by')->nullable()->after('dispatched_at')
                ->constrained('users', indexName: 'inv_transfers_sender_fk')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inv_transfers', function (Blueprint $table) {
            $table->dropForeign('inv_transfers_sender_fk');
            $table->dropColumn('dispatched_by');
        });
    }
};
