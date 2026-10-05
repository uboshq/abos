<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ প্রিন্সিপালের কমিশন — মালিক, ৫ অক্টোবর ২০২৬।
 *
 * ডিপো এক কোম্পানির ভেতরে কয়েকটা প্রিন্সিপালের মাল রাখে, আর প্রতিটা শাখা একটা প্রিন্সিপাল। ডিপোর আয় আদায়ের উপর
 * কমিশন, প্রিন্সিপাল ধরে আলাদা:
 *
 *   মার্জিন r%  → কমিশন = আদায় × r / 100
 *   মার্কআপ r%  → কমিশন = আদায় × r / (100 + r)
 *
 * আর প্রতিটা প্রিন্সিপালের নিজের মাস — শুরুর দিন আর শেষের দিন (যেমন ২৬ থেকে ২৫)।
 *
 * ⓘ কেবল রিপোর্ট — খাতায় কিছু বসে না ([[PrincipalCommission]])। তাই ঘরগুলো সরবরাহকারীর সারিতে, আলাদা টেবিলে নয়।
 * ⓘ `principal_branch_id` সরবরাহকারীর `branch_id` থেকে আলাদা: ওটা বলে তালিকায় কোন শাখার খাতায় তিনি বসেন, আর এটা বলে
 * কোন শাখার আদায়ের উপর তাঁর কমিশন গোনা হয়। একটা সরবরাহকারী সব শাখায় দেখা যেতে পারেন, অথচ আদায় এক শাখার।
 * ⓘ শেষের দিন ৩১ মানে "মাসের শেষ দিন" — ছোট মাসে শেষ তারিখে নামে (ফেব্রুয়ারিতে ২৮/২৯)।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->foreignId('principal_branch_id')->nullable()->after('branch_id')
                ->constrained('branches')->nullOnDelete();
            $table->string('commission_basis', 8)->nullable()->after('principal_branch_id');
            $table->decimal('commission_rate', 6, 3)->nullable()->after('commission_basis');
            $table->unsignedTinyInteger('cycle_start_day')->nullable()->after('commission_rate');
            $table->unsignedTinyInteger('cycle_close_day')->nullable()->after('cycle_start_day');
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('principal_branch_id');
            $table->dropColumn(['commission_basis', 'commission_rate', 'cycle_start_day', 'cycle_close_day']);
        });
    }
};
