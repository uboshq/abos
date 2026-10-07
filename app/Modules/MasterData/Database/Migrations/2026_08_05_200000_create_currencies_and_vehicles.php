<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * মুদ্রা ও তার বিনিময় হার, আর গাড়ি ও গাড়ির ধরন।
 *
 * চারটা একসাথে, কারণ দুইটা জোড়া: হার ছাড়া মুদ্রা অর্থহীন (একটা নাম
 * মাত্র), আর ধরন ছাড়া গাড়ির তালিকা রিপোর্টে ভাগ করা যায় না।
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * মুদ্রা।
         *
         * ডিফল্ট মুদ্রাটাই কোম্পানির নিজের মুদ্রা — হিসাবের বইয়ের ভাষা।
         * আলাদা একটা is_base পতাকা রাখা হয়নি: দুইটা পতাকা থাকলে একদিন
         * একটা মুদ্রা ডিফল্ট অথচ ভিত্তি নয় এমন অবস্থা তৈরি হত, আর তখন
         * "কীসের হিসাবে ৫০০ টাকা" প্রশ্নের দুইটা উত্তর থাকত।
         */
        Schema::create('mdm_currencies', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            // ISO 4217 — BDT, USD, EUR
            $table->string('code', 8);
            $table->string('name_en', 60);
            $table->string('name_bn', 60)->nullable();

            // ৳, $, € — ছাপায় ও পর্দায় অঙ্কের আগে বসে
            $table->string('symbol', 8)->nullable();

            /*
             * দশমিকের ঘর।
             *
             * সব মুদ্রায় দুই নয়: ইয়েনে শূন্য, দিনারে তিন। ধরে নিলে
             * ¥১০০ ছাপা হত ¥১০০.০০ — যা ওই দেশে ভুল লেখা।
             */
            $table->unsignedTinyInteger('decimal_places')->default(2);

            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'is_active']);
        });

        /*
         * বিনিময় হার — ইতিহাস সহ।
         *
         * ── কেন সারি, কেন মুদ্রার উপর একটা কলাম নয় ──────────────────
         * হার রোজ বদলায়। মুদ্রার সারিতে একটা কলাম রাখলে আজকের হার
         * বসানোর সাথে সাথে গত মাসের হারটা হারিয়ে যেত — আর গত মাসের
         * বিলটা আজ খুললে অন্য টাকায় দেখাত। ইতিহাস থাকলে প্রতিটা
         * লেনদেন তার নিজের দিনের হারেই থেকে যায়।
         *
         * পুরনো সারি বদলানো হয় না, নতুন সারি বসে। তাই "কে কবে হার
         * বদলেছিল" প্রশ্নের উত্তর টেবিলেই আছে।
         */
        Schema::create('mdm_exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('currency_id')->constrained('mdm_currencies')->cascadeOnDelete();

            // এই তারিখ থেকে কার্যকর — পরের সারি না আসা পর্যন্ত
            $table->date('effective_from');

            /*
             * ভিত্তি মুদ্রায় এক একক এই মুদ্রার দাম।
             *
             * USD-র হার ১১৭.৫০ মানে ১ ডলার = ১১৭.৫০ টাকা। উল্টো দিকে
             * (১ টাকা = কত ডলার) রাখলে প্রতিবার ভাগ করতে হত, আর
             * ভাগশেষে পয়সা হারাত।
             *
             * ছয় দশমিক: টাকার ঘর চার, কিন্তু হার গুণ হয় — হারে চার
             * রাখলে বড় অঙ্কে ভুল জমে যেত।
             */
            $table->decimal('rate', 18, 6);

            // কোথা থেকে পাওয়া — ব্যাংকের নাম, বা "হাতে বসানো"
            $table->string('source', 120)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // একই দিনে একই মুদ্রার দুইটা হার থাকলে কোনটা সত্যি তা
            // বলার উপায় থাকত না
            $table->unique(['company_id', 'currency_id', 'effective_from']);
            $table->index(['company_id', 'currency_id', 'effective_from']);
        });

        /*
         * গাড়ির ধরন — ট্রাক, পিকআপ, ভ্যান, রিকশা।
         *
         * সারি, enum নয়: যে প্রতিষ্ঠান নৌকায় মাল পাঠায় তার তালিকায়
         * "নৌকা" থাকা দরকার, আর সেটা যোগ করতে একটা রিলিজ লাগবে না।
         */
        Schema::create('mdm_vehicle_types', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('code', 32);
            $table->string('name_en', 60);
            $table->string('name_bn', 60)->nullable();

            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code']);
        });

        /*
         * গাড়ি ও বহর।
         *
         * চালকের নাম ও ফোন এখানে লেখা থাকে, কোনো কর্মী-রেকর্ডের দিকে
         * নয়: HR এখনো তৈরি হয়নি, আর অস্তিত্বহীন টেবিলের দিকে একটা
         * খালি কলাম রাখা মানে সেটা কেউ ভরবে না অথচ ফর্মে দেখা যাবে।
         * কর্মী এলে চালককে সেখানে বাঁধা যাবে — নাম-ফোন তখনো ভুল হবে না।
         */
        Schema::create('mdm_vehicles', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            // বহরের নিজের কোড (V-01), আর পর্দায় ডাকনাম
            $table->string('code', 32);
            $table->string('name_en', 120);
            $table->string('name_bn', 120)->nullable();

            // নম্বরপ্লেট — চালানে ও গেটপাসে এটাই ছাপা হয়
            $table->string('registration_no', 64);

            $table->foreignId('vehicle_type_id')->nullable()
                ->constrained('mdm_vehicle_types')->nullOnDelete();

            // ধারণক্ষমতা কেজিতে — কোন গাড়িতে কত মাল ওঠে
            $table->decimal('capacity_kg', 18, 4)->nullable();

            /*
             * নিজের না ভাড়ার।
             *
             * এটা সারি নয়, কারণ তালিকাটা বাড়ে না — গাড়ি হয় নিজের, নয়
             * ভাড়ার। আর ভাড়া হলে ভাড়ার খরচ বসে, নিজের হলে বসে না;
             * তাই এটা হিসাবের নিয়ম, ব্যবসার পছন্দ নয়।
             */
            $table->string('owner_type', 16)->default('own');

            $table->string('driver_name', 120)->nullable();
            $table->string('driver_phone', 32)->nullable();

            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'registration_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mdm_vehicles');
        Schema::dropIfExists('mdm_vehicle_types');
        Schema::dropIfExists('mdm_exchange_rates');
        Schema::dropIfExists('mdm_currencies');
    }
};
