<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ অর্জিত ছুটির জের পরের বছরে — বছর শেষের ছবি (মালিকের অনুমোদন, ২১ অক্টোবর ২০২৬; বাংলাদেশ শ্রম আইন ২০০৬ ধারা ১১৭;
 * [[EarnedLeave]], [[CarryEarnedLeave]]; a6, ২১ অক্টোবর ২০২৬)।
 *
 *   · `hr_leave_carry_forwards` — একজন কর্মী × একটা ধরন × যে বছরে জেরটা ঢোকে (`year`): কত দিন (`days`, সীমা ৪০ — সেটিং)।
 *
 * ⓘ চলতি বছরের অর্জিত ছুটি জমা রাখা হয় না — হাজিরা থেকে গোনা হয় (প্রতি ১৮ দিন কাজে ১ দিন)। কেবল **শেষ হওয়া বছরের** জের
 * এখানে বসে, বছর শেষের কাজ থেকে (`abos:hr-carry-earned-leave`), কারণ রিপোর্ট এক প্রশ্নে বছরের পর বছর পিছিয়ে গুনতে পারে না;
 * ছবিটা থাকলে কর্মীর পাতা, ফোন আর রিপোর্ট একই সারি পড়ে। ⓘ কাজটা আবার চালালে একই সারি নতুন করে লেখে, নতুন সারি বসায় না।
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hr_leave_carry_forwards')) {
            return;
        }

        Schema::create('hr_leave_carry_forwards', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained('hr_leave_types')->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->decimal('days', 6, 1)->default(0);
            $table->timestamps();

            $table->unique(['employee_id', 'leave_type_id', 'year'], 'hr_carry_once');
            $table->index(['company_id', 'year'], 'hr_carry_year');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_leave_carry_forwards');
    }
};
