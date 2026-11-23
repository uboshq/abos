<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ গ্র্যাচুইটির মাসিক সঞ্চিতি — প্রতি মাসে প্রতি শাখার একটা কাগজ: সেদিনের মোট দায়, আর তা মেলাতে কত বসল (BLA ২০০৬ ধারা ২(১০),
 * IAS 19; fe, ২১ অক্টোবর ২০২৬, দফা ৮; [[Gratuity]])।
 *
 *   · `obligation` — মাস-শেষে শাখার সব কর্মীর হিসাব করা গ্র্যাচুইটি।
 *   · `amount` — এই মাসে বসা অঙ্ক = obligation − আগের সব সঞ্চিতির যোগ (ঋণাত্মক হলে দায় কমে)।
 *   · খাতায়: ধনাত্মক — ৫২২৭ ডেবিট / ২১৩৪ ক্রেডিট; ঋণাত্মক — উল্টো।
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hr_gratuity_provisions')) {
            return;
        }

        Schema::create('hr_gratuity_provisions', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->date('month');
            $table->decimal('obligation', 18, 4)->default(0);
            $table->decimal('amount', 18, 4)->default(0);
            $table->unsignedInteger('employee_count')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'month', 'branch_id'], 'hr_grat_month_branch');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_gratuity_provisions');
    }
};
