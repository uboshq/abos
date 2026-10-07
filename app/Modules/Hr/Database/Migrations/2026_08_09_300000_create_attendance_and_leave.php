<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * হাজিরা ও ছুটি।
 *
 * ── কেন দুইটা একসাথে ────────────────────────────────────────────────
 * মঞ্জুর হওয়া ছুটি হাজিরার খাতায় গিয়ে বসে — নাহলে ছুটিতে থাকা লোকটাকে
 * অনুপস্থিত দেখাত, আর বেতন থেকে কাটা যেত। দুইটা আলাদা করে বানালে
 * ওই যোগসূত্রটা কেউ পরে জুড়তে ভুলত।
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * ছুটির ধরন — নৈমিত্তিক, অসুস্থতা, বার্ষিক, বিনা বেতনে।
         *
         * সারি, enum নয়: কোন প্রতিষ্ঠানে কী কী ছুটি আর বছরে কত দিন, তা
         * তাদের নিজের নীতি। "মাতৃত্বকালীন" যোগ করতে রিলিজ লাগা উচিত নয়।
         */
        Schema::create('hr_leave_types', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('code', 32);
            $table->string('name_en', 120);
            $table->string('name_bn', 120)->nullable();

            // বছরে কত দিন — শূন্য মানে সীমা নেই (বিনা বেতনের ছুটি)
            $table->decimal('days_per_year', 6, 1)->default(0);

            /*
             * এই ছুটিতে বেতন কাটে কি না।
             *
             * বিনা বেতনের ছুটি হাজিরার খাতায় "ছুটি" হয়েই বসে, কিন্তু
             * বেতনের হিসাবে অনুপস্থিতির মতোই গোনা হয়। দুইটা আলাদা না
             * রাখলে হয় সব ছুটিতে বেতন কাটত, নয় কোনোটাতেই কাটত না।
             */
            $table->boolean('is_paid')->default(true);

            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code']);
        });

        /*
         * ছুটির আবেদন।
         *
         * ── কেন নিজের অনুমোদন, সাধারণ ইঞ্জিন নয় ────────────────────
         * অনুমোদন ইঞ্জিনটা ছক-নির্ভর: ছক না থাকলে সে "এগিয়ে যাও" বলে।
         * বেশিরভাগ কাজে সেটাই ঠিক, ছুটিতে নয় — কেউ নিজের ছুটি নিজে
         * মঞ্জুর করতে পারে না, ছক থাকুক বা না থাকুক।
         */
        Schema::create('hr_leave_applications', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained('hr_leave_types')->restrictOnDelete();

            $table->date('from_date');
            $table->date('to_date');

            /*
             * কত দিন — আধা দিনের জন্য দশমিক।
             *
             * তারিখ দুইটা থেকে গুনে নেওয়া যেত, কিন্তু আধা দিনের ছুটি
             * তখন লেখাই যেত না, আর সাপ্তাহিক ছুটি বাদ দেওয়ার নিয়ম
             * বদলালে পুরনো আবেদনের হিসাবও বদলে যেত।
             */
            $table->decimal('days', 5, 1);

            $table->string('reason', 500)->nullable();

            // pending · approved · rejected · cancelled
            $table->string('status', 16)->default('pending');

            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_remarks', 500)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'employee_id', 'from_date']);
            $table->index(['company_id', 'status']);
        });

        /*
         * হাজিরা — একজনের এক দিন।
         *
         * প্রতিদিন প্রতিজনের একটা সারি। মাসে ত্রিশ দিন আর বিশ জন মানে
         * ছয়শো সারি — সংখ্যাটা বড় শোনায়, কিন্তু "গত মঙ্গলবার কে আসেনি"
         * প্রশ্নের উত্তর অন্য কোনো গড়নে দেওয়া যায় না।
         */
        Schema::create('hr_attendance', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();

            $table->date('work_date');

            // present · absent · leave · holiday
            $table->string('status', 16);

            /*
             * দেরিটা আলাদা পতাকা, অবস্থা নয়।
             *
             * দেরিতে আসা মানুষটা উপস্থিতই — অনুপস্থিত নন। এক ঘরে মেশালে
             * "কতজন এসেছে" গোনার সময় দেরিওয়ালারা বাদ পড়ত।
             */
            $table->boolean('is_late')->default(false);

            $table->time('in_time')->nullable();
            $table->time('out_time')->nullable();
            $table->string('remarks', 191)->nullable();

            // মঞ্জুর হওয়া ছুটি থেকে বসলে কোন আবেদন থেকে
            $table->foreignId('leave_application_id')->nullable()
                ->constrained('hr_leave_applications')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // একজনের এক দিনে দুইটা হাজিরা মানে দুইটা সত্য
            $table->unique(['company_id', 'employee_id', 'work_date'], 'hr_attendance_unique');
            $table->index(['company_id', 'work_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_attendance');
        Schema::dropIfExists('hr_leave_applications');
        Schema::dropIfExists('hr_leave_types');
    }
};
