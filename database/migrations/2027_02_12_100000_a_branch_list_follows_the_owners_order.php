<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * শাখার ক্রম — মালিক, ৫ অক্টোবর ২০২৬: আদি কর্পোরেশনের শাখা "সুপার, লায়ন, গোল্ড, জাবেদ, হোলসেল" এই ক্রমে।
 * ⓘ আগে সব তালিকা ইংরেজি নামের বর্ণক্রমে সাজত — মালিকের নিজের ক্রম বসানোর কোনো ঘর ছিল না।
 * ⓘ ০ মানে "ক্রম দেওয়া হয়নি" — তখন আগের মতো নামের বর্ণক্রম ([[Branch::scopeOrdered()]])।
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('branches', 'sort_order')) {
            return;
        }

        Schema::table('branches', function (Blueprint $table): void {
            $table->unsignedSmallInteger('sort_order')->default(0)->after('name_bn');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('branches', 'sort_order')) {
            Schema::table('branches', fn (Blueprint $table) => $table->dropColumn('sort_order'));
        }
    }
};
