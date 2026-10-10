<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * রাতের হিসাব — প্রতিটা কোম্পানির, প্রতিটা শাখার, প্রতিদিনের আটটা সংখ্যা (মালিকের কেন্দ্র, ৯ অক্টোবর ২০২৬)।
 *
 * ── ⛔ কেন লাগল ─────────────────────────────────────────────────────────
 * "গত মাসের এই দিনে বাকি কত ছিল?" — খাতা থেকে আজ এর উত্তর বের করা যায় না ঠিকঠাক: তার পরে বসানো
 * পিছনের তারিখের কাগজ, শাখার বদলানো সীমা, ফেরত — সব মিলে আজকের হিসাবে তখনকার সংখ্যাটা বদলে যায়।
 * ⭐ তাই প্রতিরাতে ২৩:৫৫-এ পর্দায় যা ছিল, ঠিক তাই লিখে রাখা হয় — তিন বছর।
 *
 * ── ⓘ সারির আকার ────────────────────────────────────────────────────────
 * একটা সারি = একটা কোম্পানি × একটা জায়গা × একটা দিন। জায়গা `place`: শাখার id, আর `0` মানে গোটা
 * কোম্পানি (সব শাখা, শাখাহীন সারিসহ)। ⚠️ `branch_id`-এ খালি রাখা যেত, কিন্তু MySQL-এর অদ্বিতীয়
 * সূচক খালিকে খালির সমান ধরে না — একই রাতে দুইবার চালালে কোম্পানির সারি দুইটা হত।
 *
 * ⓘ সংখ্যার ঘর খালি = ঐ রাতে দেখার অনুমতি ছিল না বা মডিউলটা চুপ ছিল; শূন্য নয়।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('executive_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->cascadeOnDelete();

            // ⓘ শাখার id, বা 0 = গোটা কোম্পানি — অদ্বিতীয় সূচকের জন্য
            $table->unsignedBigInteger('place')->default(0);
            $table->date('taken_on');

            // ⓘ সেই দিনের বিক্রি ও আদায়; লাভ মাসের শুরু থেকে সেই দিন পর্যন্ত; বাকিগুলো সেই রাতের জের
            $table->decimal('sales', 18, 4)->nullable();
            $table->decimal('collections', 18, 4)->nullable();
            $table->decimal('receivable', 18, 4)->nullable();
            $table->decimal('payable', 18, 4)->nullable();
            $table->decimal('fund', 18, 4)->nullable();
            $table->decimal('stock', 18, 4)->nullable();
            $table->decimal('profit', 18, 4)->nullable();
            $table->unsignedInteger('signatures')->nullable();

            $table->timestamp('taken_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'place', 'taken_on'], 'exec_snap_place_day');
            $table->index(['company_id', 'taken_on'], 'exec_snap_day');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('executive_snapshots');
    }
};
