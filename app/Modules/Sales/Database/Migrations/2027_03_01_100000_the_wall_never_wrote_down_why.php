<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ দেয়ালের সাক্ষ্য — বাকি নিয়ন্ত্রণ ধাপ ১.৫ (docs/cloud-tasks/credit-control.md, ১১ অক্টোবর ২০২৬)।
 *
 * ⛔ আগে দেয়াল ([[CreditExposure::assertRoom()]]) "হ্যাঁ" বা "না" বলে চুপ করত: পরে কেউ জিজ্ঞেস করলে "এই বিলটা কেন আটকাল,
 * ওটা কেন গেল" — উত্তর ছিল না, কারণ সেই মুহূর্তের বকেয়া, আটকে থাকা টাকা আর সীমা পরে বদলে যায়।
 *
 * ⭐ এখন প্রতিটা সিদ্ধান্তে (DO, চালান, বিল, কাউন্টার) একটা সারি: দেয়াল কী দেখেছিল আর কী বলেছিল। কেবল যোগের — সারি বদলায়
 * না, মোছে না ([[CreditDecision]])।
 *
 * ⓘ অঙ্কের ঘরগুলো nullable: বাকির সীমার সুইচ বন্ধ থাকলে দেয়াল কিছুই মাপে না, তবু "কেন গেল" — সুইচ বন্ধ — লেখা থাকে।
 * ⓘ নতুন টেবিল, পুরনো কোনো সারি ছোঁয় না।
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sal_credit_decisions')) {
            return;
        }

        Schema::create('sal_credit_decisions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('company_id')->constrained('companies', 'id', 'sal_credit_dec_company_fk');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->foreignId('customer_id')->constrained('customers', 'id', 'sal_credit_dec_customer_fk');

            // কোথায়: delivery_order · challan · invoice · counter
            $table->string('stage', 20);
            $table->string('paper_type', 40)->nullable();
            $table->unsignedBigInteger('paper_id')->nullable();
            $table->string('paper_no', 60)->nullable();

            // কী দেখেছিল
            $table->decimal('credit_limit', 18, 4)->nullable();
            $table->decimal('due', 18, 4)->nullable();
            $table->decimal('held', 18, 4)->nullable();
            $table->decimal('adding', 18, 4)->nullable();
            $table->decimal('paying_now', 18, 4)->nullable();
            $table->decimal('exposure_after', 18, 4)->nullable();

            // কী বলেছিল: pass · stop, আর কেন
            $table->string('result', 10);
            $table->string('reason', 30);
            $table->string('message', 500)->nullable();

            $table->foreignId('user_id')->nullable()->constrained('users', 'id', 'sal_credit_dec_user_fk')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['company_id', 'customer_id', 'created_at'], 'sal_credit_dec_customer_idx');
            $table->index(['company_id', 'result', 'created_at'], 'sal_credit_dec_result_idx');
            $table->index(['paper_type', 'paper_id'], 'sal_credit_dec_paper_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sal_credit_decisions');
    }
};
