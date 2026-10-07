<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * কেউ ছাড়টা বদলে দিলেন, আর পুরনোটা কেউ রাখল না।
 *
 * ── ⭐ স্পেক §১৯ ─────────────────────────────────────────────────────
 * *"Override করার সময় Reason Mandatory, User, Date/Time, Original
 * Benefit, Modified Benefit ও প্রয়োজনে Approval সংরক্ষণ করতে হবে।"*
 * আর §১৭-এ আলাদা একটা *"Override Report"*।
 *
 * ── ⚠️ কেন আলাদা কলাম, অডিট-খাতা যথেষ্ট নয় ─────────────────────────
 * ⓘ [[PromotionApplication]] নিরীক্ষিত, তাই পুরনো → নতুন মান অডিট-খাতায়
 * এমনিতেই ওঠে। ⛔ কিন্তু *"এ মাসে কে কতবার ছাড় হাতে বদলেছেন, আর তাতে
 * কত টাকা বেশি গেছে"* — এই প্রতিবেদন অডিট-খাতার JSON খুঁড়ে বানাতে হত,
 * প্রতিটা সারি খুলে। ⚠️ আর যে প্রতিবেদন বানানো কঠিন, সেটা কেউ দেখেন না।
 *
 * ⭐ তাই মূল অঙ্ক আর কে-কখন নিজের কলামে — এক কোয়েরিতে গোনা যায়।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promotion_applications', function (Blueprint $table) {
            /*
             * ⓘ ইঞ্জিন যা বলেছিল — হাতে বদলানোর **আগের** অঙ্ক।
             *
             * ⚠️ `null` মানে কখনো বদলানো হয়নি। ⛔ শূন্য দিয়ে বোঝানো যেত
             * না: ইঞ্জিনের অঙ্ক নিজেই শূন্য হতে পারে (যেমন ধাপের বাইরে)।
             */
            $table->decimal('original_worth', 18, 4)->nullable()->after('worth');

            $table->foreignId('overridden_by')->nullable()->after('override_reason')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('overridden_at')->nullable()->after('overridden_by');
        });
    }

    public function down(): void
    {
        Schema::table('promotion_applications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('overridden_by');
            $table->dropColumn(['original_worth', 'overridden_at']);
        });
    }
};
