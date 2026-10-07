<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * বিল বাতিল হলো, অথচ উপহারটা দেওয়াই থেকে গেল।
 *
 * ── ⭐ স্পেক §১৮ ─────────────────────────────────────────────────────
 * *"Invoice cancel হলে promotion benefit reverse হবে এবং Original
 * Promotion, Applied Benefit, Gift, Stock Impact, Reversal, User,
 * Date/Time audit করা হবে।"*
 *
 * ── ⛔ এই দুইটা কলাম না থাকলে যা হত ─────────────────────────────────
 * ⓘ বাতিল বিলের সুবিধা [[BudgetGuard]]-এর গোনায় থেকে যেত — ⚠️ অর্থাৎ
 * একটা বাতিল বিল বাজেট খেয়ে বসে থাকত, আর নতুন ক্রেতা অফারটা পেতেন না।
 *
 * ⚠️ আর সারিটা মুছে দেওয়া যেত না: মোছা মানে *"এই বিলে কখনো অফার বসেছিল"*
 * সেই ইতিহাসটাই মোছা — §১৯ বলে নিরীক্ষার খাতা কখনো মোছা যাবে না।
 * ⭐ তাই সারিটা থাকে, কেবল ফেরতের চিহ্ন পড়ে।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promotion_applications', function (Blueprint $table) {
            $table->timestamp('reversed_at')->nullable()->after('overridden_at');
            $table->foreignId('reversed_by')->nullable()->after('reversed_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('promotion_applications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reversed_by');
            $table->dropColumn('reversed_at');
        });
    }
};
