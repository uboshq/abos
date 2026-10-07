<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * চালানে বহরের গাড়িটার দিকে সত্যিকারের যোগ।
 *
 * ── কেন লেখা নম্বরটাও থাকে ───────────────────────────────────────────
 * vehicle_no ঘরটা মোছা হয়নি, আর সেটা ইচ্ছাকৃত। বহরের বাইরের গাড়িতেও
 * মাল যায় — ভাড়ার ট্রাক, প্রতিবেশীর পিকআপ। শুধু FK রাখলে ওই চালানটা
 * লেখাই যেত না, আর তখন মানুষ যেকোনো একটা গাড়ি বেছে নিত শুধু ফর্মটা
 * পার করতে — যা কাগজে মিথ্যা নম্বর ছাপত।
 *
 * তাই দুইটাই: বহরের গাড়ি হলে FK (রিপোর্ট গাড়ি ধরে যোগ করতে পারে),
 * আর বাইরের গাড়ি হলে শুধু লেখা নম্বর।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sal_challans', function (Blueprint $table) {
            $table->foreignId('vehicle_id')->nullable()->after('warehouse_id')
                ->constrained('mdm_vehicles')->nullOnDelete();

            // "এই গাড়িটা এ মাসে কয়টা চালানে গেল" — এই প্রশ্নটাই
            // গাড়ির তালিকা রাখার কারণ
            $table->index(['company_id', 'vehicle_id', 'trx_date']);
        });
    }

    public function down(): void
    {
        Schema::table('sal_challans', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'vehicle_id', 'trx_date']);
            $table->dropConstrainedForeignId('vehicle_id');
        });
    }
};
