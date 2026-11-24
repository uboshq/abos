<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⛔ একই রকম বিশটা শাখার মডিউল বিশবার হাতে বসাতে হত — মডিউল প্যাকেজ (মালিক, ২৪ নভেম্বর ২০২৬, নমুনা system-plan-v4; [[ModulePackages]])।
 *
 * ⓘ online: দুটো নতুন টেবিল, পুরনো কিছু বদলায় না; প্যাকেজ না বসালে শাখার মডিউল হুবহু আগের মতো। ফেরত: টেবিল দুটো মোছে, শাখার সারি থাকে।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('module_packages', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('name_en', 120);
            $table->string('name_bn', 120)->nullable();
            $table->json('modules');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'name_en'], 'module_package_name_once');
        });

        Schema::create('branch_module_packages', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('package_id')->constrained('module_packages')->cascadeOnDelete();
            // ⓘ হাতে বদলানো — প্যাকেজ বদলালে এই শাখা নড়ে না
            $table->boolean('is_custom')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique('branch_id', 'branch_one_package');
            $table->index(['package_id', 'is_custom'], 'branch_package_followers');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_module_packages');
        Schema::dropIfExists('module_packages');
    }
};
