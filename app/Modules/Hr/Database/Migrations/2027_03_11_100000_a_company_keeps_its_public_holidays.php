<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ কোম্পানির ছুটির দিনের তালিকা — সরকারি ছুটি, উৎসব (মালিকের অনুমোদন, ২১ অক্টোবর ২০২৬; বাংলাদেশ শ্রম আইন ২০০৬ ধারা ১১৮;
 * [[WorkCalendar]], [[HolidayController]]; a6, ২১ অক্টোবর ২০২৬)।
 *
 *   · `hr_holidays` — একটা তারিখ, একটা নাম, আর কোন শাখার (`branch_id` খালি = সব শাখা)। সাপ্তাহিক ছুটি এখানে নয় — সেটা
 *     সেটিং (`hr.weekly_off`), কারণ প্রতি সপ্তাহের একই দিন ৫২টা সারি হয়ে বসলে একটা ভুলে যাওয়া বছর পুরোটাই কাজের দিন হত।
 *
 * ⛔ ছুটির গোনা ([[LeaveService]]) আর বেতনের কাটা দিন ([[AttendanceService::unpaidDays()]]) এই তালিকা পড়ে — তাই মোছা নরম নয়,
 * সত্যিই মোছা (অডিটে আগের মান থাকে): মুছে ফেলা একটা ছুটির দিন যেন কোনো ছাঁকনি ভুলে আবার "ছুটি" না হয়ে ওঠে।
 * ⓘ একই তারিখে একই শাখায় দুইবার নয় — নিয়ামক দেখে ([[HolidayController]]); শাখা খালি হলে ডাটাবেসের অনন্য চাবি NULL মেলায় না।
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hr_holidays')) {
            return;
        }

        Schema::create('hr_holidays', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->date('holiday_date');
            $table->string('name', 120);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'holiday_date'], 'hr_holiday_day');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_holidays');
    }
};
