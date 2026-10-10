<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ সরেজমিন গোনা আর দায়িত্ব — স্থায়ী সম্পদ ধাপ ৪ (মালিক, ১০ অক্টোবর ২০২৬)।
 *
 * ⓘ খাতায় ফ্রিজ আছে, দোকানে আছে কি না কেউ দেখত না। কেবল যোগ:
 *   · `acc_asset_verifications` — শাখা ধরে একটা গোনার অভিযান (FAV-…); শাখায় একসাথে একটাই খোলা।
 *   · `acc_asset_verification_lines` — অভিযানের শুরুতে শাখার প্রতিটা সম্পদের একটা সারি, কোথায় কার কাছে থাকার কথা সেটাসহ;
 *     তারপর পাওয়া গেল / গেল না / ভাঙা / ভুল জায়গায়। (অভিযান, সম্পদ) অনন্য।
 *   · `acc_asset_acknowledgements` — দায়িত্বে থাকা কর্মী "বুঝে নিয়েছি" বললেন, কবে, কোন অবস্থায়।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acc_asset_verifications', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->string('document_no', 40);
            $table->string('title', 191)->nullable();
            $table->date('started_on');
            $table->date('closed_on')->nullable();
            $table->string('status', 16);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'document_no'], 'acc_asset_verify_no');
            $table->index(['company_id', 'branch_id', 'status'], 'acc_asset_verify_open');
        });

        Schema::create('acc_asset_verification_lines', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('verification_id')->constrained('acc_asset_verifications')->cascadeOnDelete();
            $table->foreignId('fixed_asset_id')->constrained('acc_fixed_assets')->cascadeOnDelete();
            // ⓘ অভিযানের শুরুতে কোথায় আর কার কাছে থাকার কথা — পরে সম্পদ সরলেও গোনার দিনের ছবি বদলায় না
            $table->string('expected_location', 120)->nullable();
            $table->unsignedBigInteger('expected_custodian_id')->nullable();
            $table->string('result', 20)->nullable();
            $table->string('found_location', 120)->nullable();
            $table->string('note', 500)->nullable();
            $table->foreignId('checked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();

            $table->unique(['verification_id', 'fixed_asset_id'], 'acc_asset_check_once');
        });

        Schema::create('acc_asset_acknowledgements', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('fixed_asset_id')->constrained('acc_fixed_assets')->cascadeOnDelete();
            // ⓘ কর্মী HR-এর — কোর পক্ষের তালিকা দিয়ে চেনা, অন্য মডিউলের টেবিলে বিদেশি চাবি নয়
            $table->unsignedBigInteger('employee_id');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acknowledged_at');
            $table->string('condition', 20);
            $table->string('note', 500)->nullable();
            $table->timestamps();

            $table->index(['fixed_asset_id', 'employee_id'], 'acc_asset_ack_who');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acc_asset_acknowledgements');
        Schema::dropIfExists('acc_asset_verification_lines');
        Schema::dropIfExists('acc_asset_verifications');
    }
};
