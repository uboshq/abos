<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⛔ চাকরি ছাড়া কর্মীর চূড়ান্ত পাওনার কোনো কাগজ ছিল না — বকেয়া মজুরি, না-নেওয়া অর্জিত ছুটির টাকা (BLA ধারা 11), গ্র্যাচুইটি
 * (ধারা 2(10)), ভবিষ্য তহবিল (ধারা 264–267) আর অগ্রিমের কর্তন আলাদা আলাদা হাতে গোনা হত (মালিক, ২১ অক্টোবর ২০২৬: "এক কাগজ,
 * মালিকের অনুমোদনে"; [[FinalSettlements]])।
 *
 * ⓘ প্রতিটা অঙ্ক আলাদা ঘরে, কাগজ বানানোর দিনে যেমন গোনা হলো — অনুমোদনের পরে খাতায় ঠিক এগুলোই বসে (পরে কাঠামো বদলালেও কাগজ বদলায়
 * না)। `details` = দিন, হার, বছর — দেখানোর জন্য। ⓘ online: নতুন টেবিল, কোনো পুরনো টেবিল বদলায় না। ফেরত: টেবিল মোছে।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_final_settlements', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();

            $table->date('last_day');
            $table->string('separation', 24);
            $table->string('note', 500)->nullable();

            $table->decimal('wages', 18, 4)->default(0);
            $table->decimal('leave_encashment', 18, 4)->default(0);
            $table->decimal('gratuity', 18, 4)->default(0);
            $table->decimal('provident_fund', 18, 4)->default(0);
            $table->decimal('advance_recovered', 18, 4)->default(0);
            $table->decimal('net', 18, 4)->default(0);
            $table->json('details')->nullable();

            $table->string('status', 16)->default('pending');   // pending · approved · rejected · withdrawn
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'status'], 'hr_final_settle_queue');
            $table->index(['employee_id', 'status'], 'hr_final_settle_emp');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_final_settlements');
    }
};
