<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * কার্টন গোনা হয় বক্সে, পিসে নয়।
 *
 * ── কেন, ১৯ সেপ্টেম্বর ২০২৬ ─────────────────────────────────────────
 * মালিকের উদাহরণ: *"Dairy Milk Chocolate 24 pcs e ek box, 12 box e 1
 * ctn"*। ⓘ তিনি কার্টনকে বক্সে বলেন, পিসে নয়। শুধু পিসে লিখতে বাধ্য
 * করলে মানুষকে মাথায় ১২ × ২৪ গুণ করতে হত — আর ভুলটা ঢোকে ঠিক সেখানেই।
 *
 * ⭐ তাই প্যাক মনে রাখে মানুষ যেভাবে বলেছিলেন: `per_qty` (১২) আর
 * `per_unit_id` (বক্স)। ⚠️ কিন্তু একমাত্র সত্য `factor` (২৮৮, base-এ) —
 * মজুদ সেটাই পড়ে ([[PackConversion]])। এই দুই ঘর কেবল ফর্মে আবার
 * দেখানো আর বক্সের মাপ বদলালে কার্টনটা আবার হিসাব করার সূত্র।
 *
 * ⓘ দুইটাই null হতে পারে: base-এর নিজের সারি, আর ব্যাকফিলের সারি —
 * তাদের "কিসের কত" বলার কিছু নেই।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inv_product_units', function (Blueprint $table) {
            $table->decimal('per_qty', 18, 6)->nullable()->after('factor');

            /*
             * ⚠️ restrict: বক্সের নাম মোছা গেলে কার্টনের "১২ কিসের" উত্তর
             * হারাত। factor তখনো ঠিক থাকত, কিন্তু ফর্ম মিথ্যা দেখাত।
             */
            $table->foreignId('per_unit_id')->nullable()->after('per_qty')
                ->constrained('mdm_units')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inv_product_units', function (Blueprint $table) {
            $table->dropConstrainedForeignId('per_unit_id');
            $table->dropColumn('per_qty');
        });
    }
};
