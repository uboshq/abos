<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ঋণের নড়াচড়া — তোলা, জমা, সুদ।
 *
 * ── কেন ঋণ নিজে খতিয়ানের ডকুমেন্ট নয় ────────────────────────────────
 * একটা ঋণ একটা চুক্তি, একটা ঘটনা নয়। ওটা বছরের পর বছর থাকে আর তার
 * ভেতরে বহুবার টাকা নড়ে। পোস্টিং ইঞ্জিন ঠিকই একই ডকুমেন্টকে দুইবার
 * খতিয়ানে বসতে দেয় না — ওই পাহারাটাই Save-এ দুইবার ক্লিক করলে হিসাব
 * দ্বিগুণ হওয়া ঠেকায়। তাই ঋণকে ডকুমেন্ট বানালে হয় পাহারাটা দুর্বল
 * করতে হত, নয়তো দ্বিতীয় কিস্তিটা বসতই না।
 *
 * সমাধান পাহারা বদলানো নয় — সত্যটা মেনে নেওয়া: **প্রতিটা নড়াচড়াই
 * নিজে একটা ডকুমেন্ট**, তার নিজের তারিখ, নিজের অঙ্ক, নিজের নম্বর।
 * ঠিক যেমন গ্রাহক নিজে খতিয়ানে বসে না, তার চালানগুলো বসে।
 *
 * কিস্তির জন্য আলাদা সারি লাগে না — acc_loan_instalments আগে থেকেই
 * আছে আর প্রতিটা কিস্তির নিজের id আছে, তাই সেটাই তার ডকুমেন্ট।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acc_loan_movements', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('loan_id')->constrained('acc_loans')->cascadeOnDelete();

            // draw = টাকা তোলা, repay = জমা, interest = সুদ বসানো
            $table->string('kind', 16);

            $table->string('document_no', 40);
            $table->date('trx_date');
            $table->decimal('amount', 18, 4);

            /*
             * টাকাটা কোন খাতে এল বা কোথা থেকে গেল।
             *
             * সুদে এটা ফাঁকা: ব্যাংক CC-র সুদ আলাদা করে নেয় না, বকেয়ার
             * সাথে যোগ করে দেয় — তাই নগদ-ব্যাংকের কোথাও কিছু নড়ে না।
             */
            $table->foreignId('counter_account_id')->nullable()->constrained('accounts')->nullOnDelete();

            $table->string('narration', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'loan_id', 'trx_date']);
            $table->unique(['company_id', 'document_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acc_loan_movements');
    }
};
