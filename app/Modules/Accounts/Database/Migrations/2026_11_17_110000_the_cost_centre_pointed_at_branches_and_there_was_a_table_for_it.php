<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * খরচের কেন্দ্র ছিল শাখার দিকে, অথচ তার নিজের টেবিল আছে।
 *
 * ── ⛔ কী ভুল হয়েছিল, ১৫ সেপ্টেম্বর ২০২৬ ─────────────────────────────
 * আগের মাইগ্রেশনে `cost_centre_id` বাঁধা হয়েছিল `branches`-এ, কারণ
 * নমুনার তালিকায় লেখা ছিল "ময়মনসিংহ ডিপো · নেত্রকোনা গুদাম · প্রধান
 * অফিস · ভ্যান — ঢাকা মেট্রো ১১-৩৪৫৬" — দেখে শাখা মনে হয়।
 *
 * ⚠️ কিন্তু শেষেরটা একটা **গাড়ি**, আর গাড়ি কোনো শাখা নয়। ⓘ রেপোতে
 * `acc_cost_centers` টেবিল আর [[CostCenter]] মডেল আগে থেকেই আছে, আর
 * `VoucherController::formOptions()` ইতিমধ্যে সেটাই পাঠায়।
 *
 * ⛔ ভুল টেবিলে বাঁধা থাকলে ফর্মের তালিকা আর বিদেশি চাবি **দুই
 * জায়গার দিকে** দেখাত, আর প্রতিটা সংরক্ষণ SQL-এ ভাঙত।
 *
 * ⭐ ধরা পড়েছে কন্ট্রোলার পড়তে গিয়ে — `'costCenters' => CostCenter::…`
 * লাইনটা চোখে পড়ায়। ⓘ না পড়লে ভুলটা ধরা পড়ত প্রথম খরচ ভাউচারে।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vouchers', function (Blueprint $table): void {
            $table->dropIndex('vouchers_cost_centre_index');
            $table->dropConstrainedForeignId('cost_centre_id');
        });

        Schema::table('vouchers', function (Blueprint $table): void {
            $table->foreignId('cost_centre_id')->nullable()->after('branch_id')
                ->constrained('acc_cost_centers')->nullOnDelete();

            $table->index(['company_id', 'cost_centre_id'], 'vouchers_cost_centre_index');
        });
    }

    public function down(): void
    {
        Schema::table('vouchers', function (Blueprint $table): void {
            $table->dropIndex('vouchers_cost_centre_index');
            $table->dropConstrainedForeignId('cost_centre_id');
        });

        Schema::table('vouchers', function (Blueprint $table): void {
            $table->foreignId('cost_centre_id')->nullable()->after('branch_id')
                ->constrained('branches')->nullOnDelete();

            $table->index(['company_id', 'cost_centre_id'], 'vouchers_cost_centre_index');
        });
    }
};
