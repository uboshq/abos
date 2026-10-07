<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * নতুন দোকানের খোঁজ লেখার কোনো জায়গা ছিল না — NEXUS স্পেক §৭।
 *
 * ── ⓘ কী হচ্ছিল ────────────────────────────────────────────────────────
 * মাঠের বিক্রয়কর্মী নতুন একটা দোকান পান, মালিকের সাথে কথা বলেন, তিনবার
 * যান। ⚠️ এর কিছুই খাতায় থাকত না — দোকানটা গ্রাহক হওয়ার আগ পর্যন্ত
 * ERP-র চোখে তার অস্তিত্বই নেই। ⛔ বিক্রয়কর্মী বদলালে খোঁজটাও তাঁর
 * সাথে চলে যেত।
 *
 * ── চারটা টেবিল ────────────────────────────────────────────────────────
 *   sal_leads               — সম্ভাব্য গ্রাহক (এখনো গ্রাহক নন)
 *   sal_opportunity_stages  — ধাপের তালিকা, কোম্পানি নিজে সাজায়
 *   sal_opportunities       — একটা সম্ভাব্য বিক্রয়, অঙ্ক ও সম্ভাবনাসহ
 *   sal_opportunity_lines   — কোন পণ্য, কত
 *
 * ⚠️ `sales_quotation_id`-এ বিদেশি চাবি নেই, ইচ্ছাকৃত: কোটেশনের টেবিল
 * আরেকটা কাজে একই সময়ে তৈরি হচ্ছে। ⛔ চাবি বসালে এই মাইগ্রেশন ঐ
 * টেবিলের আগে চললে ভাঙত। কোটেশন বসলে আলাদা মাইগ্রেশনে চাবিটা বসবে।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sal_leads', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('document_no', 64);

            // দোকান বা প্রতিষ্ঠানের নাম — যে ভাষায় লেখা হয়, সেভাবেই
            $table->string('name', 191);
            $table->string('contact_person', 191)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('address', 500)->nullable();
            $table->foreignId('location_id')->nullable()->constrained('mdm_locations')->nullOnDelete();

            // field_visit · referral · phone_call · walk_in · other
            $table->string('source', 32);

            /*
             * কার খোঁজ — এই ঘরটাই দেখার দেয়াল।
             *
             * ⓘ মালিকের নিয়ম (২৬ সেপ্টেম্বর ২০২৬): বিক্রয়কর্মী কেবল নিজেরটা
             * দেখেন। কর্মী মুছে গেলে সারিটা থাকে, মালিকহীন — তখন কেবল
             * সবার-দেখার চাবিধারী দেখেন, আর নতুন কাউকে দিতে পারেন।
             */
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();

            // new · contacted · qualified · lost · converted
            $table->string('status', 16)->default('new');
            $table->string('lost_reason', 500)->nullable();
            $table->text('notes')->nullable();

            // গ্রাহক হওয়ার পর — কোন গ্রাহক, কবে, কে করলেন
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->timestamp('converted_at')->nullable();
            $table->foreignId('converted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'document_no']);
            $table->index(['company_id', 'owner_user_id', 'status'], 'sal_leads_company_owner_status_idx');
        });

        Schema::create('sal_opportunity_stages', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('code', 16);
            $table->string('name_en', 120);
            $table->string('name_bn', 120)->nullable();

            // এই ধাপে এলে সম্ভাবনা কত ধরা হবে — নতুন সুযোগে আপনা থেকে বসে
            $table->unsignedTinyInteger('probability')->default(0);
            $table->unsignedSmallInteger('sort_order')->default(0);

            // জেতা বা হারা — দুইটার একটা, অথবা কোনোটাই নয় (চলমান)
            $table->boolean('is_won')->default(false);
            $table->boolean('is_lost')->default(false);

            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code']);
        });

        Schema::create('sal_opportunities', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('document_no', 64);
            $table->string('title', 191);

            // গ্রাহক অথবা লিড — ঠিক একটা (সার্ভিস দেখে)
            $table->foreignId('customer_id')->nullable()->constrained('customers')->restrictOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained('sal_leads')->restrictOnDelete();

            $table->foreignId('salesperson_user_id')->nullable()->constrained('users')->nullOnDelete();

            // ⚠️ restrict — ব্যবহৃত ধাপ মুছলে সুযোগগুলো ধাপহীন হত
            $table->foreignId('stage_id')->constrained('sal_opportunity_stages')->restrictOnDelete();

            // সারিগুলোর যোগফল — সার্ভিস গোনে, bcmath-এ
            $table->decimal('estimated_value', 18, 4)->default(0);
            $table->unsignedTinyInteger('probability')->default(0);
            $table->date('expected_close_date')->nullable();

            $table->string('competitor', 191)->nullable();
            $table->text('remarks')->nullable();

            // জেতার পর কোটেশন — চাবি ছাড়া, কারণ উপরে লেখা
            $table->unsignedBigInteger('sales_quotation_id')->nullable()->index();

            $table->timestamp('closed_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'document_no']);
            // নাম হাতে — নিজে থেকে বানালে ৬৩ অক্ষর, ৬০-এর দেয়ালের বাইরে
            $table->index(['company_id', 'salesperson_user_id', 'stage_id'], 'sal_opp_company_seller_stage_idx');
        });

        Schema::create('sal_opportunity_lines', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('opportunity_id')->constrained('sal_opportunities')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('inv_products')->restrictOnDelete();

            $table->decimal('qty', 18, 4);
            $table->decimal('value', 18, 4);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sal_opportunity_lines');
        Schema::dropIfExists('sal_opportunities');
        Schema::dropIfExists('sal_opportunity_stages');
        Schema::dropIfExists('sal_leads');
    }
};
