<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * যে শাখা বাছলেন, সেই শাখা দেখলেন না — মালিকের অভিযোগ, ২৯ সেপ্টেম্বর ২০২৬:
 * *"এক শাখার হিসাব আরেক শাখায় দেখা যায় কেন?"*
 *
 * ⓘ হেডারে শাখা বাছলে এতদিন কেবল নতুন কাগজের শাখা বদলাত
 * (`current_branch_id`); তালিকা, রিপোর্ট সব শাখাই দেখাত।
 *
 * ⭐ এই ঘর "দেখার" প্রশ্নের উত্তর: `true` = সব শাখা (নাগালের ভেতরে), `false` =
 * কেবল কাজের শাখা (`current_branch_id`)। ⚠️ শুরুর মান `true` — আজ যে যা দেখেন
 * কাল সকালেও তা-ই; কেউ হেডারে একটা শাখা বাছলে তবেই দেখা সরু হয়।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('view_all_branches')->default(true)->after('current_branch_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('view_all_branches');
        });
    }
};
