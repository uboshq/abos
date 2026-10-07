<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * কে কোন কোম্পানিতে ঢুকতে পারে, আর কোম্পানির সেটিং কোথায় থাকে।
 *
 * users টেবিলে company_id বসানো হয়নি ইচ্ছাকৃতভাবে: একজন হিসাবরক্ষক দুইটা
 * কোম্পানি দেখতে পারে, আর কলাম দিয়ে সেটা করা যায় না। এই pivot টেবিলই
 * কোম্পানি-সুইচারের ভিত্তি (সেকশন ১৫.১৫) — DMS-এ সুইচ কাজ না করার কারণটাই
 * ছিল এই সম্পর্কটা ঠিকভাবে না থাকা।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // এই কোম্পানিতে ঢুকলে ডিফল্ট কোন শাখা — না থাকলে কোম্পানির
            // ডিফল্ট শাখা। ব্যবহারকারীভেদে আলাদা, তাই এখানে।
            $table->foreignId('default_branch_id')->nullable()->constrained('branches')->nullOnDelete();

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'user_id']);
            $table->index(['user_id', 'is_active']);
        });

        // ব্যবহারকারীর শেষ অবস্থা — Workspace Memory (সেকশন ১৫.১৫)।
        // সেশনে নয়, রেকর্ডে: সেশনে রাখলে রিলোডে বা অন্য ডিভাইসে মুছে যায়,
        // আর DMS-এ ঠিক সেই কারণেই কোম্পানি-সুইচ টিকত না।
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('current_company_id')->nullable()->after('password')
                ->constrained('companies')->nullOnDelete();
            $table->foreignId('current_branch_id')->nullable()->after('current_company_id')
                ->constrained('branches')->nullOnDelete();
            $table->string('locale', 5)->default('bn')->after('current_branch_id');
            $table->string('theme', 16)->default('light')->after('locale');
            $table->boolean('is_active')->default(true)->after('theme');
            $table->timestamp('last_login_at')->nullable()->after('is_active');
            $table->softDeletes();
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->id();

            // company_id null মানে প্রোডাক্ট-ডিফল্ট (module.php থেকে আসা),
            // আর রো থাকলে সেই কোম্পানির নিজের পছন্দ। দুই স্তর এক টেবিলে
            // রাখলে "ডিফল্ট কী ছিল" প্রশ্নের উত্তর সবসময় পাওয়া যায়।
            $table->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();

            $table->string('module', 64);
            $table->string('key', 191);
            $table->string('type', 16)->default('string');  // boolean|integer|decimal|string|json
            $table->text('value')->nullable();
            $table->string('group', 32)->default('general'); // entry|print|report|general

            $table->timestamps();

            $table->unique(['company_id', 'key']);
            $table->index(['company_id', 'module', 'group']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('current_company_id');
            $table->dropConstrainedForeignId('current_branch_id');
            $table->dropColumn(['locale', 'theme', 'is_active', 'last_login_at', 'deleted_at']);
        });

        Schema::dropIfExists('company_user');
    }
};
