<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ সীমা বাড়ানোর অনুরোধ — নিজের কাগজ (মালিকের উত্তর ৫, ১১ অক্টোবর ২০২৬; বাকি নিয়ন্ত্রণ ধাপ ২.৫)।
 *
 * ⓘ মালিক: *"হিসাব বা ম্যানেজার অনুরোধ লেখেন, আমি সই দিলে তবেই বাড়ে"*। আগে সীমা বাড়ানোর একমাত্র পথ গ্রাহকের ফর্ম —
 * অনুরোধ আর গ্রাহকের সারি একই কাগজ, তাই কে কী চেয়েছিলেন, কোন কারণে, কোন সংযুক্তিতে, আর না হলে কেন — কোথাও এক সারিতে
 * থাকত না; অস্থায়ী সীমা চাওয়ার জায়গাই ছিল না।
 * ⭐ এখন প্রতিটা অনুরোধ এক সারি: কার জন্য, তখনকার সীমা, চাওয়া সীমা (বা অস্থায়ী সীমা আর শেষ দিন), কারণ, কে চাইলেন, কোন
 * সইয়ের অনুরোধ, আর ফল। ⛔ মোছে না — না হওয়া বা মেয়াদ পেরোনো অনুরোধও থাকে।
 * ⓘ নতুন টেবিল — পুরনো কোনো সারি ছোঁয় না।
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('customer_credit_requests')) {
            return;
        }

        Schema::create('customer_credit_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('company_id')->constrained('companies', 'id', 'customer_credit_req_company_fk');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->foreignId('customer_id')->constrained('customers', 'id', 'customer_credit_req_customer_fk');

            // limit = সাধারণ সীমা · temporary = শেষ দিন পর্যন্ত অস্থায়ী সীমা
            $table->string('kind', 16);
            $table->decimal('current_limit', 18, 4);
            $table->decimal('requested_limit', 18, 4);
            $table->date('temp_until')->nullable();
            $table->string('reason', 500);

            // pending · approved · rejected · cancelled · expired · void
            $table->string('status', 16)->default('pending');
            $table->foreignId('approval_id')->nullable()->constrained('approvals', 'id', 'customer_credit_req_approval_fk')->nullOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users', 'id', 'customer_credit_req_user_fk')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->string('note', 255)->nullable();
            $table->timestamps();

            $table->index(['company_id', 'customer_id', 'status'], 'customer_credit_req_status_idx');
            $table->index(['company_id', 'status', 'created_at'], 'customer_credit_req_recent_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_credit_requests');
    }
};
