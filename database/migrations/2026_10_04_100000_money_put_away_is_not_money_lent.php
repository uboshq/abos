<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * জমা — FD, DPS, সঞ্চয়পত্র। ঋণ নয়, টাকা সরিয়ে রাখা।
 *
 * ── কেন এটা `Loan`-এর ধরন নয়, ২৯ আগস্ট ২০২৬ ─────────────────────────
 * ABOS-এ FD ও DPS ছিল `Loan`-এর দুইটা `kind`। মালিক বললেন এগুলোও আলাদা
 * করতে হবে, আর DMS-এ ঠিক তাই — নিজের এনটিটি (`BankDeposit`), নিজের মেনু।
 *
 * যুক্তিটা সোজা: ঋণ মানে টাকা **খাটানো বা নেওয়া**, জমা মানে টাকা
 * **সরিয়ে রাখা**। ঋণের ঘরগুলো — জামানত, পুনর্বিবেচনার তারিখ, সুবিধার
 * ধরন — জমায় একটাও লাগে না, আর জমার ঘরগুলো — মেয়াদপূর্তির তারিখ,
 * কিস্তির দিন, মুনাফা কোথায় জমা হবে — ঋণে নেই।
 *
 * ── কেন পনেরোটা ধরন নয়, তিনটা আকৃতি ─────────────────────────────────
 * খুঁজে দেখা গেছে (nationalsavings.gov.bd আর ব্যাংকগুলোর পাতা):
 * সঞ্চয়পত্র পাঁচ রকম, ব্যাংকের স্কিম আরও দশ-বারো রকম — FDR, DPS,
 * মাসিক মুনাফা, ডাবল/ট্রিপল বেনিফিট, মিলিয়নিয়ার, শিক্ষা, বিবাহ,
 * SND, ইসলামি MTDR/MSS/MMPS। কাল আরেকটা আসবে।
 *
 * কিন্তু **টাকার চলাচলের আকৃতি মাত্র তিনটা**, আর ঘরগুলো ওটাই ঠিক করে:
 *
 *   `at_maturity`     — একবারে জমা, মেয়াদান্তে মুনাফা।
 *                       FDR · ডাবল বেনিফিট · ৫ বছর মেয়াদি সঞ্চয়পত্র
 *   `periodic_payout` — একবারে জমা, নিয়মিত মুনাফা তোলা।
 *                       মাসিক মুনাফা · ৩-মাস অন্তর · পরিবার · পেনশনার
 *   `instalment`      — মাসে মাসে জমা, মেয়াদান্তে একসাথে।
 *                       DPS/MSS · শিক্ষা · বিবাহ · মিলিয়নিয়ার
 *
 * ধরনটা তাই একটা **খোলা তালিকা** (`fin_deposit_kinds`) — কোম্পানি
 * সেটিংস থেকে সারি যোগ করে, আর প্রতিটা সারি বলে সে কোন আকৃতির।
 * enum করলে প্রথম নতুন স্কিমেই কোড বদলাতে হত।
 *
 * ── কেন "কার নামে" ঘরটা অপরিহার্য ────────────────────────────────────
 * **সঞ্চয়পত্র ব্যক্তির জিনিস — ফার্ম বা কোম্পানি কিনতে পারে না।**
 * মালিক ব্যবসার টাকায় কিনলে সঠিক হিসাব **উত্তোলন** (৩২০০), ব্যবসার
 * সম্পদ নয়। ঘরটা না থাকলে স্থিতিপত্রে এমন একটা সম্পদ বসত যা ব্যবসার
 * নয় — আর সেটা অডিটে ধরা পড়ে।
 *
 * ── কেন "সুদ" নয়, "মুনাফা"ও ─────────────────────────────────────────
 * ইসলামি ব্যাংকে শব্দটা মুনাফা, আর কাগজে ওটাই ছাপা হয়। একটা ঘর
 * বলে দেয় কোনটা, আর ছাপার সময় সেটাই বসে।
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * ধরনের খোলা তালিকা।
         *
         * বসানো সারিগুলো একটা শুরু, শেষ কথা নয় — কোম্পানি নিজের
         * ব্যাংকের স্কিম যোগ করবে।
         */
        Schema::create('fin_deposit_kinds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('code', 32);
            $table->string('name_en', 120);

            /*
             * বাংলা নামটা ঐচ্ছিক — [[TheFormSaidOptionalTheColumnSaidRequiredTest]]।
             *
             * ব্যবস্থার প্রতিটা মাস্টার তালিকায় `name_bn` ঐচ্ছিক, কারণ
             * অনেক কিছুর বাংলা নাম হয় না — "FDR"-এর বাংলা "FDR"-ই।
             * এখানে `NOT NULL` লিখে ফেলেছিলাম, আর পাহারাটা ধরল: ফর্ম
             * ঘরটা খালি নিত, তারপর ইনসার্টে ৫০০।
             */
            $table->string('name_bn', 120)->nullable();

            /* at_maturity · periodic_payout · instalment */
            $table->string('shape', 20);

            /* ব্যাংক · ডাকঘর · সঞ্চয় অধিদপ্তর — কাগজে কার নাম ছাপা */
            $table->string('issuer', 32)->default('bank');

            /*
             * ব্যক্তির জিনিস কি না।
             *
             * সঞ্চয়পত্র ফার্ম কিনতে পারে না। সত্যি হলে পর্দা "কার নামে"
             * জিজ্ঞেস করে, আর মালিকের নামে হলে টাকাটা উত্তোলনে যায়।
             */
            $table->boolean('personal_only')->default(false);

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);

            $table->timestamps();
            $table->uuid('public_id')->unique();

            $table->unique(['company_id', 'code']);
        });

        Schema::create('fin_deposits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();

            $table->string('document_no', 64);
            $table->foreignId('kind_id')->constrained('fin_deposit_kinds')->restrictOnDelete();

            /* কোথায় — ব্যাংক ও শাখা, নাকি ডাকঘর */
            $table->string('institution', 160);
            $table->string('branch_name', 160)->nullable();

            /* ব্যাংকের নিজের নম্বর — ফোনে যেটা বলা হয় */
            $table->string('reference_no', 60)->nullable();

            /*
             * কার নামে।
             *
             * `business` — ব্যবসার সম্পদ, স্থিতিপত্রে বসে।
             * `owner`    — মালিকের ব্যক্তিগত; ব্যবসার টাকা গেলে সেটা
             *              উত্তোলন, সম্পদ নয়।
             */
            $table->string('held_by', 16)->default('business');
            $table->string('holder_name', 160)->nullable();

            /* যা রাখা হয়েছে। কিস্তির জমায় এটা এ পর্যন্ত মোট, আর বাড়ে। */
            $table->decimal('principal', 18, 4)->default(0);

            $table->decimal('profit_rate', 8, 4)->nullable();

            /* সুদ না মুনাফা — ইসলামি হিসাবে শব্দটা আলাদা, কাগজে ওটাই ছাপা */
            $table->string('return_word', 16)->default('interest');

            $table->date('opened_on');
            $table->date('matures_on')->nullable();

            /*
             * কিস্তির জমার জন্য: কত আর কোন দিন।
             *
             * ── কেন দিনটাও কলাম ─────────────────────────────────────
             * একটা কিস্তি বাদ পড়লে মেয়াদান্তে টাকাটা চুপচাপ কমে যায়,
             * আর কেউ এক বছর পর টের পায়। দিনটা কলামে থাকলে সিস্টেম
             * মনে করিয়ে দিতে পারে; কারও মাথায় থাকলে পারে না।
             */
            $table->decimal('instalment_amount', 18, 4)->nullable();
            $table->unsignedTinyInteger('instalment_day')->nullable();

            /*
             * নিয়মিত মুনাফা কোথায় জমা হয়।
             *
             * মাসিক মুনাফার স্কিমে টাকাটা প্রতি মাসে একটা হিসাবে আসে।
             * কোথায় আসে তা না জানলে ওই টাকাটা খাতায় কোনোদিন বসত না।
             */
            $table->foreignId('payout_account_id')->nullable()->constrained('accounts')->nullOnDelete();

            /* খাতায় কোথায় বসে — জমা একটা সম্পদ, স্থিতিপত্রেও থাকে */
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();

            /* টাকাটা কোথা থেকে গেল */
            $table->foreignId('funded_from_account_id')->nullable()->constrained('accounts')->nullOnDelete();

            /*
             * এই জমাটা কোন ঋণের বিপরীতে বন্ধক।
             *
             * ডিপো নিজের FD বন্ধক রেখে কম সুদে ধার নেয়। জোড়াটা না
             * থাকলে জমার পাতা চুপ থাকত, আর প্রথম খবরটা আসত ব্যাংক
             * ভাঙতে না দেওয়ার দিন।
             */
            $table->foreignId('pledged_to_loan_id')->nullable()->constrained('acc_loans')->nullOnDelete();

            $table->string('status', 16)->default('active');
            $table->date('closed_on')->nullable();
            $table->text('note')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->uuid('public_id')->unique();

            $table->unique(['company_id', 'document_no']);
            $table->index(['company_id', 'status', 'matures_on']);
        });

        /*
         * জমার চলাচল — খোলা, কিস্তি, মুনাফা তোলা, ভাঙা।
         *
         * হাতধারের মতোই চিহ্ন-ভিত্তিক নয়, কারণ এখানে ঘটনাগুলো সত্যিই
         * আলাদা: কিস্তি জমা আর মুনাফা তোলা দুইটার খাতা-দাখিলা আলাদা।
         */
        Schema::create('fin_deposit_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('deposit_id')->constrained('fin_deposits')->cascadeOnDelete();

            /* opened · instalment · payout · closed */
            $table->string('kind', 16);

            $table->decimal('amount', 18, 4);
            $table->date('moved_on');

            $table->foreignId('money_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->foreignId('voucher_id')->nullable()->constrained('vouchers')->nullOnDelete();

            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->uuid('public_id')->unique();

            $table->index(['company_id', 'deposit_id', 'moved_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_deposit_movements');
        Schema::dropIfExists('fin_deposits');
        Schema::dropIfExists('fin_deposit_kinds');
    }
};
