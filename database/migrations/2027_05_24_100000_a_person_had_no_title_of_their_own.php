<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ A person's own title, Bengali and English — for people who are NOT HR employees (owner, 24 Nov 2026: "সৈয়দা মাসুদা আক্তার
 * তন্নির আইডির নিচে ডেজিগনেশন … ম্যানেজিং ডিরেক্টর"; "স্টারলাইনের এরিয়া ম্যানেজার … তাদেরকে এমপ্লয়ি হিসাবে অ্যাড করবো না,
 * তাদেরও তো একটা ডেজিগনেশন আছে").
 * ⓘ Only the owner sets it, on the user form. An HR employee's own designation always wins over it; empty → the role shows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'designation')) {
                $table->string('designation', 100)->nullable()->after('name_bn');
            }
            if (! Schema::hasColumn('users', 'designation_bn')) {
                $table->string('designation_bn', 100)->nullable()->after('designation');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            foreach (['designation_bn', 'designation'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
