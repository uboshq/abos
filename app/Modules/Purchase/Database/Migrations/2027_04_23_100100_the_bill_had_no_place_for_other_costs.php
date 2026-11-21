<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ বিলে "অন্যান্য খরচ"-এর ঘর ছিল না — মালিকের অনুমোদিত নমুনা v6, ট্যাব গ (২১ নভেম্বর ২০২৬)।
 *
 * ⓘ ভাড়ার (`transport_cost`) পাশে আরেকটা টাকার ঘর: ঘাট, টোল, বন্দরের ছোট খরচ — মাল হাতে পাওয়ার আগে যা দিতে হয়।
 *   [[PurchaseBillService::bringingInCost()]] দুইটাই একসাথে আনার খরচ ধরে — ভাড়ার মতোই মালের দামে আর খাতায়।
 * ⓘ ডিফল্ট ০ — পুরনো প্রতিটা বিল আগের মতোই বসে থাকে, অঙ্ক বদলায় না।
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('pur_bills', 'other_cost')) {
            return;
        }

        Schema::table('pur_bills', function (Blueprint $table): void {
            // ⓘ বাকি টাকার ঘরগুলোর সমান মাপ (১৮,৪)
            $table->decimal('other_cost', 18, 4)->default(0)->after('transport_cost');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('pur_bills', 'other_cost')) {
            return;
        }

        Schema::table('pur_bills', function (Blueprint $table): void {
            $table->dropColumn('other_cost');
        });
    }
};
