<?php

declare(strict_types=1);

use App\Modules\Accounts\Models\LoanInstalment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * কিস্তির বাইরের কী।
 *
 * ── কেন আলাদা মাইগ্রেশন, আগেরটা শুধরে নয় ────────────────────────────
 * acc_loan_instalments তৈরির মাইগ্রেশনটা কমিট হয়ে গেছে এবং সার্ভারে
 * একবার চলেও গেছে। চলে যাওয়া মাইগ্রেশন বদলালে সেটা আর দ্বিতীয়বার চলে
 * না — ফলে আমার মেশিনে কলামটা থাকত, সার্ভারে থাকত না, আর ভুলটা ধরা
 * পড়ত সবচেয়ে খারাপ সময়ে।
 *
 * ── কেন কিস্তিরও বাইরের কী দরকার ────────────────────────────────────
 * কিস্তি এখন নিজেই খতিয়ানের ডকুমেন্ট (LoanInstalment::drillSourceType),
 * তাই তার দিকে বাইরে থেকে লিংক যায়। ক্রমিক id বাইরে দেখানো মানে
 * প্রতিষ্ঠানের কতগুলো সারি আছে তা ফাঁস করা, আর একজনের নম্বরে এক
 * বাড়িয়ে আরেকজনেরটা অনুমান করা।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('acc_loan_instalments', function (Blueprint $table) {
            $table->publicId();
        });

        // পুরনো সারিগুলোও পাবে — নাহলে সেগুলোর দিকে কোনো লিংক যেত না
        LoanInstalment::query()->whereNull('public_id')->eachById(
            fn (LoanInstalment $row) => $row->forceFill(['public_id' => (string) Str::uuid()])->saveQuietly(),
        );
    }

    public function down(): void
    {
        Schema::table('acc_loan_instalments', function (Blueprint $table) {
            $table->dropColumn('public_id');
        });
    }
};
