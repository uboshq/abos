<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⛔ শাখাটা দোকান, ডিপো, গুদাম না অফিস — কোথাও লেখা ছিল না (মালিক, ২৪ নভেম্বর ২০২৬)।
 *
 * ⓘ online: একটা খালি-রাখা-যায় ঘর; পুরোনো কোনো শাখার মান বদলায় না, নাম দেখে কিছু আন্দাজও করা হয় না — খালি মানে "ধরন দেওয়া নেই"।
 * ফেরত: ঘরটা মোছে।
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('branches', 'type')) {
            Schema::table('branches', fn (Blueprint $table) => $table->string('type', 16)->nullable()->after('name_bn'));
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('branches', 'type')) {
            Schema::table('branches', fn (Blueprint $table) => $table->dropColumn('type'));
        }
    }
};
