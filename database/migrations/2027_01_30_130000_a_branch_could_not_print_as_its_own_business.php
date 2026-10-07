<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ শাখার নিজের ছাপার সেটিং — মালিকের নির্দেশ, ৩০ সেপ্টেম্বর ২০২৬: *"inv info, iNVOICE lOGO protiti branch ER JONNO
 * ALADA ALADA HOBE, PRINT TAMPLATE ALADA HOBE … KARON ALADA ALADA BRANCH E ALADA TYPE BUSINESS HOTEPARE"*।
 *
 * ⓘ আলাদা টেবিল, `settings`-এ `branch_id` নয়: ⛔ `settings`-এর অনন্য সূচক (company_id, key), আর তাতে শাখার সারি
 * বসাতে হলে সূচক বদলাতে হত — NULL-ওয়ালা অনন্য সূচক MariaDB-তে দুই সারি মেনে নেয়, আর কোম্পানির সারি নীরবে দুইটা হতে
 * পারত। এখানে প্রতিটা সারির শাখা আছেই, তাই (branch_id, key) সত্যিই অনন্য।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_settings', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->string('key', 191);
            $table->string('type', 20)->default('string');
            $table->text('value')->nullable();
            $table->timestamps();

            $table->unique(['branch_id', 'key']);
            $table->index('company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_settings');
    }
};
