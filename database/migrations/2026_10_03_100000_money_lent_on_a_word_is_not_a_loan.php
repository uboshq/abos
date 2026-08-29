<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * হাতধার — ঋণ নয়, একজন মানুষের সাথে একটা চলমান হিসাব।
 *
 * ── কেন এটা ঋণের একটা ধরন নয়, ২৯ আগস্ট ২০২৬ ─────────────────────────
 * ABOS-এ এতদিন হাতধার ছিল `Loan`-এর একটা `kind`। মালিক বললেন ওটা হবে
 * না — **এটা সম্পূর্ণ আলাদা জিনিস**, আর DMS-এ ঠিক তাই: নিজের এনটিটি,
 * নিজের মেনু, নিজের হিসাব।
 *
 * কারণটা DMS-এর কোডেই লেখা, আর সেটা ঠিক: একটা ঋণ বয়ে বেড়ায় কিস্তির
 * সূচি, সুদের পদ্ধতি, জামানত, পুনর্বিবেচনার তারিখ আর সুবিধার ধরন —
 * হাতধারে এর একটাও সত্যি নয়। জোর করে ঢোকালে সূচিটা কল্পনা হয়ে যায়,
 * আর যে একটা প্রশ্ন মানুষ সত্যিই করে — **করিম কত ফেরত দিয়েছে?** —
 * সেটা অর্থহীন যন্ত্রপাতির নিচে চাপা পড়ে।
 *
 * ── কেন হিসাব, ঋণের সারি নয় ──────────────────────────────────────────
 * পাঁচ হাজার দিলাম, দুই হাজার ফেরত এল, আরও তিন হাজার দিলাম — এটা
 * তিনটা ঋণ নয়, একটা সম্পর্ক আর তার একটা ব্যালেন্স। চলাচলগুলোর চিহ্ন
 * থাকে, আর ব্যালেন্স ওদের থেকেই বেরোয় — এতেই "কে আমার কাছে পায়, আর
 * আমি কার কাছে পাই" একটাই তালিকা হয়, দুইটা রিপোর্ট নয় যেগুলো কাউকে
 * মিলিয়ে দেখতে হয়।
 *
 * ── কেন মানুষটা পক্ষের তালিকা থেকে নয় ───────────────────────────────
 * এদের বেশিরভাগ গ্রাহকও নন, সরবরাহকারীও নন, আর কোনোদিন হবেনও না।
 * চাচাতো ভাইকে পাঁচ হাজার ধার দিতে গেলে আগে একটা গ্রাহক রেকর্ড বানাতে
 * হবে — এভাবেই একটা ফিচার অব্যবহৃত থেকে যায়।
 *
 * তবু `partner_id` আছে: যে ডিলার ব্যক্তিগতভাবে ধার নেন তিনি ওই
 * ডিলারই, আর তাঁর খতিয়ান যিনি পড়ছেন তাঁর এটাও খুঁজে পাওয়া উচিত।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_hand_loan_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();

            $table->string('person_name', 160);
            $table->string('mobile', 30)->nullable();

            /* একই মানুষ যখন ডিলার বা সরবরাহকারীও — জোড়াটা ঐচ্ছিক */
            $table->unsignedBigInteger('partner_id')->nullable();
            $table->string('partner_type', 32)->nullable();

            $table->text('note')->nullable();

            /*
             * খোলা, নাকি চুকে গেছে।
             *
             * চুকে যাওয়া একটা অবস্থা, মুছে ফেলা নয় — ইতিহাসটা থাকে।
             * নিয়ম ৫, আর এখানে ওটা বিশেষভাবে দরকারি: "তুমি তো ফেরত
             * দাওনি" কথাটার উত্তর ওই পুরনো সারিগুলোই।
             */
            $table->string('status', 16)->default('active');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->uuid('public_id')->unique();

            $table->index(['company_id', 'status', 'person_name']);
        });

        Schema::create('fin_hand_loan_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('fin_hand_loan_accounts')->cascadeOnDelete();

            /*
             * ডিপোর দিক থেকে চিহ্ন — আর এটাই গোটা মডেল।
             *
             * `out` — টাকা এখান থেকে গেল: ধার দিলাম, বা তাঁর টাকা ফেরত
             *         দিলাম।
             * `in`  — টাকা এখানে এল: ধার নিলাম, বা তিনি ফেরত দিলেন।
             *
             * "ধার" আর "ফেরত" আলাদা ধরন হিসেবে লিখতে গেলে চারটা ধরন
             * লাগত, আর কোনটার পরে কোনটা আসতে পারে তার একটা নিয়ম — আর
             * সেই নিয়মটা প্রথমবারেই ভুল হয় যখন আগেরটা ফেরত আসার আগেই
             * কেউ আবার ধার নেন।
             */
            $table->string('direction', 4);
            $table->decimal('amount', 18, 4);
            $table->date('moved_on');

            /*
             * কোন টিল বা ব্যাংক থেকে গেল, বা কোথায় এল।
             *
             * টাকাটা ব্যবসার, তাই ক্যাশ বইকে ওটা দেখতে হবে। যে হাতধারে
             * কোনো টাকার খাত নড়ে না, সেটা কেউ নিজের পকেট থেকে দিয়েছেন
             * — অন্য ব্যবস্থা, আর এটা সেটা নয়।
             */
            $table->foreignId('money_account_id')->nullable()->constrained('accounts')->nullOnDelete();

            /* যে দাখিলাটা হলো — পর্দার প্রতিটা সংখ্যা ওখানেই খোলে (নিয়ম ১) */
            $table->foreignId('voucher_id')->nullable()->constrained('vouchers')->nullOnDelete();

            $table->text('note')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->uuid('public_id')->unique();

            $table->index(['company_id', 'account_id', 'moved_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_hand_loan_movements');
        Schema::dropIfExists('fin_hand_loan_accounts');
    }
};
