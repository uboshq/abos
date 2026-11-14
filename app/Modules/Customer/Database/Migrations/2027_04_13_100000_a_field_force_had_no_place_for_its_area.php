<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ ফিল্ড ফোর্সের গাছ — পদ আর চলমান এলাকা (মালিক, ১২ নভেম্বর ২০২৬: *"DSM/RSM/ASM/SR নিজের এলাকার ডিলার দেখবে …
 *   কাকে কোন এলাকা দিতে হবে, কার নিচে কীভাবে ট্রি হবে — কোডের কাজগুলো করে রাখো, আমি বসাব"*)।
 *
 * ১ · `staff_positions` — পদ (SR · ASM · RSM · DSM)। ⓘ কেবল গাছে দেখানোর জন্য; দেখার সীমা আসে গাছ আর এলাকা থেকে,
 *     পদ থেকে নয় — পদ ভুল বসলেও কেউ বেশি দেখেন না।
 * ২ · `area_bindings` — চলমান এলাকা: এলাকা (আর তার নিচের এলাকা) যত ডিলার, **আজ আর পরে আসা** সবাই।
 *     ⓘ ২৬ সেপ্টেম্বরের "কেবল যা নিজে বাঁধা" বদলাল মালিকের ১২ নভেম্বরের পরিকল্পনা — নতুন ডিলার বসলে
 *     আলাদা করে বাঁধতে হলে মাঠের লোক প্রথম দিন নিজের নতুন ডিলারকে দেখতেন না।
 *     হাতবদলে সারি মোছা নয়, `ends_on` পায় — ডিলার-বাঁধনের একই নিয়ম।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_positions', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('position', 8);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'user_id'], 'staff_pos_one');
        });

        Schema::create('area_bindings', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('location_id')->constrained('mdm_locations')->cascadeOnDelete();
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('ended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'user_id', 'starts_on'], 'area_bind_user');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('area_bindings');
        Schema::dropIfExists('staff_positions');
    }
};
