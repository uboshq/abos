<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * ⭐ পোর্টাল থেকে নামানো কাগজের খাতায় "কোন গ্রাহক" — গ্রাহক পোর্টাল, ধাপ ১, ১১ অক্টোবর ২০২৬।
 *
 * ── কেন একটা কলাম ───────────────────────────────────────────────────────
 * মালিকের নিয়ম: গ্রাহক যে ফাইলই নামান (বিলের PDF, বিবরণী) — কে, কী, কখন খাতায় থাকবে। `doc_deliveries` আগে থেকেই
 * "কী" আর "কখন" রাখে, কিন্তু "কে" কেবল কর্মী (`created_by` → users)। ⛔ পোর্টালের গ্রাহক কর্মী নন, তাই সেই ঘরটা খালি
 * থাকত আর সারিটা পড়ত "কেউ একজন নামিয়েছে"।
 *
 * ⓘ পুরনো সারি ছোঁয়া হয় না — কলামটা খালি দিয়ে শুরু, আর গ্রাহক মুছলে সারিটা থাকে (খাতা হারায় না), কেবল নামটা খালি হয়।
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('doc_deliveries') || Schema::hasColumn('doc_deliveries', 'customer_id')) {
            return;
        }

        Schema::table('doc_deliveries', function (Blueprint $table): void {
            $table->foreignId('customer_id')->nullable()->after('created_by')
                ->constrained('customers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('doc_deliveries') || ! Schema::hasColumn('doc_deliveries', 'customer_id')) {
            return;
        }

        Schema::table('doc_deliveries', function (Blueprint $table): void {
            // ⚠️ আগে বিদেশি চাবি, তারপর কলাম — MySQL চাবির সূচিটা ছাড়ে না
            $table->dropForeign(['customer_id']);
            $table->dropColumn('customer_id');
        });
    }
};
