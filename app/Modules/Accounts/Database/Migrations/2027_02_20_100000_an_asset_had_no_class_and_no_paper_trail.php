<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ স্থায়ী সম্পদের খাতা, আন্তর্জাতিক মানে — ধাপ ১: শ্রেণি আর নিবন্ধনের ঘর (মালিক, ১০ অক্টোবর ২০২৬; IAS 16)।
 *
 * ⓘ কেবল যোগ — পুরনো সম্পদ, তার দাখিলা আর অবচয় হুবহু থাকে:
 *   · `acc_asset_categories` — শ্রেণি: পাঁচটা খাতের জোড়া (দাম, সঞ্চিত অবচয়, অবচয় খরচ, বিক্রির লাভ/লোকসান, অবমূল্যায়ন),
 *     আর ডিফল্ট পদ্ধতি, আয়ু, শেষ-দামের হার; শ্রেণির নিজের মূলধনীকরণ সীমা (খালি = কোম্পানির সেটিং)।
 *   · `acc_fixed_assets`-এ নতুন ঘর — শ্রেণি, মূল সম্পদ (অংশ), জায়গা, বিভাগ, দায়িত্বে থাকা কর্মী, বিক্রেতা, ক্রয় বিলের সারি,
 *     ব্যবহার শুরুর তারিখ, সিরিয়াল/মডেল, ওয়ারেন্টি, বীমার নম্বর আর মেয়াদ। সবই খালি হতে পারে — পুরনো সারি অক্ষত।
 *   · `acc_asset_cost_parts` — কেনা দামের ভাগ (দাম, পরিবহন, বসানো, শুল্ক…); যোগফল = সম্পদের দাম (IAS 16.16)।
 * ⚠️ সূচির নাম ছোট — ৬৪ অক্ষরের সীমা ([[NoIndexNameStandsAtTheEdgeTest]])।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acc_asset_categories', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('code', 32);
            $table->string('name_en', 120);
            $table->string('name_bn', 120)->nullable();

            /* পাঁচটা খাত — ছক থেকে বাছা, পোস্টযোগ্য আর ঠিক ধরনের ([[AssetCategoryService]]) */
            $table->foreignId('asset_account_id')->constrained('accounts');
            $table->foreignId('accumulated_account_id')->constrained('accounts');
            $table->foreignId('expense_account_id')->constrained('accounts');
            $table->foreignId('gain_account_id')->nullable()->constrained('accounts');
            $table->foreignId('loss_account_id')->nullable()->constrained('accounts');
            $table->foreignId('impairment_account_id')->nullable()->constrained('accounts');

            $table->string('method', 16)->default('straight');
            $table->unsignedSmallInteger('life_months')->nullable();
            $table->decimal('rate', 8, 4)->nullable();
            $table->decimal('residual_percent', 8, 4)->default(0);

            /* খালি = কোম্পানির সেটিং (`accounts.asset.capitalisation_threshold`) */
            $table->decimal('capitalisation_threshold', 18, 4)->nullable();

            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code'], 'acc_asset_cat_code');
        });

        Schema::table('acc_fixed_assets', function (Blueprint $table): void {
            $table->foreignId('category_id')->nullable()->after('branch_id')
                ->constrained('acc_asset_categories')->nullOnDelete();

            /* ⓘ অংশ — বড় যন্ত্রের মোটর, গাড়ির ব্যাটারি (IAS 16.43); মূলটা একই কোম্পানির */
            $table->foreignId('parent_id')->nullable()->after('category_id')
                ->constrained('acc_fixed_assets')->nullOnDelete();

            $table->string('location', 120)->nullable();
            $table->string('department', 120)->nullable();

            /* ⓘ পক্ষের জোড়া — কর্মী আর বিক্রেতা কোর চেনে ([[PartyRegistry]]), মডিউল নয় */
            $table->unsignedBigInteger('custodian_id')->nullable();
            $table->unsignedBigInteger('supplier_id')->nullable();

            /* ⓘ ক্রয় বিলের সারি — চুক্তির মাধ্যমে ([[CapitalisesABillLine]]); ফরেন কি নেই, মডিউলের সীমা */
            $table->unsignedBigInteger('purchase_bill_id')->nullable();
            $table->unsignedBigInteger('purchase_bill_line_id')->nullable();
            $table->decimal('capitalised_qty', 18, 4)->nullable();

            $table->date('put_in_use_on')->nullable();
            $table->string('serial_no', 120)->nullable();
            $table->string('model_no', 120)->nullable();
            $table->date('warranty_ends_on')->nullable();
            $table->string('insurance_policy_no', 64)->nullable();
            $table->date('insured_until')->nullable();

            $table->index(['company_id', 'category_id'], 'acc_fa_category');
            $table->index(['company_id', 'purchase_bill_line_id'], 'acc_fa_bill_line');
            $table->index(['company_id', 'custodian_id'], 'acc_fa_custodian');
        });

        Schema::create('acc_asset_cost_parts', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('fixed_asset_id')->constrained('acc_fixed_assets')->cascadeOnDelete();
            $table->string('kind', 24);
            $table->decimal('amount', 18, 4);
            $table->string('note', 255)->nullable();
            $table->timestamps();

            $table->index(['company_id', 'fixed_asset_id'], 'acc_asset_part_of');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acc_asset_cost_parts');

        Schema::table('acc_fixed_assets', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('category_id');
            $table->dropConstrainedForeignId('parent_id');
            $table->dropIndex('acc_fa_category');
            $table->dropIndex('acc_fa_bill_line');
            $table->dropIndex('acc_fa_custodian');
            $table->dropColumn([
                'location', 'department', 'custodian_id', 'supplier_id', 'purchase_bill_id', 'purchase_bill_line_id',
                'capitalised_qty', 'put_in_use_on', 'serial_no', 'model_no', 'warranty_ends_on', 'insurance_policy_no', 'insured_until',
            ]);
        });

        Schema::dropIfExists('acc_asset_categories');
    }
};
