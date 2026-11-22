<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ বিক্রির কৃতিত্ব দায়িত্বে থাকা মানুষের, বিল যিনি কেটেছেন তাঁর নয় (মালিক, ২৩ নভেম্বর ২০২৬)।
 *
 * ⓘ আন্তর্জাতিক নিয়ম: SAP-এর partner function "sales employee", D365-এর "sales responsible", Odoo-র salesperson।
 *   অফিসের লোক বিল কাটেন, কিন্তু বিক্রিটা মাঠের SR/ASM-এর — আগে `created_by` ধরে গুনলে বিলকাটা লোক সব পেতেন।
 * ⓘ `sales_person_id` পাকা করার সময় একবার বসে ([[SalesResponsibility]]); পুরনো সারি ভরে
 *   `sales:assign-responsible` আদেশ। ফাঁকা = কেউ দায়িত্বে নেই, কারো খাতায় ওঠে না।
 * ⛔ মানুষ মুছলে বিল থাকে, ঘরটা ফাঁকা হয় (nullOnDelete) — খাতা কখনো ভাঙে না।
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['sal_invoices' => 'sal_inv_sales_person', 'sal_returns' => 'sal_ret_sales_person'] as $table => $index) {
            if (Schema::hasColumn($table, 'sales_person_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) use ($index): void {
                $t->foreignId('sales_person_id')->nullable()->after('customer_id')->constrained('users')->nullOnDelete();
                $t->index(['company_id', 'sales_person_id', 'trx_date'], $index);
            });
        }
    }

    public function down(): void
    {
        foreach (['sal_invoices' => 'sal_inv_sales_person', 'sal_returns' => 'sal_ret_sales_person'] as $table => $index) {
            if (! Schema::hasColumn($table, 'sales_person_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) use ($index): void {
                $t->dropForeign(['sales_person_id']);
                $t->dropIndex($index);
                $t->dropColumn('sales_person_id');
            });
        }
    }
};
