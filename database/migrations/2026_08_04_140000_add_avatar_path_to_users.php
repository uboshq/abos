<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ব্যবহারকারীর ছবি।
 *
 * পথ রাখা হচ্ছে, ছবি নয় — ডাটাবেজে blob রাখলে প্রতিটা পাতা লোডে ছবিটা
 * কোয়েরির সাথে আসত, আর ব্যাকআপের আকার কয়েকগুণ হত।
 *
 * nullable, কারণ ছবি না থাকাটাই স্বাভাবিক অবস্থা — তখন আদ্যক্ষর দেখায়।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('avatar_path')->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('avatar_path');
        });
    }
};
