<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ সম্পদের জীবনের ঘটনা — স্থায়ী সম্পদ ধাপ ৩ (মালিক, ১০ অক্টোবর ২০২৬; IAS 16.12-14, 16.31-42, IAS 36)।
 *
 * ⓘ খাতা এতদিন কেবল জন্ম (নিবন্ধন) আর মৃত্যু (বিক্রি) দেখত। মাঝের সংযোজন, মেরামত, পুনর্মূল্যায়ন আর দাম পড়ে যাওয়া
 * কোথাও বসত না। কেবল যোগ:
 *   · `acc_asset_events` — প্রতিটা ঘটনার নিজের কাগজ (FAE-…)। ⚠️ সই এই সারির উপর, সম্পদের উপর নয়: একই সম্পদে দুইবার
 *     একই অঙ্কের মেরামত হলে সম্পদের পুরনো সই দ্বিতীয়টাকে পাশ করিয়ে দিত।
 *     `cost_change` আর `accumulated_change` — সম্পদের দাম আর সঞ্চিত ক্ষয় কতটা বদলাল; পাকা ঘটনার যোগফলই সম্পদের হিসাবে ঢোকে।
 *   · `acc_fixed_assets.disposal_reason` — বাতিল বা হারানোর কারণ।
 *   · `acc_asset_transfers` — কর্মী, জায়গা আর বিভাগের বদলও এখন স্থানান্তর (আগে কেবল শাখা)।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acc_asset_events', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('fixed_asset_id')->constrained('acc_fixed_assets')->cascadeOnDelete();
            $table->string('kind', 20);
            $table->string('document_no', 40);
            $table->date('happened_on');

            // ⓘ ঘটনার মূল অঙ্ক: সংযোজন/মেরামতের খরচ, পুনর্মূল্যায়নের ন্যায্য দাম, দাম পড়ার বেলায় ফেরতযোগ্য দাম
            $table->decimal('amount', 18, 4);
            $table->decimal('cost_change', 18, 4)->default(0);
            $table->decimal('accumulated_change', 18, 4)->default(0);
            // ⓘ লাভ-ক্ষতি বা উদ্বৃত্তে কত গেল (দাম পড়ার লোকসান, পুনর্মূল্যায়নের উদ্বৃত্ত)
            $table->decimal('effect', 18, 4)->default(0);
            $table->decimal('surplus_change', 18, 4)->default(0);

            // ⓘ উল্টো দিকের খাত — টাকা/পাওনার খাত, বা পুনর্মূল্যায়নের উদ্বৃত্তের খাত
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();
            // ⓘ মেরামতের খরচের খাত
            $table->foreignId('charge_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            // ⓘ বিক্রেতা — কোর পক্ষের তালিকা থেকে ([[PartyRegistry]]); অন্য মডিউলের টেবিলে বিদেশি চাবি নয়
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->unsignedSmallInteger('extend_months')->nullable();
            $table->string('reason', 500)->nullable();
            $table->string('status', 16);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'document_no'], 'acc_asset_event_no');
            $table->index(['fixed_asset_id', 'kind', 'happened_on'], 'acc_asset_event_when');
        });

        // ⓘ কেন বাতিল বা হারানো — ভাঙারি, চুরি, আগুন; বিক্রিতে ঐচ্ছিক
        Schema::table('acc_fixed_assets', function (Blueprint $table): void {
            $table->string('disposal_reason', 500)->nullable()->after('disposal_amount');
        });

        Schema::table('acc_asset_transfers', function (Blueprint $table): void {
            $table->unsignedBigInteger('from_custodian_id')->nullable()->after('to_branch_id');
            $table->unsignedBigInteger('to_custodian_id')->nullable()->after('from_custodian_id');
            $table->string('from_location', 120)->nullable()->after('to_custodian_id');
            $table->string('to_location', 120)->nullable()->after('from_location');
            $table->string('from_department', 120)->nullable()->after('to_location');
            $table->string('to_department', 120)->nullable()->after('from_department');
        });
    }

    public function down(): void
    {
        Schema::table('acc_asset_transfers', function (Blueprint $table): void {
            $table->dropColumn(['from_custodian_id', 'to_custodian_id', 'from_location', 'to_location', 'from_department', 'to_department']);
        });

        Schema::table('acc_fixed_assets', function (Blueprint $table): void {
            $table->dropColumn('disposal_reason');
        });

        Schema::dropIfExists('acc_asset_events');
    }
};
