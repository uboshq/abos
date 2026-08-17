<?php

declare(strict_types=1);

use App\Core\Support\DocumentStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * শিপমেন্ট — একটা গাড়ি সকালে কয়টা চালান নিয়ে বেরোয়, বিকেলে ফেরে।
 *
 * ── কেন চালানই যথেষ্ট নয় ────────────────────────────────────────────
 * চালান বলে **কার কাছে কী গেল**। কিন্তু ডিপোর সকালটা চালান ধরে চলে না,
 * গাড়ি ধরে চলে: এক গাড়িতে বারোটা চালান ওঠে, একজন চালক ও একজন হেলপার
 * যান, আর বিকেলে ওই বারোটার হিসাব একসাথে বুঝে নিতে হয়।
 *
 * আজ ওই হিসাবটা কোথাও লেখা নেই। চালানে গাড়ির নম্বর আছে, তাই "আজ এই
 * গাড়িতে কী গেল" খুঁজে বের করা যায় — কিন্তু:
 *
 *   • **কোনটা ফিরল না, তা কেউ বলে না।** দুইটা চালান ক্রেতা নেননি, মাল
 *     গাড়িতেই ফিরে এসেছে — খাতায় দুইটাই "চলে গেছে"। ওই মাল গুদামে
 *     আছে, ব্যবস্থায় নেই।
 *   • **একই চালান দুই গাড়িতেও তোলা যায়** — কেউ আটকায় না।
 *   • **গাড়ি বেরিয়ে গেছে কি না** তার কোনো উত্তর নেই, তাই "কোন কোন
 *     গাড়ি এখনো পথে" প্রশ্নটার উত্তর দেওয়া যায় না।
 *
 * তাই আলাদা একটা কাগজ, যার তিনটাই অবস্থা আছে: খসড়া (লোড হচ্ছে), নিশ্চিত
 * (গাড়ি বেরিয়ে গেছে), সম্পন্ন (ফিরেছে ও বুঝে নেওয়া হয়েছে)।
 *
 * ── কেন এই কাগজ স্টকে হাত দেয় না ────────────────────────────────────
 * মাল আগেই চালানে বেরিয়ে গেছে; শিপমেন্ট সেটা আবার বের করলে দুইবার বেরোত।
 * ফেরত আসা মালও এখানে ঢোকে না — ওটা ফেরানোর একটাই পথ, চালান বাতিল বা
 * বিক্রয় ফেরত, আর সেই পথেই লট ও খতিয়ান ঠিক থাকে। শিপমেন্ট শুধু **বলে
 * দেয় কোনটা ফেরেনি**, আর ফেরানোর কাজটা না হওয়া পর্যন্ত বন্ধ হয় না।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sal_shipments', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('financial_year_id')->nullable()
                ->constrained('financial_years')->nullOnDelete();

            $table->string('document_no', 64);
            $table->date('trx_date');

            // কোন গুদাম থেকে গাড়িটা বেরোল
            $table->foreignId('warehouse_id')->constrained('inv_warehouses')->restrictOnDelete();

            /*
             * গাড়ি — বহরের, নাহলে শুধু লেখা নম্বর।
             *
             * চালানে ঠিক এই দুইটা ঘরই আছে, আর কারণটাও একই: ভাড়ার
             * ট্রাকেও মাল যায়। শুধু FK রাখলে ওই দিনের গাড়িটা লেখাই যেত
             * না, আর মানুষ ফর্ম পার করতে যেকোনো একটা গাড়ি বেছে নিতেন।
             */
            $table->foreignId('vehicle_id')->nullable()
                ->constrained('mdm_vehicles')->nullOnDelete();
            $table->string('vehicle_no', 64)->nullable();

            /*
             * চালক — কর্মী তালিকার, নাহলে শুধু নাম।
             *
             * নিজের চালক হলে FK, তাই "এই চালক এ মাসে কয়টা ট্রিপ গেলেন"
             * প্রশ্নটার উত্তর থাকে। ভাড়ার গাড়ির চালক কর্মী নন, আর
             * তাঁকে কর্মী তালিকায় ঢোকানো মানে বেতনের খাতাও নোংরা করা।
             */
            $table->foreignId('driver_employee_id')->nullable()
                ->constrained('hr_employees')->nullOnDelete();
            $table->string('driver_name', 191)->nullable();
            $table->string('helper_name', 191)->nullable();

            /*
             * রুট — জায়গার সেই এক গাছটাই (Country›…›Route)।
             *
             * আলাদা একটা "রুট" তালিকা বানালে ডিপোর ঠিকানা দুই জায়গায়
             * দুইভাবে লেখা থাকত, আর একদিন দুইটা আলাদা হয়ে যেত।
             */
            $table->foreignId('route_location_id')->nullable()
                ->constrained('mdm_locations')->nullOnDelete();

            /*
             * মিটারের পাঠ — বেরোনোর সময় ও ফেরার সময়।
             *
             * এটাই একমাত্র সংখ্যা যা দিয়ে পরে জ্বালানির খরচ ট্রিপে ভাগ
             * করা যাবে। খালি রাখা যায়: যে ডিপো মিটার লেখে না, তার
             * ট্রিপ আটকে থাকা উচিত নয়।
             */
            $table->decimal('opening_km', 18, 4)->nullable();
            $table->decimal('closing_km', 18, 4)->nullable();

            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('returned_at')->nullable();

            $table->string('status', 32)->default(DocumentStatus::DRAFT);
            $table->text('narration')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'document_no']);
            $table->index(['company_id', 'trx_date', 'status']);

            // "এই গাড়িটা এখন পথে কি না" — সবচেয়ে বেশি জিজ্ঞাসিত প্রশ্ন
            $table->index(['company_id', 'vehicle_id', 'status']);
        });

        Schema::create('sal_shipment_lines', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('shipment_id')->constrained('sal_shipments')->cascadeOnDelete();

            /*
             * restrictOnDelete — গাড়িতে ওঠা চালান মুছে ফেলা যাবে না।
             *
             * মুছতে দিলে ট্রিপের কাগজে একটা ফাঁকা সারি থেকে যেত, আর
             * "বারোটা নিয়ে বেরিয়েছিল, এগারোটার হিসাব আছে" — বাকিটা
             * কোথায় গেল তার উত্তর কারও কাছে থাকত না।
             */
            $table->foreignId('delivery_challan_id')->constrained('sal_challans')->restrictOnDelete();

            $table->unsignedInteger('line_no');

            /*
             * ফল — পথে যা হলো।
             *
             * `pending` মানে এখনো জানা যায়নি, অর্থাৎ গাড়ি ফেরেনি বা
             * সারিটা এখনো বুঝে নেওয়া হয়নি। ট্রিপ বন্ধ করতে গেলে একটাও
             * `pending` থাকা চলবে না — নাহলে "বুঝে নেওয়া হয়েছে" কথাটা
             * মিথ্যা হত।
             */
            $table->string('outcome', 32)->default('pending');
            $table->string('outcome_note', 500)->nullable();
            $table->timestamp('settled_at')->nullable();

            $table->timestamps();

            /*
             * একটা চালান এক ট্রিপে একবারই।
             *
             * ডাটাবেসেই, সেবায় নয়: দুইজন একই সময়ে একই চালান তুললে
             * সেবার পরীক্ষাটা দুইবার পাশ করত আর সারিটা দুইবার বসত।
             */
            $table->unique(['shipment_id', 'delivery_challan_id']);
            $table->index(['company_id', 'delivery_challan_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sal_shipment_lines');
        Schema::dropIfExists('sal_shipments');
    }
};
