<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⛔ বিক্রয়কর্মী গোটা কোম্পানির ডিলার দেখতেন — ⛔১৬, ২ অক্টোবর ২০২৬।
 *
 * ⭐ মালিকের সিদ্ধান্ত (ক), ২৬ সেপ্টেম্বর ২০২৬: *"বিক্রয়কর্মী কেবল নিজের ডিলার আর
 * এলাকার বিল ও বকেয়া দেখবেন"*। নকশা: `docs/Plan — বিক্রয়কর্মী ও ডিলারের বাঁধন.md`,
 * মালিকের উত্তর §ছ-তে।
 *
 * ── দুইটা টেবিল ─────────────────────────────────────────────────────────
 * ১ · `dealer_bindings` — কোন কর্মী কোন ডিলারের, কবে থেকে কবে পর্যন্ত।
 *     ⓘ এক ডিলারে কয়েকজন (মালিক: *"একই পয়েন্টে একাধিক SR"*); হাতবদলে পুরনো সারি
 *     `ends_on` পায়, মোছা হয় না — *"মার্চে কার ছিল"* প্রশ্নের উত্তর থাকে।
 *     ⛔ এলাকা-বাঁধন নেই: মালিক বললেন *"যা নিজে বাঁধা হবে কেবল তাই"* — এলাকা বাছলে
 *     সেই মুহূর্তের ডিলারগুলোই এক এক করে বাঁধা হয়, পরে আসা ডিলার আপনা থেকে নয়।
 *
 * ২ · `staff_supervisors` — কে কার উপরে (SM · TSM · RSM · DSM)।
 *     ⓘ মালিক: *"প্রত্যেকে নিজের নিচের লোকদের এলাকা দেখবেন"* — একজন তাঁর নিচের গোটা
 *     গাছের বাঁধা ডিলার দেখেন। প্রতি কোম্পানিতে একজনের একজনই উপরওয়ালা।
 *
 * ⚠️ `user_data_scopes` ইচ্ছা করে নয়: ওর নিয়ম *"সারি নেই মানে সব"*, এখানে উল্টো
 * (সারি নেই মানে কিছুই নয়) — একই টেবিলে দুই উল্টো নিয়ম একদিন একটা পাহারা ভুলত।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dealer_bindings', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();

            // ⓘ চালু দিনের হিসাব — শেষ দিন ফাঁকা মানে এখনো চলছে; শেষ দিনটাও গোনা হয়
            $table->date('starts_on');
            $table->date('ends_on')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('ended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // ⓘ প্রতিটা কোয়েরিতে "এই কর্মীদের আজকের ডিলার" — নাম ছোট, ৬৪-র অনেক নিচে
            $table->index(['company_id', 'user_id', 'starts_on'], 'dealer_bind_user');
            $table->index(['company_id', 'customer_id'], 'dealer_bind_customer');
        });

        Schema::create('staff_supervisors', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('supervisor_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // ⛔ এক কোম্পানিতে একজনের একজনই উপরওয়ালা — দুইজন হলে গাছ আর গাছ থাকে না
            $table->unique(['company_id', 'user_id'], 'staff_sup_one');
            $table->index(['company_id', 'supervisor_id'], 'staff_sup_under');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_supervisors');
        Schema::dropIfExists('dealer_bindings');
    }
};
