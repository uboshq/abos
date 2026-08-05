<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HR — কর্মী, বেতনের খাত, আর বেতনের কাঠামো।
 *
 * ── কেন কাঠামোটা তারিখ ধরে ───────────────────────────────────────────
 * বেতন বাড়ে। কর্মীর সারিতে একটা "বেতন" কলাম রাখলে জুলাইয়ের বেতন
 * বাড়ানোর সাথে সাথে জুনের বেতনশিটটা নতুন অঙ্কে দেখাত — অথচ জুনে ওই
 * টাকা দেওয়া হয়নি। বিনিময় হারের মতোই: সারি, কলাম নয়।
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * কর্মী।
         *
         * ব্যবহারকারী (users) আর কর্মী এক নয়, আর ইচ্ছাকৃতভাবে আলাদা:
         * গুদামের সব শ্রমিকের সিস্টেমে ঢোকার দরকার নেই, অথচ তাদের বেতন
         * হয়। উল্টোটাও — মালিকের অ্যাকাউন্ট আছে, বেতন নেই।
         *
         * যাদের দুইটাই আছে তাদের জন্য user_id, তাই "এই এন্ট্রিটা কে
         * করেছে" আর "তার বেতন কত" এক সুতোয় বাঁধা যায়।
         */
        Schema::create('hr_employees', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();

            $table->string('code', 32);
            $table->string('name_en', 120);
            $table->string('name_bn', 120)->nullable();
            $table->string('father_name', 120)->nullable();
            $table->string('mobile', 32)->nullable();
            $table->string('email', 120)->nullable();

            // জাতীয় পরিচয়পত্র — বেতনের ব্যাংক ফাইলে ও কর কাগজে লাগে
            $table->string('national_id', 32)->nullable();

            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('mdm_departments')->nullOnDelete();
            $table->foreignId('designation_id')->nullable()->constrained('mdm_designations')->nullOnDelete();
            $table->foreignId('employment_type_id')->nullable()->constrained('mdm_employment_types')->nullOnDelete();

            $table->date('joining_date');

            /*
             * চাকরি ছাড়ার তারিখ।
             *
             * ছেড়ে যাওয়া কর্মী মোছা হয় না (নিয়ম ৫) — গত বছরের বেতনশিটে
             * তার নাম থাকতেই হবে। শুধু নতুন বেতনের তালিকায় আর আসে না।
             */
            $table->date('leaving_date')->nullable();

            // বেতন কীভাবে যায় — নগদ, ব্যাংক, না মোবাইল ব্যাংকিং
            $table->string('payment_method', 16)->default('cash');
            $table->string('bank_name', 120)->nullable();
            $table->string('bank_branch', 120)->nullable();
            $table->string('bank_account_name', 120)->nullable();
            $table->string('bank_account_no', 64)->nullable();

            // ব্যাংকের ফাইলে শাখা চেনানোর নম্বর
            $table->string('bank_routing_no', 32)->nullable();
            $table->string('mfs_number', 32)->nullable();

            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'is_active']);
            $table->index(['company_id', 'department_id']);
        });

        /*
         * বেতনের খাত — মূল বেতন, বাড়িভাড়া, যাতায়াত, ভবিষ্য তহবিল, অগ্রিম কাটা।
         *
         * সারি, enum নয়: প্রতিষ্ঠানভেদে খাত আলাদা, আর নতুন একটা ভাতা
         * যোগ করতে রিলিজ লাগা উচিত নয়।
         *
         * প্রতিটা খাতের নিজের হিসাব-খাত (account_id) আছে, কারণ বেতনের
         * খরচ এক লাইনে বসলে "যাতায়াত বাবদ কত গেল" প্রশ্নের উত্তর আর
         * বইয়ে থাকত না।
         */
        Schema::create('hr_salary_heads', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('code', 32);
            $table->string('name_en', 120);
            $table->string('name_bn', 120)->nullable();

            // earning · deduction — যোগ হবে না বিয়োগ
            $table->string('kind', 16);

            /*
             * হিসাবের ধরন: fixed (টাকার অঙ্ক) না percent_of_basic (মূলের হার)।
             *
             * বাড়িভাড়া সাধারণত মূল বেতনের অর্ধেক — হার হিসেবে রাখলে
             * বেতন বাড়ার দিনে ভাতাগুলো নিজে থেকেই ঠিক হয়ে যায়।
             */
            $table->string('calculation', 24)->default('fixed');

            // এই খাতটা মূল বেতন কি না — শতাংশের হিসাব এটার উপরেই
            $table->boolean('is_basic')->default(false);

            // ছুটি-কাটা ও অনুপস্থিতির ভাগ এই খাতে বসবে কি না
            $table->boolean('prorated_by_attendance')->default(true);

            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();

            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'kind', 'is_active']);
        });

        /*
         * কর্মীর বেতনের কাঠামো — তারিখ ধরে।
         *
         * একটা কর্মীর একই খাতে সময়ে-সময়ে আলাদা অঙ্ক থাকে। যে তারিখ
         * থেকে কার্যকর সেটাই সারিতে, আর পরের সারি না আসা পর্যন্ত ওটাই
         * চলে — ঠিক বিনিময় হারের মতো।
         */
        Schema::create('hr_salary_structures', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->foreignId('salary_head_id')->constrained('hr_salary_heads')->cascadeOnDelete();

            $table->date('effective_from');

            // fixed হলে টাকা, percent_of_basic হলে হার — খাতটাই বলে দেয় কোনটা
            $table->decimal('amount', 18, 4)->default(0);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // একই দিনে একই কর্মীর একই খাতে দুইটা অঙ্ক থাকলে কোনটা সত্যি
            // তা বলার উপায় থাকত না
            $table->unique(['company_id', 'employee_id', 'salary_head_id', 'effective_from'], 'hr_structure_unique');
            $table->index(['company_id', 'employee_id', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_salary_structures');
        Schema::dropIfExists('hr_salary_heads');
        Schema::dropIfExists('hr_employees');
    }
};
