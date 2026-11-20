<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ সরবরাহকারীর বাকির সীমা বাড়ানোর অনুরোধ — নিজের কাগজ (মালিক, ১৮ নভেম্বর ২০২৬: সরবরাহকারীর বাকির দেয়াল)।
 *
 * ⓘ সীমা পেরোলে নতুন বিল বা মাল গ্রহণ পাকা হয় না ([[SupplierCreditWall]]); খোলার দুই পথ — টাকা দেওয়া, বা সীমা বাড়ানো। বাড়ানো
 * সইয়ে যায় (`supplier.credit_limit` ছক), শেষ সই পড়লে নিজে বসে। গ্রাহকের [[customer_credit_requests]]-এর ছাঁচ।
 * ⛔ মোছে না — না হওয়া, ফিরিয়ে নেওয়া বা বাতিল অনুরোধও থাকে।
 * ⓘ নতুন টেবিল — পুরনো কোনো সারি ছোঁয় না।
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('supplier_credit_requests')) {
            return;
        }

        Schema::create('supplier_credit_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('company_id')->constrained('companies', 'id', 'supplier_credit_req_company_fk');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->foreignId('supplier_id')->constrained('suppliers', 'id', 'supplier_credit_req_supplier_fk');

            $table->decimal('current_limit', 18, 4);
            $table->decimal('requested_limit', 18, 4);
            $table->string('reason', 500);

            // pending · approved · rejected · void
            $table->string('status', 16)->default('pending');
            $table->foreignId('approval_id')->nullable()->constrained('approvals', 'id', 'supplier_credit_req_approval_fk')->nullOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users', 'id', 'supplier_credit_req_user_fk')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->string('note', 255)->nullable();
            $table->timestamps();

            $table->index(['company_id', 'supplier_id', 'status'], 'supplier_credit_req_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_credit_requests');
    }
};
