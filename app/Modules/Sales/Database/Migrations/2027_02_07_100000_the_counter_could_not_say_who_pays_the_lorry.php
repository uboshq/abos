<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * কাউন্টারের "মাল কীভাবে যাবে" আর "গাড়ি ও ভাড়া" — মালিক, ৪ অক্টোবর ২০২৬ (সংস্করণ ২, D365/SAP কাউন্টারের ধাঁচ)।
 *
 * ⓘ চালানে তিনটা ঘর, সবগুলো খালি-যোগ্য — খালি মানে আজকের আচরণ, হুবহু (পুরনো চালানে কিছু বদলায় না):
 *   `delivery_mode`  take_now (এখনই নিয়ে যাবেন) · send_later (পরে পাঠানো হবে) · pickup_later (পরে নিয়ে যাবেন)
 *   `vehicle_owner`  own · hired · customer · none
 *   `fare_paid_by`   us (Prepaid — আমাদের খরচ) · us_add_to_bill (Prepaid & Add — খরচ আর বিলে আদায়)
 *                    · customer (Collect — ক্রেতা চালককে দেন, খাতায় কিছু নয়) · none
 * আর বিলে `freight_charge` — বিলে যোগ করা ভাড়া, মোটের ভিতরে ([[SalesInvoiceService::replaceLines()]]),
 * খাতায় আলাদা আয়ের খাতে ([[StandardChart::FREIGHT_INCOME]])।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sal_challans', function (Blueprint $table) {
            $table->string('delivery_mode', 16)->nullable()->after('ship_date');
            $table->string('vehicle_owner', 16)->nullable()->after('delivery_mode');
            $table->string('fare_paid_by', 24)->nullable()->after('vehicle_owner');
        });

        Schema::table('sal_invoices', function (Blueprint $table) {
            $table->decimal('freight_charge', 18, 4)->default(0)->after('rounding_amount');
        });
    }

    public function down(): void
    {
        Schema::table('sal_invoices', fn (Blueprint $table) => $table->dropColumn('freight_charge'));
        Schema::table('sal_challans', fn (Blueprint $table) => $table->dropColumn(['delivery_mode', 'vehicle_owner', 'fare_paid_by']));
    }
};
