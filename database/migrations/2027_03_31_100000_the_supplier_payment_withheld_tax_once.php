<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ উৎসে কর (AIT) আর উৎসে ভ্যাট (VDS) — একবারই কাটা, বিলে না হলে পরিশোধে (fe, ২১ অক্টোবর ২০২৬; নকশা #৫, #২৫; আয়কর আইন
 * ২০২৩, মূসক আইন ২০১২ — "পাওনা জমা বা পরিশোধ, যেটা আগে")।
 *
 * ⓘ যা আসে:
 *  - সরবরাহকারীর রেকর্ডে হার: `ait_rate` (ভ্যাট বাদে মূল্যের উপর %) আর `vds_rate` (বিলের ভ্যাটের উপর %) — খালি মানে কাটা নেই।
 *  - বিলে `withheld_at`: `bill` (বিলেই কাটা) বা `payment` (পরিশোধে কাটা) — দ্বিতীয়বার কাটা ঠেকানোর দাগ। আগের যে বিলে
 *    বিলেই কাটা আছে (`ait_amount + vds_amount > 0`), সেটায় `bill` বসে।
 *  - পরিশোধ আর তার লাইনে কাটা অঙ্ক; হাতে বদলালে কারণ।
 *
 * ⛔ কোনো পোস্ট হওয়া সারি বদলায় না — কেবল নতুন কলাম আর দাগ।
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('suppliers')) {
            Schema::table('suppliers', function (Blueprint $table) {
                if (! Schema::hasColumn('suppliers', 'ait_rate')) {
                    $table->decimal('ait_rate', 7, 4)->nullable();
                }
                if (! Schema::hasColumn('suppliers', 'vds_rate')) {
                    $table->decimal('vds_rate', 7, 4)->nullable();
                }
            });
        }

        if (Schema::hasTable('pur_bills') && ! Schema::hasColumn('pur_bills', 'withheld_at')) {
            Schema::table('pur_bills', function (Blueprint $table) {
                $table->string('withheld_at', 8)->nullable()->after('vds_amount');
            });

            DB::table('pur_bills')->whereRaw('ait_amount + vds_amount > 0')->update(['withheld_at' => 'bill']);
        }

        if (Schema::hasTable('pur_payments')) {
            Schema::table('pur_payments', function (Blueprint $table) {
                if (! Schema::hasColumn('pur_payments', 'ait_amount')) {
                    $table->decimal('ait_amount', 18, 4)->default(0)->after('amount');
                    $table->decimal('vds_amount', 18, 4)->default(0)->after('ait_amount');
                    $table->string('withholding_reason', 255)->nullable()->after('vds_amount');
                }
            });
        }

        if (Schema::hasTable('pur_payment_lines') && ! Schema::hasColumn('pur_payment_lines', 'ait_amount')) {
            Schema::table('pur_payment_lines', function (Blueprint $table) {
                $table->decimal('ait_amount', 18, 4)->default(0)->after('amount');
                $table->decimal('vds_amount', 18, 4)->default(0)->after('ait_amount');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('pur_payment_lines') && Schema::hasColumn('pur_payment_lines', 'ait_amount')) {
            Schema::table('pur_payment_lines', fn (Blueprint $table) => $table->dropColumn(['ait_amount', 'vds_amount']));
        }
        if (Schema::hasTable('pur_payments') && Schema::hasColumn('pur_payments', 'ait_amount')) {
            Schema::table('pur_payments', fn (Blueprint $table) => $table->dropColumn(['ait_amount', 'vds_amount', 'withholding_reason']));
        }
        if (Schema::hasTable('pur_bills') && Schema::hasColumn('pur_bills', 'withheld_at')) {
            Schema::table('pur_bills', fn (Blueprint $table) => $table->dropColumn('withheld_at'));
        }
        if (Schema::hasTable('suppliers')) {
            Schema::table('suppliers', function (Blueprint $table) {
                foreach (['ait_rate', 'vds_rate'] as $column) {
                    if (Schema::hasColumn('suppliers', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
