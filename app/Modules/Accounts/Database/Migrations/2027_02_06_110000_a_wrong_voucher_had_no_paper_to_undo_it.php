<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * উল্টো কাগজ — পাকা ভাউচার আর নোটের (মালিকের সংস্করণ ২, ৪ অক্টোবর ২০২৬: পাকা কাগজ বদলায় না, ভুল শোধরায় উল্টো কাগজে)।
 *
 * ⓘ নিজের নম্বরে (REV-xxxx) আর মূল কাগজের সূত্রসহ; মূলটা থাকে, "বাতিল" হয়ে। এক কাগজের একটাই উল্টো কাগজ।
 * ⓘ খাতার উল্টো সারিগুলো এই নম্বরে বসে ([[AccountsReversalService]]) — খতিয়ানে দেখা যায় কোন কাগজ কী উল্টাল।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acc_reversals', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies', 'id', 'acc_rev_company_fk')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches', 'id', 'acc_rev_branch_fk')->nullOnDelete();

            // ⓘ কোন কাগজ উল্টাল — `voucher` বা `note`
            $table->string('reversible_type', 16);
            $table->unsignedBigInteger('reversible_id');
            $table->string('reversed_no', 64);

            $table->string('document_no', 64);
            $table->date('trx_date');
            $table->string('reason', 500);
            $table->decimal('amount', 18, 4)->default(0);

            $table->foreignId('created_by')->nullable()->constrained('users', 'id', 'acc_rev_creator_fk')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'document_no'], 'acc_rev_number_once');
            $table->unique(['reversible_type', 'reversible_id'], 'acc_rev_one_per_paper');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acc_reversals');
    }
};
