<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * খরচের স্তর জানে মালটা কার — কোন সরবরাহকারী/প্রিন্সিপালের (মালিক, ৬ অক্টোবর ২০২৬)।
 *
 * ⓘ প্রিন্সিপালের "আসল" কমিশনে অংশ = তাঁর মালের কেনা দাম ([[PrincipalCommission::costOfSales()]])। ⛔ আগে সেটা স্তরের উৎস
 * (ক্রয় বিল / মাল-গ্রহণ) ধরে খোঁজা হত, তাই খোলা মজুদের স্তর কারও নয় — মালিক: *"কোন পণ্য কোন প্রিন্সিপালের, খোলা মজুদে
 * সেটা উল্লেখ করে দেওয়ার ব্যবস্থা করো"*। ⭐ এখন একটা ঘর, এক জায়গা থেকে পড়া: ক্রয়ের স্তরে উৎস থেকে ভরা (নিচে, আর
 * নতুন স্তরে জন্মের সময়), খোলা মজুদে মানুষ বাছেন।
 *
 * ⓘ কেবল ঘর যোগ (MariaDB-নিরাপদ), বিদেশি চাবি ছাড়া — মজুদ মডিউল সরবরাহকারীর উপর নির্ভর করে না; বৈধতা ফর্মে।
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('inv_cost_layers', 'supplier_id')) {
            Schema::table('inv_cost_layers', function (Blueprint $table) {
                $table->unsignedBigInteger('supplier_id')->nullable()->after('batch_id');
                $table->index(['company_id', 'supplier_id'], 'inv_cost_layers_supplier_idx');
            });
        }

        // ⓘ পুরনো ক্রয়ের স্তর — উৎসের কাগজ থেকে, একই কোম্পানির
        foreach (['purchase_bill' => 'pur_bills', 'purchase_receipt' => 'pur_receipts'] as $source => $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            DB::table('inv_cost_layers')
                ->where('source_type', $source)
                ->whereNull('supplier_id')
                ->update(['supplier_id' => DB::raw("(select p.supplier_id from {$table} p where p.id = inv_cost_layers.source_id and p.company_id = inv_cost_layers.company_id)")]);
        }
    }

    public function down(): void
    {
        Schema::table('inv_cost_layers', function (Blueprint $table) {
            $table->dropIndex('inv_cost_layers_supplier_idx');
            $table->dropColumn('supplier_id');
        });
    }
};
