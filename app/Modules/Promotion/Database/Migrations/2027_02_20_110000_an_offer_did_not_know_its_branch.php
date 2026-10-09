<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ⛔ অফারের প্রয়োগ কোন শাখার, জানা ছিল না — পুরো-ERP পুনঃঅডিট, ৯ অক্টোবর ২০২৬ (প্রমোশন ২১; [[AGiftIsIssuedOnlyInsideMyBranchTest]])।
 *
 * ⓘ বাকি মডিউলের প্রতিটা কাগজের মাথায় শাখা থাকে, আর পর্দা সেই শাখা ধরে দেয়াল টানে ([[DataScope]])। প্রয়োগের সারিতে শাখা ছিল না, তাই
 * উপহার দেওয়ার পর্দায় সব শাখার বাকি উপহার আসত, আর নেত্রকোনার বিলের উপহার ময়মনসিংহ থেকে দেওয়া যেত। ⭐ এখন সারিতে কাগজের শাখা —
 * বসানোর সময় কাগজের সারি-প্রসঙ্গ থেকে ([[PromotionDesk::apply()]]), আর পুরনো সারিতে কাগজ থেকে ভরে দেওয়া।
 *
 * ⚠️ শাখাটা কেবল **দেখানোর দেয়ালে** — বাজেট আর ছাদ গোটা কোম্পানিতেই গোনা হয় ([[BudgetGuard]]), তাই মডেলে গ্লোবাল স্কোপ নয়।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promotion_applications', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->after('company_id')
                ->constrained('branches', 'id', 'pa_branch_fk')->nullOnDelete();
        });

        foreach (['sales_invoice' => 'sal_invoices', 'sales_order' => 'sal_orders', 'delivery_challan' => 'sal_challans'] as $type => $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            DB::table('promotion_applications')
                ->where('source_type', $type)
                ->whereNull('branch_id')
                ->update(['branch_id' => DB::raw("(select p.branch_id from {$table} p where p.id = promotion_applications.source_id)")]);
        }
    }

    public function down(): void
    {
        Schema::table('promotion_applications', function (Blueprint $table) {
            $table->dropForeign('pa_branch_fk');
            $table->dropColumn('branch_id');
        });
    }
};
