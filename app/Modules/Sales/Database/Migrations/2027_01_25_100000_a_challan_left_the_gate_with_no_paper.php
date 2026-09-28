<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * গেট পাস — মাল গেট পেরোয় একটা কাগজ হাতে (মালিকের "Delivery Processing" নকশা, ২৮ সেপ্টেম্বর ২০২৬, রাত)।
 *
 * ⓘ গেট পাস জন্মায় **রওনার মুহূর্তে** — ট্রিপ বেরোলে বা হাতে "রওনা" বসালে
 * ([[GatePassService::issueFor()]])। এক রওনা, এক কাগজ: `delivery_event_id` অনন্য, তাই একই
 * রওনায় দুইবার ডাকলেও দ্বিতীয় কাগজ হয় না। পৌঁছায়নি থেকে পরদিন আবার রওনা মানে নতুন ঘটনা,
 * নতুন গেট পাস — দারোয়ান যেদিন যা দেখেন, সেদিনের কাগজ।
 *
 * ⓘ পণ্যের সারি নেই: নিশ্চিত চালানের সারি বদলায় না, তাই ছাপা চালানের সারি থেকেই আসে।
 * ⚠️ সূচকের নাম হাতে ছোট — MariaDB-তে ৬৪ অক্ষরের সীমা ([[NoIndexNameStandsAtTheEdgeTest]])।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sal_gate_passes', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()
                ->constrained('branches', indexName: 'sal_gp_branch_fk')->nullOnDelete();

            $table->string('document_no', 32);

            $table->foreignId('delivery_challan_id')
                ->constrained('sal_challans', indexName: 'sal_gp_challan_fk')->restrictOnDelete();
            $table->foreignId('delivery_event_id')
                ->constrained('sal_delivery_events', indexName: 'sal_gp_event_fk')->restrictOnDelete();
            $table->foreignId('shipment_id')->nullable()
                ->constrained('sal_shipments', indexName: 'sal_gp_shipment_fk')->nullOnDelete();

            // ⓘ গেটে যা দেখা হয় — রওনার মুহূর্তের ছবি, পরে চালান বা ট্রিপ বদলালেও কাগজ বদলায় না
            $table->string('vehicle_no', 64)->nullable();
            $table->string('driver_name', 191)->nullable();
            $table->string('driver_phone', 32)->nullable();

            $table->foreignId('issued_by')->nullable()
                ->constrained('users', indexName: 'sal_gp_issuer_fk')->nullOnDelete();
            $table->timestamp('issued_at');

            $table->string('status', 16)->default('issued');
            $table->string('cancel_reason', 500)->nullable();
            $table->foreignId('cancelled_by')->nullable()
                ->constrained('users', indexName: 'sal_gp_canceller_fk')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();

            $table->timestamps();

            // ⭐ এক রওনা, এক গেট পাস — ডাটাবেসেই, দুইজন একসাথে ডাকলেও
            $table->unique('delivery_event_id', 'sal_gp_event_uq');
            $table->unique(['company_id', 'document_no'], 'sal_gp_number_uq');
            $table->index(['company_id', 'issued_at'], 'sal_gp_issued_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sal_gate_passes');
    }
};
