<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ মাতৃত্বকালীন সুবিধার দুই তথ্য — জীবিত সন্তান আর প্রসবের সম্ভাব্য তারিখ (fe-র উত্তর ২, ২১ অক্টোবর ২০২৬; বাংলাদেশ শ্রম আইন ২০০৬
 * ধারা ৪৬ ও ৪৭; [[LeaveService::assertEligible()]]; a6, ২১ অক্টোবর ২০২৬)।
 *
 *   · `hr_employees.surviving_children` — জীবিত সন্তান কয়জন; খালি = "তথ্য নেই" (আটকায় না, সইকারীকে সতর্ক করে)। ⛔ পরিচয়ের চাবির
 *     পেছনে ([[Employee::GUARDED]])।
 *   · `hr_leave_applications.expected_delivery_date` — মাতৃত্বকালীন ছুটির আবেদনে প্রসবের সম্ভাব্য দিন; ৮ সপ্তাহ আগে + ৮ সপ্তাহ পরে
 *     এর চারপাশে মাপা হয়।
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('hr_employees', 'surviving_children')) {
            Schema::table('hr_employees', function (Blueprint $table): void {
                $table->unsignedTinyInteger('surviving_children')->nullable()->after('gender');
            });
        }

        if (! Schema::hasColumn('hr_leave_applications', 'expected_delivery_date')) {
            Schema::table('hr_leave_applications', function (Blueprint $table): void {
                $table->date('expected_delivery_date')->nullable()->after('to_date');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('hr_leave_applications', 'expected_delivery_date')) {
            Schema::table('hr_leave_applications', function (Blueprint $table): void {
                $table->dropColumn('expected_delivery_date');
            });
        }

        if (Schema::hasColumn('hr_employees', 'surviving_children')) {
            Schema::table('hr_employees', function (Blueprint $table): void {
                $table->dropColumn('surviving_children');
            });
        }
    }
};
