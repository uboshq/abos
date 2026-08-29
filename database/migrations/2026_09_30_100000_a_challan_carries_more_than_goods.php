<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * চালান কেবল মাল বয় না — ভাড়া, ঠিকানা, আর টাকার বিবরণও।
 *
 * ── কেন এই কলামগুলো, ২৯ আগস্ট ২০২৬ ───────────────────────────────────
 * সরাসরি বিক্রয়ের পর্দায় ছয়টা বোতাম ছিল, আর চারটা কেবল "আসছে" বলত।
 * মালিক নাম ধরে ছয়টাই চেয়েছেন, তাই প্রতিটার নিজের ঘর হলো — আর ঘরগুলো
 * সার্ভারে গিয়ে বসার জন্য কলাম লাগে।
 *
 * ── কেন ভাড়া খরচের ঘরে ঢোকানো হয়নি ──────────────────────────────────
 * ঢোকানো যেত, আর তাতে একটা কলাম কম লাগত। কিন্তু তখন "এই রুটে পরিবহনে
 * কত গেল" প্রশ্নের আর কোনো উত্তর থাকত না — খরচের ঘরে ভাড়া, হাম্মালি আর
 * নাশতা সব একসাথে জমত।
 *
 * ── কেন সবগুলো nullable ──────────────────────────────────────────────
 * ছয়টার একটাও বাধ্যতামূলক নয়। বেশিরভাগ চালানে গাড়ির নম্বরও থাকে না,
 * ঠিকানাও না — কাউন্টার থেকে মাল হাতে হাতে যায়। ডিফল্ট শূন্য বসালে
 * রিপোর্টে "ভাড়া ০" আর "ভাড়া লেখা হয়নি" এক দেখাত।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sal_challans', function (Blueprint $table) {
            $table->string('expense_narration', 191)->nullable()->after('expense_amount');

            $table->string('carrier_name', 191)->nullable()->after('driver_name');
            $table->decimal('transport_cost', 18, 4)->nullable()->after('carrier_name');

            $table->string('ship_to', 191)->nullable()->after('transport_cost');
            $table->date('ship_date')->nullable()->after('ship_to');

            $table->string('deposit_method', 32)->nullable()->after('deposit_amount');
            $table->string('deposit_ref', 64)->nullable()->after('deposit_method');
        });
    }

    public function down(): void
    {
        Schema::table('sal_challans', function (Blueprint $table) {
            $table->dropColumn([
                'expense_narration', 'carrier_name', 'transport_cost',
                'ship_to', 'ship_date', 'deposit_method', 'deposit_ref',
            ]);
        });
    }
};
