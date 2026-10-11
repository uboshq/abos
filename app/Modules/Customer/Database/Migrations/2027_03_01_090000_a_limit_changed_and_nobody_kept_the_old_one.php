<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ বাকির সীমা বদলের ইতিহাস — বাকি নিয়ন্ত্রণ ধাপ ১.৪ (docs/cloud-tasks/credit-control.md, ১১ অক্টোবর ২০২৬)।
 *
 * ⛔ আগে সীমা বা বাকির দিন বদলালে কেবল সাধারণ নিরীক্ষার খাতায় একটা ঘর-বদল বসত, অন্য শত বদলের ভিড়ে; আর কোন সইয়ে
 * বদলাল, কেন — সেটা কোথাও এক সারিতে ছিল না।
 *
 * ⭐ এখন প্রতিটা বদল এক সারি: কে, কখন, আগে, পরে, কারণ, আর কোন সইয়ের অনুরোধে। কেবল যোগের ([[CustomerCreditChange]])।
 * ⓘ নতুন টেবিল — পুরনো কোনো সারি ছোঁয় না; আজকের সীমাগুলোর "আগের ইতিহাস" নেই, প্রথম বদল থেকে শুরু।
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('customer_credit_changes')) {
            return;
        }

        Schema::create('customer_credit_changes', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('company_id')->constrained('companies', 'id', 'customer_credit_chg_company_fk');
            $table->foreignId('customer_id')->constrained('customers', 'id', 'customer_credit_chg_customer_fk');

            // কোন ঘর: credit_limit · credit_days (পরে অস্থায়ী সীমা)
            $table->string('field', 40);
            $table->string('old_value', 40)->nullable();
            $table->string('new_value', 40)->nullable();
            $table->string('reason', 500)->nullable();
            $table->foreignId('approval_id')->nullable()->constrained('approvals', 'id', 'customer_credit_chg_approval_fk')->nullOnDelete();
            $table->foreignId('changed_by')->nullable()->constrained('users', 'id', 'customer_credit_chg_user_fk')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['company_id', 'customer_id', 'created_at'], 'customer_credit_chg_customer_idx');
            $table->index(['company_id', 'created_at'], 'customer_credit_chg_recent_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_credit_changes');
    }
};
