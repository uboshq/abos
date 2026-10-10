<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ করের অবচয় খাতার অবচয় থেকে আলাদা — স্থায়ী সম্পদ ধাপ ৫ (মালিক, ১০ অক্টোবর ২০২৬)।
 *
 * ⓘ আয়করের হিসাবে প্রতিটা শ্রেণির নিজের হার, আর পদ্ধতি প্রায়ই অবশিষ্ট মূল্যের উপর। ⛔ আইনের হার কোডে লেখা নেই — মালিক
 * শ্রেণিতে বসান। কেবল যোগ:
 *   · `acc_asset_categories.tax_method` / `tax_rate` — খালি মানে এই শ্রেণির করের হিসাব হয় না।
 *   · `acc_asset_tax_years` — সম্পদ ধরে আয়বর্ষের করের অবচয়: শুরুর অবশিষ্ট মূল্য, বছরের অবচয়, শেষের অবশিষ্ট মূল্য।
 *     (সম্পদ, বছর শেষ) অনন্য — আবার হিসাব করলে সারি বদলায়, দুইটা হয় না।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('acc_asset_categories', function (Blueprint $table): void {
            $table->string('tax_method', 16)->nullable()->after('capitalisation_threshold');
            $table->decimal('tax_rate', 8, 4)->nullable()->after('tax_method');
        });

        Schema::create('acc_asset_tax_years', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('fixed_asset_id')->constrained('acc_fixed_assets')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->date('year_start');
            $table->date('year_end');
            $table->string('method', 16);
            $table->decimal('rate', 8, 4);
            $table->decimal('opening_wdv', 18, 4);
            $table->decimal('amount', 18, 4);
            $table->decimal('closing_wdv', 18, 4);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['fixed_asset_id', 'year_end'], 'acc_asset_tax_once');
            $table->index(['company_id', 'year_end'], 'acc_asset_tax_year');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acc_asset_tax_years');

        Schema::table('acc_asset_categories', function (Blueprint $table): void {
            $table->dropColumn(['tax_method', 'tax_rate']);
        });
    }
};
