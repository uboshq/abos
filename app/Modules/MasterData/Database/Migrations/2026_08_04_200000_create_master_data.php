<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Master Data-র টেবিলগুলো।
 *
 * সবগুলো একটাই মাইগ্রেশনে, কারণ এগুলো একসাথেই অর্থবহ: একটা ছাড়া
 * অন্যটা বসালে মডিউলটা অর্ধেক কাজ করত, আর ঠিক কোন অর্ধেক তা মনে
 * রাখতে হত।
 *
 * সব টেবিলের নামের আগে mdm_ — মাস্টার ডাটার টেবিল বলে চেনা যায়, আর
 * "units" বা "taxes"-এর মতো সাধারণ নাম অন্য মডিউলের সাথে সংঘর্ষে যায় না।
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * এলাকার একটাই গাছ — দেশ › বিভাগ › অঞ্চল › এরিয়া › টেরিটরি ›
         * পয়েন্ট › রুট।
         *
         * দুইটা আলাদা টেবিল (এলাকা ও রুট) বানালে "এই রুটটা কোন
         * টেরিটরিতে" প্রশ্নের উত্তর দুই জায়গা জোড়া দিয়ে বের করতে হত,
         * আর একটা স্তর যোগ করতে গেলে দুইটাই বদলাতে হত।
         *
         * অঞ্চল ও টেরিটরি সুইচযোগ্য: ছোট প্রতিষ্ঠানে ওই দুই স্তর
         * অর্থহীন, আর বাধ্যতামূলক করলে সবাই একটা ভুয়া "মূল অঞ্চল"
         * বানিয়ে রাখত।
         */
        Schema::create('mdm_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('mdm_locations')->nullOnDelete();

            $table->string('code', 32);
            $table->string('name_en', 120);
            $table->string('name_bn', 120)->nullable();

            // country · division · region · area · territory · point · route
            $table->string('level', 16);

            // রুটে কে যায় — ডেলিভারি ও আদায়ের দায়িত্ব
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();

            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'level', 'is_active']);
            $table->index(['company_id', 'parent_id']);
        });

        /*
         * একক ও তার রূপান্তর।
         *
         * রূপান্তর একই টেবিলে, আলাদা নয়: "১ কার্টন = ১২ পিস" আসলে
         * কার্টনের নিজের একটা বৈশিষ্ট্য — কার্টন কীসের কার্টন আর কয়টা।
         * আলাদা টেবিলে রাখলে একটা এককের দুইটা রূপান্তর বসানো যেত, আর
         * তখন কোনটা সত্যি তা বলার উপায় থাকত না।
         */
        Schema::create('mdm_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('code', 16);
            $table->string('name_en', 60);
            $table->string('name_bn', 60)->nullable();

            // কার এককে হিসাব হবে — ভিত্তি একক (পিস) নিজের দিকে দেখায় না
            $table->foreignId('base_unit_id')->nullable()->constrained('mdm_units')->nullOnDelete();

            /*
             * ভিত্তি এককে কতটা।
             *
             * DECIMAL, INT নয়: ওজনে বিক্রি হয় এমন পণ্যে "১ বস্তা = ২৫
             * কেজি" স্বাভাবিক, আর "১ কেজি = ০.০০১ টন"-ও লাগে।
             */
            $table->decimal('factor', 18, 6)->default(1);

            // ভগ্নাংশে বিক্রি হয় কি না — পিস হয় না, কেজি হয়
            $table->boolean('allows_fraction')->default(false);

            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code']);
        });

        /*
         * ভ্যাট ও অন্যান্য কর।
         *
         * হার সংরক্ষিত হয় করের সাথে, লেনদেনের সাথে নয় — কিন্তু লেনদেনে
         * বসার সময় হারটা কপি হয়ে যায়। কারণ হার বদলায়: ২০২৬-এ ৭.৫%
         * থেকে ১০% হলে পুরনো বিলগুলোর হার বদলে যাওয়া চলবে না।
         */
        Schema::create('mdm_taxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('code', 16);
            $table->string('name_en', 60);
            $table->string('name_bn', 60)->nullable();

            $table->decimal('rate', 8, 4)->default(0);

            // vat · ait · vds · sd — কোন ধরনের কর
            $table->string('kind', 16)->default('vat');

            /*
             * দামের ভেতরে না বাইরে।
             *
             * বাংলাদেশে খুচরায় দাম প্রায়ই ভ্যাট-সহ বলা হয় ("১১৫ টাকা"),
             * পাইকারিতে ভ্যাট আলাদা। একই পণ্যে দুই রকম হয়, তাই এটা
             * করের বৈশিষ্ট্য, প্রতিষ্ঠানের নয়।
             */
            $table->boolean('is_inclusive')->default(false);

            // আদায় করা ভ্যাট কোন খাতে জমা হবে
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();

            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code']);
        });

        /*
         * পরিশোধের শর্ত — "নগদ", "৭ দিন", "৩০ দিন"।
         *
         * দিনের সংখ্যাটা গ্রাহকের ক্রেডিট দিনের সাথে এক নয়: গ্রাহকের
         * ঘরে সাধারণ শর্ত থাকে, কিন্তু একটা নির্দিষ্ট বিলে অন্য শর্ত
         * দেওয়া যায়।
         */
        Schema::create('mdm_payment_terms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('code', 16);
            $table->string('name_en', 60);
            $table->string('name_bn', 60)->nullable();

            $table->unsignedSmallInteger('days')->default(0);

            // সময়মতো দিলে ছাড় — "১০ দিনে দিলে ২%"
            $table->decimal('early_discount_percent', 8, 4)->default(0);
            $table->unsignedSmallInteger('early_discount_days')->default(0);

            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code']);
        });

        /*
         * দর তালিকা — খুচরা, পাইকারি, ডিলার।
         *
         * পণ্যের দর এখানে নয়, mdm_price_list_items-এ — কারণ একই তালিকায়
         * হাজারটা পণ্য থাকে। তালিকাটা শুধু "কোন দরের সেট" বলে।
         *
         * পণ্যের টেবিল এখনো নেই (Phase 6), তাই আইটেমের টেবিলটাও তখন —
         * খালি একটা টেবিল আগে বানিয়ে রাখলে সেটা ফাঁকা পড়ে থাকত আর কেউ
         * বুঝত না কেন।
         */
        Schema::create('mdm_price_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('code', 16);
            $table->string('name_en', 60);
            $table->string('name_bn', 60)->nullable();

            // কোন গ্রাহকের ধরনে এই দর ডিফল্ট
            $table->foreignId('party_type_id')->nullable();

            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code']);
        });

        /*
         * পক্ষের ধরন — খুচরা, পাইকারি, ডিলার, প্রতিষ্ঠান।
         *
         * enum নয়, সারি: একটা প্রতিষ্ঠান "সাব-ডিলার" নামে নতুন ধরন যোগ
         * করতে চাইলে সেটা সেটিংস থেকেই হবে। enum লিখলে প্রতিটা নতুন
         * ধরনের জন্য একটা রিলিজ লাগত, আর ততদিন কেউ "অন্যান্য" লিখে
         * কাজ চালাত।
         *
         * গ্রাহক ও সরবরাহকারী দুইটার জন্যই একই টেবিল, applies_to দিয়ে
         * আলাদা করা।
         */
        Schema::create('mdm_party_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('code', 16);
            $table->string('name_en', 60);
            $table->string('name_bn', 60)->nullable();

            // customer · supplier · both
            $table->string('applies_to', 16)->default('customer');

            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code']);
        });

        /*
         * কারণ কোড — ফেরত, সমন্বয়, বাতিল।
         *
         * "কেন ফেরত এল" মুক্ত লেখায় নিলে দুইশো রকম বানান জমত, আর
         * "কোন কারণে সবচেয়ে বেশি ফেরত আসে" প্রশ্নের উত্তর বের করা যেত
         * না। তালিকা থেকে বাছলে সেটা গোনা যায়।
         */
        Schema::create('mdm_reason_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('code', 16);
            $table->string('name_en', 120);
            $table->string('name_bn', 120)->nullable();

            // sales_return · purchase_return · stock_adjustment · cancellation · discount
            $table->string('context', 32);

            /*
             * ফেরত পণ্যটা আবার বিক্রি করা যাবে কি না।
             *
             * "গ্রাহক পছন্দ করেনি" আর "মেয়াদ শেষ" — দুইটাই ফেরত, কিন্তু
             * একটা স্টকে ফেরে আর অন্যটা নষ্ট। কারণ কোডে এটা না বললে
             * প্রতিবার হাতে ঠিক করতে হত, আর কেউ ভুল করলে নষ্ট মাল
             * আবার বিক্রি হয়ে যেত।
             */
            $table->boolean('returns_to_stock')->default(true);

            $table->boolean('needs_approval')->default(false);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'context', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mdm_reason_codes');
        Schema::dropIfExists('mdm_party_types');
        Schema::dropIfExists('mdm_price_lists');
        Schema::dropIfExists('mdm_payment_terms');
        Schema::dropIfExists('mdm_taxes');
        Schema::dropIfExists('mdm_units');
        Schema::dropIfExists('mdm_locations');
    }
};
