<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * এলাকা আর রুট প্রতিটা শাখার নিজের — মালিক, ৬ অক্টোবর ২০২৬।
 *
 * ⛔ মালিকের প্রশ্ন: *"এলাকা ও রুট সব ব্রাঞ্চে একই দেখায় কেন?"* ⓘ `mdm_locations`-এ শাখার কোনো ঘর
 * ছিল না, অথচ ১ অক্টোবরের নিয়ম *"প্রতিটা শাখা পুরোপুরি আলাদা"*।
 *
 * ⓘ `null` মানে কোম্পানির সব শাখার — দেশ, বিভাগ, অঞ্চল সবসময় তাই, আর পুরনো সারিও ভরাটের আগে তাই
 * থাকে (কিছুই হঠাৎ লুকায় না)। ভরাট আলাদা কমান্ডে: `master-data:locations-to-branches`।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mdm_locations', function (Blueprint $table): void {
            $table->foreignId('branch_id')->nullable()->after('company_id')->constrained('branches')->nullOnDelete();

            // তালিকা আর বাছাইয়ের প্রশ্ন: "এই শাখার এই স্তরের" — নাম ছোট, ৬৪-র অনেক নিচে
            $table->index(['company_id', 'branch_id', 'level'], 'mdm_loc_branch_level_idx');
        });
    }

    public function down(): void
    {
        Schema::table('mdm_locations', function (Blueprint $table): void {
            $table->dropIndex('mdm_loc_branch_level_idx');
            $table->dropConstrainedForeignId('branch_id');
        });
    }
};
