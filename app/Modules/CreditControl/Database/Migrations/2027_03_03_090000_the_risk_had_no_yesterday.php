<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ ঝুঁকির মাত্রার দিনের ছবি — "ঝুঁকির ধারা" রিপোর্টের জন্য (বাকি নিয়ন্ত্রণ ধাপ ৩.২–৩.৪, ১২ অক্টোবর ২০২৬)।
 *
 * ⓘ মাত্রা প্রতিবার নতুন করে গোনা হয় ([[CreditRisk]]) — গতকালের মাত্রা কোথাও থাকত না, তাই "ঝুঁকি বাড়ছে না কমছে" বলা যেত না।
 * ⭐ রোজ সকালে একবার প্রতিটা সক্রিয় গ্রাহকের মাত্রা আর নম্বর এক সারি (`abos:credit-due`); একই দিনে আবার চললে সেই সারিই বদলায়।
 * ⓘ নতুন টেবিল — পুরনো কোনো সারি ছোঁয় না।
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('credit_risk_snapshots')) {
            return;
        }

        Schema::create('credit_risk_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies', 'id', 'credit_risk_snap_company_fk');
            $table->foreignId('customer_id')->constrained('customers', 'id', 'credit_risk_snap_customer_fk');
            $table->date('taken_on');
            $table->string('level', 10);
            $table->unsignedSmallInteger('score');
            $table->timestamp('created_at')->nullable();

            $table->unique(['company_id', 'customer_id', 'taken_on'], 'credit_risk_snap_day_uq');
            $table->index(['company_id', 'taken_on'], 'credit_risk_snap_day_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_risk_snapshots');
    }
};
