<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ অবচয়ের ইঞ্জিন — স্থায়ী সম্পদ ধাপ ২ (মালিক, ১০ অক্টোবর ২০২৬; IAS 16.50-62)।
 *
 * ⓘ কেবল যোগ — আগের অবচয়ের সারি আর দাখিলা অক্ষত:
 *   · `acc_depreciation_runs` — মাসের দৌড়, প্রতি শাখায় একটা কাগজ (DEP-…); (কোম্পানি, মাস, শাখা) অনন্য — দুইবার চালালে
 *     দ্বিতীয়টা ডাটাবেজেই থামে। ⚠️ শাখাহীন সম্পদের দৌড়ে `branch_key` শূন্য, কারণ NULL-এ অনন্যতা খাটে না।
 *   · `acc_depreciation_entries.run_id` — কোন দৌড়ে বসেছে (খালি = আগের এক-সম্পদের পথ বা খোলা জের)।
 *   · `acc_fixed_assets.total_units` — ব্যবহারের এককে ক্ষয়ের মোট একক (কিলোমিটার, ঘণ্টা)।
 *   · `acc_asset_usages` — মাসে কত একক চলল; (সম্পদ, মাস) অনন্য।
 *   · `acc_asset_estimate_changes` — আয়ু, শেষ দাম, পদ্ধতি বা হার বদলের ইতিহাস (IAS 8: আগামীর দিকে, অতীত ছোঁয় না)।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acc_depreciation_runs', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->unsignedBigInteger('branch_key')->default(0);
            $table->date('period_end');
            $table->string('document_no', 40);
            $table->decimal('total', 18, 4)->default(0);
            $table->unsignedInteger('assets_count')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'period_end', 'branch_key'], 'acc_dep_run_once');
        });

        Schema::table('acc_depreciation_entries', function (Blueprint $table): void {
            $table->foreignId('run_id')->nullable()->after('fixed_asset_id')
                ->constrained('acc_depreciation_runs')->nullOnDelete();
        });

        Schema::table('acc_fixed_assets', function (Blueprint $table): void {
            $table->decimal('total_units', 18, 4)->nullable()->after('rate');
        });

        Schema::create('acc_asset_usages', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('fixed_asset_id')->constrained('acc_fixed_assets')->cascadeOnDelete();
            $table->date('period_end');
            $table->decimal('units', 18, 4);
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['fixed_asset_id', 'period_end'], 'acc_asset_usage_once');
        });

        Schema::create('acc_asset_estimate_changes', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('fixed_asset_id')->constrained('acc_fixed_assets')->cascadeOnDelete();
            $table->date('changed_on');
            $table->json('before');
            $table->json('after');
            $table->string('reason', 500);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'fixed_asset_id'], 'acc_asset_estimate_of');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acc_asset_estimate_changes');
        Schema::dropIfExists('acc_asset_usages');

        Schema::table('acc_fixed_assets', function (Blueprint $table): void {
            $table->dropColumn('total_units');
        });

        Schema::table('acc_depreciation_entries', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('run_id');
        });

        Schema::dropIfExists('acc_depreciation_runs');
    }
};
