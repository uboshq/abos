<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ ধাপের নিজের "কত টাকা থেকে" — অনুমোদন পর্দা ভাগ ৩, দফা ১৭ (মালিকের "হ্যাঁ", ২১ অক্টোবর ২০২৬; পরিকল্পনার পাতা ৪)।
 *
 * ⛔ আগে সীমা কেবল গোটা ছকের (`approval_flows.threshold_amount`): ছক ধরলে প্রতিটা ধাপ সই দিত। ৳১,০০০-এর পরিশোধও মালিকের কাছে
 * যেত, নইলে মালিককে ছক থেকে সরাতে হত আর ৳৫ লাখের কাগজও তাঁকে ছাড়াই পার হত। ⭐ SAP-এর release strategy আর Oracle AME-র মতো:
 * ধাপটা কেবল এই অঙ্ক বা তার বেশি হলে সই দেয় ([[ApprovalFlow::stepsAt()]])। NULL মানে যেকোনো অঙ্ক — পুরনো প্রতিটা ছক অবিকল আগের মতো।
 * ⚠️ ঘর থাকলে কিছু করে না — দুবার চললেও ভাঙে না।
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('approval_flow_steps', 'from_amount')) {
            return;
        }

        Schema::table('approval_flow_steps', function (Blueprint $table): void {
            $table->decimal('from_amount', 18, 4)->nullable()->default(null)->after('min_approvals');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('approval_flow_steps', 'from_amount')) {
            return;
        }

        Schema::table('approval_flow_steps', function (Blueprint $table): void {
            $table->dropColumn('from_amount');
        });
    }
};
