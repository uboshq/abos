<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ বীমার দাবির খাতা — অর্থ-মডিউলের পরিকল্পনা ৬.৪, ৬ অক্টোবর ২০২৬ (সমন্বয়কের উত্তর প্র৩: দাবি খাতায় ওঠে কেবল টাকা এলে
 * বা লিখিত অনুমোদনে — IAS 37; জমা দেওয়া দাবি তালিকায় থাকে)।
 *
 * ⓘ একটা দাবি = এক পলিসির এক ঘটনা: কবে ঘটল, কবে দাবি, কত চাওয়া, কত অনুমোদন (চিঠির নম্বরসহ), কত এল, অবস্থা
 * ([[InsuranceClaim]], [[InsuranceClaimService]])। টাকা খাতায় থাকে ভাউচারে; এখানে কেবল কোন ভাউচার।
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('fin_insurance_claims')) {
            return;
        }

        Schema::create('fin_insurance_claims', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('policy_id')->constrained('fin_insurance_policies')->cascadeOnDelete();
            $table->string('claim_no', 64)->nullable();
            $table->date('incident_on');
            $table->date('claimed_on');
            $table->string('incident', 500);
            $table->decimal('claimed_amount', 18, 4);
            $table->decimal('approved_amount', 18, 4)->nullable();
            $table->date('approved_on')->nullable();
            $table->string('approval_ref', 100)->nullable();
            $table->foreignId('approval_voucher_id')->nullable()->constrained('vouchers')->nullOnDelete();
            $table->decimal('received_amount', 18, 4)->default(0);
            $table->date('received_on')->nullable();
            $table->string('status', 16)->default('lodged');
            $table->date('closed_on')->nullable();
            $table->string('close_note', 500)->nullable();
            $table->foreignId('close_voucher_id')->nullable()->constrained('vouchers')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['policy_id', 'claimed_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_insurance_claims');
    }
};
