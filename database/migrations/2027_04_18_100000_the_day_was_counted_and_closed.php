<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * ⭐ দিন বন্ধ — মালিকের অনুমোদিত "দিন শেষের হিসাব" (Day Closing v7, ১৬ নভেম্বর ২০২৬; ১৮ নভেম্বর: "এখনই করে লাইভে দাও")।
 *
 * কাগজের শেষে লেখা থাকে: "নগদ গুনে খাতার সাথে মেলানো হয়েছে (ঘাটতি … — কারণ লেখা)। ১৬-১১-২০২৬-এর খাতা বন্ধ — এই দিনে নতুন বা
 * বদলানো এন্ট্রি এখন অনুমোদন ছাড়া হবে না। বন্ধ করলেন: …"। এই টেবিলে সেই বন্ধের দাগ।
 *
 * ⓘ শাখা ধরে — এক শাখার দিন বন্ধ মানে অন্য শাখার নয়; শাখা খালি = গোটা কোম্পানির দিন।
 * ⓘ বন্ধ দিনের তারিখে খাতায় লেখা আটকায় [[OpenPeriod::assertOpen()]] — পেছনের তারিখের অনুমতি (`accounts.backdate.override`)
 *   যাঁর আছে, তিনি পারেন: সেটাই "অনুমোদন"।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acc_day_closes', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->date('day');

            // ⓘ বন্ধের মুহূর্তে নগদ — গুনে পাওয়া, খাতার, পার্থক্য আর কারণ (কাগজের "দিন বন্ধ" লাইনে যায়)
            $table->decimal('cash_counted', 18, 4)->nullable();
            $table->decimal('cash_book', 18, 4)->nullable();
            $table->decimal('cash_gap', 18, 4)->nullable();
            $table->string('gap_reason', 500)->nullable();

            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();

            /*
             * ⭐ দিন আবার খোলা — মালিক, ১৮ নভেম্বর ২০২৬ ("ওকে, তাহলে তোমার প্ল্যান মতো এটা করো"): অনেক পেছনের এন্ট্রি একসাথে দিতে হলে
             * কারণ লিখে দিন খোলা, কাজ শেষে আবার গুনে-মিলিয়ে বন্ধ। ⓘ সারি মোছে না — খোলা থাকলে এই তিন ঘর ভরা; আবার বন্ধ হলে বন্ধের
             * ঘরগুলো নতুন করে বসে আর এগুলো খালি হয়, আর আগের সব অবস্থা নিরীক্ষার খাতায় থাকে ([[IsAudited]])।
             */
            $table->foreignId('reopened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reopened_at')->nullable();
            $table->string('reopen_reason', 500)->nullable();

            $table->timestamps();

            // ⓘ শাখা খালি হলে MySQL-এ UNIQUE দুইবার একই দিন আটকায় না — সেবা নিজে আগে দেখে ([[DayClose::isClosed()]])
            $table->unique(['company_id', 'branch_id', 'day'], 'acc_day_close_once');
            $table->index(['company_id', 'day'], 'acc_day_close_day');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acc_day_closes');
    }
};
