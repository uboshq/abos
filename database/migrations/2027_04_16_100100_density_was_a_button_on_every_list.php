<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * ⭐ ঘন সারি — ব্যবহারকারীর নিজের সেটিং (পরিকল্পনা §৩.১: "ঘনত্ব প্রতিটা তালিকায় নয়। ব্যবহারকারীর নিজের সেটিংসে একবার ঠিক করলেই হবে")।
 * ⓘ আগে প্রতিটা তালিকার বারে বোতাম ছিল, আর পরের পাতায় গেলেই ভুলে যেত। ঠিকানার `?compact=` এখনো চলে ([[Ui::compact()]])।
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'compact_rows')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->boolean('compact_rows')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'compact_rows')) {
            Schema::table('users', fn (Blueprint $table) => $table->dropColumn('compact_rows'));
        }
    }
};
