<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⛔ সরবরাহকারীর ব্যাংক তথ্য একজনের হাতেই বদলাত — নকশা দফা #৬ (মালিক অনুমোদিত, ২১ অক্টোবর ২০২৬; SOX/ITGC দ্বৈত নিয়ন্ত্রণ)।
 *
 * ⓘ সরবরাহকারীর টাকা কোন হিসাবে যাবে, সেটা এখন সরবরাহকারীর নিজের রেকর্ডে, আর প্রতিটা বদল একটা আলাদা সারি:
 *   pending    — চাওয়া হয়েছে, দ্বিতীয় সইয়ের অপেক্ষায়; চালু তথ্য বদলায় না
 *   signed     — অন্য একজন সই দিয়েছেন; এটাই চালু (প্রতি সরবরাহকারীর একটাই)
 *   superseded — একদিন সই-পাওয়া, পরে নতুন সই-পাওয়া সারি এর জায়গা নিয়েছে
 *   rejected · withdrawn — না হওয়া বদল; থাকে, মোছে না
 *
 * ⚠️ `account_no` ঢাকা (Laravel `encrypted`) — ডাম্পে পড়া যায় না, নিরীক্ষার খাতায় কেবল "ঢাকা"। পর্দার জন্য শেষ চার অঙ্ক
 * আলাদা ঘরে (`account_last4`)। ⓘ চালু সারি "একটাই" — নিয়মটা সই দেওয়ার মুহূর্তে সরবরাহকারীর সারিতে তালা দিয়ে রাখা হয়
 * ([[SupplierBank::sign()]]); ডাটাবেজের unique দিয়ে বলা যায় না, কারণ পুরনো সই-পাওয়া সারিও থাকে।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sup_bank_details', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();

            $table->string('bank_name', 120);
            $table->string('branch_name', 120)->nullable();
            $table->string('account_name', 160);
            $table->text('account_no');                       // ⚠️ ঢাকা
            $table->string('account_last4', 4);
            $table->string('routing_no', 20)->nullable();

            $table->string('status', 16)->default('pending');   // pending · signed · superseded · rejected · withdrawn
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('signed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('signed_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('reason', 500)->nullable();
            $table->string('decision_note', 500)->nullable();

            $table->timestamps();

            $table->index(['company_id', 'status'], 'sup_bank_queue');
            $table->index(['supplier_id', 'status'], 'sup_bank_supplier');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sup_bank_details');
    }
};
