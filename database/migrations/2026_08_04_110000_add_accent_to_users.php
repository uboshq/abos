<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ব্যবহারকারীর বাছাই করা অ্যাকসেন্ট রং।
 *
 * ব্রাউজারে নয়, রেকর্ডে — সেকশন ১৫.১৫। ব্রাউজারে রাখলে এক ডিপোর একটা
 * কম্পিউটারে সকালের অপারেটরের পছন্দ সন্ধ্যার অপারেটরকে স্বাগত জানাত, আর
 * দুজনেই বারবার বদলাত; সেটা পছন্দ না দেওয়ার চেয়েও খারাপ।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('accent', 16)->default('blue')->after('theme');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('accent');
        });
    }
};
