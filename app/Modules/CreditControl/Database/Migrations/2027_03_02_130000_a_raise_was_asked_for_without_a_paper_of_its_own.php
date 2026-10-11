<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ সীমা বাড়ানোর অনুরোধ — নিজের কাগজ (বাকি নিয়ন্ত্রণ ধাপ ২.৫, মালিকের উত্তর ৫, ১১ অক্টোবর ২০২৬)।
 *
 * ⓘ মালিক: *"হিসাবরক্ষক বা ম্যানেজার অনুরোধ লিখবেন; সীমা বসবে কেবল আমি সই দিলে"*। আগে "অনুরোধ" ছিল গ্রাহকের সম্পাদনার
 * ফর্মে একটা বড় সংখ্যা লেখা — না কারণের ঘর, না কাগজ, না অস্থায়ী সীমা, আর "না" হলে কিছুই থাকত না।
 *
 * ⭐ এখন প্রতিটা অনুরোধ এক সারি: কার, এখন কত, কত চাই (বা অস্থায়ী কত, কবে পর্যন্ত), কেন, কে লিখলেন, কোন সইয়ে, কী হলো।
 * ⛔ সারি কখনো মোছে না — "না" বা মেয়াদ ফুরানো অনুরোধও থাকে ([[CreditLimitRequest]])।
 * ⓘ নতুন টেবিল — কোনো চলতি সারি ছোঁয় না।
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cc_limit_requests')) {
            return;
        }

        Schema::create('cc_limit_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('company_id')->constrained('companies', 'id', 'cc_limit_req_company_fk');
            $table->foreignId('branch_id')->nullable()->constrained('branches', 'id', 'cc_limit_req_branch_fk')->nullOnDelete();
            $table->foreignId('customer_id')->constrained('customers', 'id', 'cc_limit_req_customer_fk');

            // limit = সাধারণ সীমা বাড়ানো · temporary = শেষ দিনসহ অস্থায়ী সীমা
            $table->string('kind', 12);
            $table->decimal('current_limit', 18, 4);
            $table->decimal('requested_limit', 18, 4);
            $table->date('temporary_until')->nullable();
            $table->string('reason', 500);

            // pending · approved · rejected · expired
            $table->string('status', 12)->default('pending');
            $table->foreignId('approval_id')->nullable()->constrained('approvals', 'id', 'cc_limit_req_approval_fk')->nullOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users', 'id', 'cc_limit_req_requester_fk')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users', 'id', 'cc_limit_req_decider_fk')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status', 'created_at'], 'cc_limit_req_status_idx');
            $table->index(['company_id', 'customer_id', 'created_at'], 'cc_limit_req_customer_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cc_limit_requests');
    }
};
