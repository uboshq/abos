<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * ⭐ মানুষের বাংলা নাম — মালিক, ৮ নভেম্বর ২০২৬ (প্রোফাইলের ছবিতে "নাম"-এর নিচে দাগ): "ইউজারের নাম বাংলাতে দেখায় না যদি বাংলা
 * সিলেক্ট করি … বাংলা নাম লেখার জন্য একটা বক্স বসিয়ে দাও"।
 *
 * ⓘ ঐচ্ছিক — খালি থাকলে আগের মতো `name` ([[User::displayName()]])। দৈর্ঘ্য `name`-এর ঘরের মাপে (১২০)।
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'name_bn')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('name_bn', 120)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'name_bn')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('name_bn');
            });
        }
    }
};
