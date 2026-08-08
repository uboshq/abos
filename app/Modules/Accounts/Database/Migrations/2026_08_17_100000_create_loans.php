<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ঋণ — দুই রকম, আর দুইটা সত্যিই আলাদা জিনিস।
 *
 * ── কেন এতদিন লাগেনি, আর কেন এখন লাগে ───────────────────────────────
 * খাতগুলো আগে থেকেই ছিল (২২১০ ব্যাংক ঋণ, ২২২০ অন্যান্য ঋণ), তাই ভাউচার
 * দিয়ে টাকা জমা-খরচ লেখা যেত। কিন্তু খাত একটা যোগফল ছাড়া কিছু জানে না।
 * সে বলতে পারে না কিস্তি কবে, কতটা আসল আর কতটা সুদ, কত বাকি, কিংবা
 * সীমার আর কতটা খালি — অথচ ঋণ নিয়ে প্রতিটা প্রশ্ন ঠিক ওগুলোই।
 *
 * ── দুই ধরনের গড়ন এক নয় ──────────────────────────────────────────
 * **টার্ম লোন** — নির্দিষ্ট টাকা, নির্দিষ্ট মেয়াদ, মাসিক কিস্তি। বকেয়া
 * কেবল কমে। প্রতিটা কিস্তির নিজের তারিখ, নিজের আসল, নিজের সুদ — তাই
 * সূচিটা সারি হয়ে থাকে (নিচের দ্বিতীয় টেবিল)।
 *
 * **CC (ক্যাশ ক্রেডিট)** — একটা সীমা, আর তার ভেতরে যত খুশি তোলা-জমা।
 * কোনো কিস্তি নেই, তাই সূচিও নেই। বকেয়া ওঠানামা করে, আর সুদ বসে
 * প্রতিদিনের বকেয়ার উপর, মাসে একবার।
 *
 * এক টেবিলে দুইটা রাখা হয়েছে কারণ বাকি সবটা এক: কে দিল, কোন খাতে,
 * কত সুদ, কী জামানত, আর খতিয়ানে কীভাবে বসে। আলাদা দুইটা টেবিল করলে
 * ওই সবটা দুইবার লিখতে হত, আর একদিন একটায় ফাঁক থাকত।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acc_loans', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();

            $table->string('document_no', 32);
            $table->string('lender', 160);

            // ব্যাংকের দেওয়া নিজস্ব নম্বর — বিবরণী মেলাতে এটাই লাগে
            $table->string('account_no', 64)->nullable();

            /*
             * ধরন — term বা cc।
             *
             * এই একটা কলামই ঠিক করে পর্দাটা কী দেখাবে: সূচি, নাকি
             * সীমা আর চলতি বকেয়া।
             */
            $table->string('kind', 8);

            /*
             * সুদের পদ্ধতি — reducing বা flat।
             *
             * মালিক জানেন না ব্যাংক কোনটা করে (২০২৬-০৮-০৯), তাই দুইটাই
             * আছে আর প্রতিটা ঋণে বেছে নেওয়া যায়। বাংলাদেশে বেশিরভাগ
             * ব্যাংক কমতি জেরে (reducing) হিসাব করে, কিন্তু SME-তে
             * ফ্ল্যাটও দেখা যায় — আর দুইটার কিস্তি সম্পূর্ণ আলাদা আসে।
             *
             * CC-তে প্রযোজ্য নয়: ওখানে সুদ প্রতিদিনের বকেয়ার উপর।
             */
            $table->string('interest_method', 16)->nullable();

            /*
             * টার্ম লোনে মঞ্জুরিকৃত টাকা; CC-তে সীমা।
             *
             * একই কলাম, কারণ দুইটাই একই প্রশ্নের উত্তর: "সর্বোচ্চ কত"।
             */
            $table->decimal('sanctioned', 18, 4);

            $table->decimal('interest_rate', 8, 4);

            // মাসে কত কিস্তি নয় — কয় মাস। CC-তে খালি।
            $table->unsignedSmallInteger('tenure_months')->nullable();

            $table->date('start_date');
            $table->date('first_instalment_on')->nullable();

            /*
             * কোন খাতে দায়টা বসে, আর টাকাটা কোথায় ঢোকে।
             *
             * দুইটাই ঋণ বসানোর সময় বেছে নেওয়া হয়: একটা ব্যাংক ঋণ
             * ২২১০-এ যায়, আত্মীয়ের ঋণ ২২২০-এ; আর টাকাটা কোন ব্যাংক
             * হিসাবে বা কোন ক্যাশে ঢুকল সেটাও প্রতিবার আলাদা।
             */
            $table->foreignId('liability_account_id')->constrained('accounts');
            $table->foreignId('interest_account_id')->constrained('accounts');

            $table->string('security', 500)->nullable();
            $table->string('narration', 500)->nullable();

            $table->string('status', 16);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'document_no']);
            $table->index(['company_id', 'kind', 'status']);
        });

        /*
         * কিস্তির সূচি — কেবল টার্ম লোনের।
         *
         * ── কেন সূচিটা আগেই লেখা হয়, চাহিদামতো গোনা হয় না ────────────
         * ব্যাংকের কাগজে কিস্তিগুলো ছাপা থাকে, আর সেগুলোই সত্য। আমরা
         * প্রতিবার নতুন করে গুণলে পয়সার ভগ্নাংশে ব্যাংকের সাথে অমিল
         * হত, আর তখন কোনটা ঠিক তা বলার উপায় থাকত না।
         *
         * সারি হিসেবে থাকায় ব্যাংক কোনো কিস্তি বদলালে (পুনঃতফসিল,
         * আংশিক পরিশোধ) সেই সারিটাই শোধরানো যায় — পুরো ঋণটা নতুন করে
         * বসাতে হয় না।
         */
        Schema::create('acc_loan_instalments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained('acc_loans')->cascadeOnDelete();

            $table->unsignedSmallInteger('no');
            $table->date('due_date');

            $table->decimal('principal', 18, 4);
            $table->decimal('interest', 18, 4);

            // পরিশোধের পর যা সত্যিই দেওয়া হল — ব্যাংক কখনো কম-বেশি নেয়
            $table->decimal('paid_amount', 18, 4)->default(0);
            $table->date('paid_on')->nullable();

            $table->string('status', 16);

            $table->timestamps();

            $table->unique(['loan_id', 'no']);
            $table->index(['loan_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acc_loan_instalments');
        Schema::dropIfExists('acc_loans');
    }
};
