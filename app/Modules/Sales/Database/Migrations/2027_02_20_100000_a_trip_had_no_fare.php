<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ট্রিপের ভাড়া — এক ট্রাক, এক ভাড়া, এক ভাউচার (মালিক, ৭ অক্টোবর ২০২৬; fe-র অনুমোদিত সিদ্ধান্ত ঘ; [[FarePayment]])।
 *
 * ⛔ ট্রিপে (লোডিং শিট) ভাড়ার কোনো ঘরই ছিল না — কয়েক চালানের এক ট্রাকের ভাড়া কোথাও লেখা যেত না, আর চালানে আলাদা
 * লিখলে একই ট্রাকের ভাড়া কয়েকবার বসত।
 *
 * ঘরগুলো চালানের ভাড়ার হুবহু ([[2027_02_19_100000_a_fare_came_out_of_the_main_counter]]), সাথে অঙ্ক আর বাহক:
 *  - `transport_cost` — ভাড়া; `carrier_id` — বাহক (পরে দিলে দেনা তাঁর নামে);
 *  - `fare_rule`, `fare_status` (`now` · `due`), `fare_account_id`, `fare_reference` (TrxID), `fare_payer_id`, `fare_voucher_id`।
 *
 * ⓘ সব nullable — পুরনো ট্রিপ যেমন ছিল তেমনই, ভাড়াহীন।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sal_shipments', function (Blueprint $table): void {
            $table->decimal('transport_cost', 18, 4)->nullable()->after('carrier_name');
            $table->foreignId('carrier_id')->nullable()->after('transport_cost')->constrained('suppliers')->nullOnDelete();
            $table->string('fare_rule', 16)->nullable()->after('carrier_id');
            $table->string('fare_status', 16)->nullable()->after('fare_rule');
            $table->foreignId('fare_account_id')->nullable()->after('fare_status')->constrained('accounts')->nullOnDelete();
            $table->string('fare_reference', 64)->nullable()->after('fare_account_id');
            $table->foreignId('fare_payer_id')->nullable()->after('fare_reference')->constrained('users')->nullOnDelete();
            $table->foreignId('fare_voucher_id')->nullable()->after('fare_payer_id')->constrained('vouchers')->nullOnDelete();

            // "ভাড়া বাকি" ট্রিপ — নাম ছোট, ৬৪-র অনেক নিচে
            $table->index(['company_id', 'fare_status'], 'sal_sh_fare_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('sal_shipments', function (Blueprint $table): void {
            $table->dropIndex('sal_sh_fare_status_idx');
            $table->dropConstrainedForeignId('fare_voucher_id');
            $table->dropConstrainedForeignId('fare_payer_id');
            $table->dropColumn('fare_reference');
            $table->dropConstrainedForeignId('fare_account_id');
            $table->dropColumn(['fare_status', 'fare_rule']);
            $table->dropConstrainedForeignId('carrier_id');
            $table->dropColumn('transport_cost');
        });
    }
};
