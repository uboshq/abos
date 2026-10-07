<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * পার্টির আচরণ — Status বলে না, কিন্তু কাউন্টারে যা জানা দরকার।
 *
 * গ্রাহকের Status বলে তার সাথে কারবার করা যাবে কিনা; কেমন কারবার তা বলে
 * না। একই ACTIVE-এর ভেতরে যে বিল পৌঁছানোর দিনই টাকা দেয়, আর যে ৯০ দিন
 * নেয় ও গাড়ি গেটে দাঁড় করিয়ে রাখে — দুইজনই আছে। এই পার্থক্য এতদিন কেবল
 * পুরনো কারবারির স্মৃতিতে থাকত। এই টেবিল সেই স্মৃতিটাকে খাতায় আনে।
 *
 * ── কয়টা সিদ্ধান্ত স্কিমাতেই ─────────────────────────────────────────
 * `type` বাঁধা তালিকার কোড (মুক্ত লেখা নয়) — একই অভ্যাস সব ডিপোতে এক
 * কোডে বসে বলেই "কোন ডিলাররা দেরি করে" রিপোর্ট হতে পারে।
 * `recorded_by` বাধ্যতামূলক — বেনামি লাল পতাকাকে প্রশ্ন করা যায় না।
 * মোছার কলাম নেই — পার্টি শুধরালে `is_active=false` হয় (`retired_by/at`),
 * কিন্তু সারিটা থাকে; ইতিহাস মেলে না গেলে "আগে খারাপ ছিল, এখন ভালো" বলা
 * যেত না। severity এখানে নেই — ধরন থেকেই গোনা হয়, drift নেই।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_conduct_notes', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();

            // বাঁধা তালিকার কোড (ConductType) — নাম/গুরুত্ব ওখান থেকে
            $table->string('type', 40);
            // OTHER-এ বাধ্যতামূলক; সার্ভিস সেটা দেখে
            $table->string('note', 500)->nullable();

            $table->boolean('is_active')->default(true);

            // কে লিখল — বাধ্যতামূলক, তাই nullable নয়
            $table->foreignId('recorded_by')->constrained('users');
            $table->timestamp('recorded_at');

            // নামানো হলে — মোছা নয়
            $table->foreignId('retired_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('retired_at')->nullable();

            $table->timestamps();

            // সরাসরি বিক্রয়ে সব গ্রাহকের চলমান পতাকা একসাথে তোলা হয়
            $table->index(['company_id', 'customer_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_conduct_notes');
    }
};
