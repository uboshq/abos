<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * মজুদ কমত মাল বেরোনোর আগেই — মালিক, ৪ অক্টোবর ২০২৬ (সংস্করণ ২, আন্তর্জাতিক মান; SAP-এর Post Goods Issue)।
 *
 * ⓘ সুইচ `sales.invoice_at_goods_issue` চালু থাকলে গাড়িতে যাওয়া বিক্রির চালান নিশ্চিতে মাল কেবল **আটকায়**, আর গেট পাসে
 * বেরোয়, ইনভয়েসও তখন। দুইটা ঘর বলে কোন চালান এই নিয়মে আর মাল কখন বেরোল:
 *   `issue_at_gate`    নিশ্চিতের সময় সুইচ চালু ছিল আর মাল এখনই যায়নি — খালি/false মানে আগের নিয়ম (পুরনো কাগজ আগের মতোই)
 *   `goods_issued_at`  গেট পাসে মাল বেরোনোর মুহূর্ত — দ্বিতীয় রওনা মাল দুইবার বের করে না
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sal_challans', function (Blueprint $table) {
            $table->boolean('issue_at_gate')->default(false)->after('fare_paid_by');
            $table->timestamp('goods_issued_at')->nullable()->after('issue_at_gate');
        });
    }

    public function down(): void
    {
        Schema::table('sal_challans', fn (Blueprint $table) => $table->dropColumn(['issue_at_gate', 'goods_issued_at']));
    }
};
