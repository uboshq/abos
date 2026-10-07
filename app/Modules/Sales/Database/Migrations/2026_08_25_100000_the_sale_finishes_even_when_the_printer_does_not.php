<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * রসিদের কিউ — প্রিন্টার বন্ধ থাকলেও বিক্রয় শেষ হয়।
 *
 * ── প্ল্যানের শর্ত ───────────────────────────────────────────────────
 * "প্রিন্টার বন্ধ থাকলেও বিক্রয় সম্পূর্ণ হয়; রসিদ পরে ছাপা যায়।"
 *
 * থার্মাল রোলে কাগজ ফুরানো রোজকার ঘটনা, আর ওই মুহূর্তে ক্যাশিয়ারের
 * সামনে দুইটা পথ থাকা উচিত নয়: বিক্রয়টা আটকে রাখা, বা রসিদ ছাড়াই
 * এগোনো আর পরে ভুলে যাওয়া। কাগজটা সারিতে জমা থাকে, প্রিন্টার ঠিক
 * হলে আবার চাপা যায়।
 *
 * ── কেন পুনঃছাপা গোনা হয় ────────────────────────────────────────────
 * একই বিলের দুইটা একরকম কাগজ ঘুরলে কোনটা আসল তা বলার উপায় থাকে না।
 * গোনা থাকলে দ্বিতীয়বার থেকে কাগজে DUPLICATE ছাপা যায়, আর কে কতবার
 * ছেপেছেন সেটাও জানা থাকে।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sal_print_jobs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();

            /*
             * কোন কাগজ — ডকুমেন্টের ধরন ও আইডি, বিদেশি কী নয়।
             *
             * রসিদ, চালান, গেটপাস, ভাউচার — সবগুলো আলাদা টেবিল। একটা
             * বিদেশি কী দিয়ে সবগুলো ধরা যায় না, আর প্রতিটার জন্য একটা
             * করে কলাম রাখলে সারির বেশিরভাগ ঘরই চিরকাল খালি থাকত।
             */
            $table->string('document_type', 60);
            $table->unsignedBigInteger('document_id');
            $table->string('document_no', 60)->nullable();

            // কোন কাগজে — ৫৮mm, ৮০mm, A4
            $table->string('paper', 10);

            $table->string('status', 20);

            /*
             * কতবার ছাপা হয়েছে।
             *
             * শূন্য মানে এখনো একবারও নয়। এক বা তার বেশি হলে পরের
             * কাগজে DUPLICATE বসে — গোনাটাই ওই সিদ্ধান্তের ভিত্তি।
             */
            $table->unsignedInteger('printed_count')->default(0);

            $table->timestamp('printed_at')->nullable();

            /*
             * শেষ চেষ্টা কেন ব্যর্থ হলো।
             *
             * "ছাপা হয়নি" বলে ছেড়ে দিলে ক্যাশিয়ার কাগজ ফুরানো আর
             * প্রিন্টার বন্ধ — দুইটার পার্থক্য বুঝতেন না, আর দুইটার
             * করণীয় আলাদা।
             */
            $table->string('failure', 255)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            /*
             * একই কাগজের একটাই সারি।
             *
             * প্রতিবার ছাপায় নতুন সারি বসালে "কতবার ছাপা হলো" প্রশ্নের
             * উত্তর সারি গুনে বের করতে হত, আর ব্যর্থ চেষ্টাগুলোও গোনা
             * হয়ে যেত — অথচ ব্যর্থ চেষ্টায় কোনো কাগজ বেরোয়নি।
             */
            $table->unique(['document_type', 'document_id', 'paper'], 'uq_sal_print_job_document');

            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sal_print_jobs');
    }
};
