<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * মজুদ — পণ্য, গুদাম ও চলাচল।
 *
 * সবচেয়ে গুরুত্বপূর্ণ সিদ্ধান্তটা তৃতীয় টেবিলে: **স্টকের কোনো "পরিমাণ"
 * কলাম নেই।** যা আছে তা চলাচলের সারি, আর "আছে কত" সেগুলোর যোগফল।
 *
 * একটা qty কলাম রাখলে প্রতিটা লেনদেনে সেটা হালনাগাদ করতে হত, আর একদিন
 * কোথাও একটা হালনাগাদ বাদ পড়ত — সমান্তরাল দুইটা বিল, একটা ব্যর্থ
 * ট্রানজেকশন, বা নতুন কোনো পথ যেটা কলামটার কথা জানে না। তখন খাতায়
 * ৫০, তাকে ৪৭, আর পার্থক্যটা কোথা থেকে এল তার কোনো উত্তর থাকত না।
 *
 * যোগফল ধীর — কিন্তু হিসাবের লেজারেও একই সিদ্ধান্ত, আর সেখানে এটা
 * প্রমাণিত। দরকার হলে পরে সারাংশ টেবিল বসানো যাবে, কিন্তু সেটা তখনই,
 * যখন সংখ্যাটা সত্যিই বড় হয় — আর তখনও সত্যের উৎস চলাচলই থাকবে।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_warehouses', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            /*
             * গুদাম শাখার নিচে।
             *
             * একটা ডিপোর তিনটা শাখা থাকলে প্রতিটার নিজের গুদাম — নাহলে
             * নেত্রকোনার মাল ময়মনসিংহের তালিকায় দেখাত, আর সেলসম্যান
             * এমন মাল বেচতেন যা তার শাখায় নেই।
             */
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();

            $table->string('code', 32);
            $table->string('name_en', 191);
            $table->string('name_bn', 191)->nullable();
            $table->string('address_en', 500)->nullable();
            $table->string('address_bn', 500)->nullable();

            // দিনশেষে মাল কোথায় ফেরে — একটাই প্রধান গুদাম
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'is_active']);
        });

        Schema::create('inv_products', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('code', 32);
            $table->string('name_en', 191);
            $table->string('name_bn', 191)->nullable();

            // বারকোড — POS-এ এটাই একমাত্র দ্রুত পথ, আর হাতে লেখা কোডের
            // সাথে মেলে না বলে আলাদা ঘর
            $table->string('barcode', 64)->nullable();

            $table->string('brand', 120)->nullable();
            $table->string('category', 120)->nullable();

            $table->foreignId('unit_id')->nullable()->constrained('mdm_units')->nullOnDelete();
            $table->foreignId('tax_id')->nullable()->constrained('mdm_taxes')->nullOnDelete();

            $table->decimal('purchase_price', 18, 4)->default(0);
            $table->decimal('sale_price', 18, 4)->default(0);

            /*
             * পুনঃক্রয়ের স্তর — কত নামলে জানাতে হবে।
             *
             * শূন্য মানে সতর্কতা নেই, শূন্যে নামলে সতর্কতা নয়। শূন্যকে
             * "০-এ নামলে বলো" ধরলে প্রতিটা নতুন পণ্যই প্রথম দিন থেকে
             * সতর্কতা দিত, আর তালিকাটা কেউ পড়ত না।
             */
            $table->decimal('reorder_level', 18, 4)->default(0);

            $table->string('status', 16)->default('confirmed');
            $table->boolean('is_active')->default(true);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'is_active']);
            $table->index(['company_id', 'barcode']);
            $table->index(['company_id', 'name_en']);
        });

        Schema::create('inv_stock_movements', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();

            $table->foreignId('product_id')->constrained('inv_products')->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained('inv_warehouses')->cascadeOnDelete();

            $table->date('trx_date');

            /*
             * কোন অবস্থায় কত নড়ল।
             *
             * তিনটা আলাদা কলাম, একটা "state" কলাম আর একটা qty নয়। কারণ
             * একটা সারি প্রায়ই একসাথে দুইটা অবস্থা বদলায়: অর্ডার
             * অনুমোদিত হলে Reserved বাড়ে আর তাকের মাল একই থাকে; মাল
             * বেরোলে Floor কমে আর Reserved-ও কমে। এক সারিতে দুইটা না
             * লিখলে ওই দুইটা আলাদা সারি হত, আর একটা বসে অন্যটা না বসার
             * সুযোগ তৈরি হত।
             */
            $table->decimal('floor_change', 18, 4)->default(0);
            $table->decimal('reserved_change', 18, 4)->default(0);
            $table->decimal('hold_change', 18, 4)->default(0);

            // আটকানোর কারণ — মাস্টার তালিকা থেকে, মুক্ত লেখা নয়।
            // দাম-হোল্ড আর ক্ষতিগ্রস্ত এক করে ফেললে রিপোর্ট মিথ্যা বলে।
            $table->foreignId('reason_code_id')->nullable()
                ->constrained('mdm_reason_codes')->nullOnDelete();

            // নিয়ম ১ — প্রতিটা চলাচল কোন ডকুমেন্ট থেকে এল
            $table->string('source_type', 64);
            $table->unsignedBigInteger('source_id');
            $table->string('document_no', 64)->nullable();
            $table->string('narration', 500)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // সবচেয়ে বেশি চলা কোয়েরি: এক পণ্যের এক গুদামের যোগফল
            $table->index(['company_id', 'product_id', 'warehouse_id']);
            $table->index(['company_id', 'trx_date']);
            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_stock_movements');
        Schema::dropIfExists('inv_products');
        Schema::dropIfExists('inv_warehouses');
    }
};
