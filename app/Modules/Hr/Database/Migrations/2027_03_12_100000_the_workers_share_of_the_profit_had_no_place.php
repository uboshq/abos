<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ শ্রমিকের মুনাফায় অংশগ্রহণ তহবিল (WPPF) — বাংলাদেশ শ্রম আইন ২০০৬, অধ্যায় ১৫ (a6, ২১ অক্টোবর ২০২৬; [[WppfService]])।
 *
 * ⓘ বছরে একটা সারি: কোন অর্থবছর, সেদিনের লাভ-ক্ষতির নিট মুনাফা, ৫% আর তার তিন ভাগ (ধারা ২৩৪, ২০১৩-র সংশোধনী — ৮০:১০:১০),
 * আর "সই দিন" পড়লে কোন জাবেদায় বসল। ⛔ বছরে একটার বেশি নয় (unique) — একই বছরের তহবিল দুইবার খরচে বসলে মুনাফা দুইবার কমত।
 * ⓘ মোছা হয় না; কোম্পানির সুইচ বন্ধ থাকলে টেবিলটা খালি থাকে, কেউ ছোঁয় না।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_wppf_provisions', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('financial_year_id')->constrained('financial_years')->cascadeOnDelete();

            $table->date('period_from');
            $table->date('period_to');
            $table->decimal('net_profit', 18, 4);
            $table->decimal('wppf', 18, 4);
            $table->decimal('participation_fund', 18, 4);
            $table->decimal('welfare_fund', 18, 4);
            $table->decimal('foundation_fund', 18, 4);

            $table->string('status', 16)->default('draft');   // draft · posted
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();

            $table->timestamps();

            $table->unique(['company_id', 'financial_year_id'], 'hr_wppf_one_per_year');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_wppf_provisions');
    }
};
