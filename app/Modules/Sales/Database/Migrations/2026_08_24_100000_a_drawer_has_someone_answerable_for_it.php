<?php

declare(strict_types=1);

use App\Modules\Accounts\Models\CashTill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * শিফট — ড্রয়ারটার জন্য কেউ একজন দায়ী।
 *
 * ── কেন এটা দরকার ────────────────────────────────────────────────────
 * দিনশেষে ড্রয়ারে তিনশো টাকা কম। প্রশ্নটা তখন "কার কাছে জিজ্ঞেস করব"।
 * শিফট ছাড়া উত্তরটা "যে কেউ" — সকালের লোক, দুপুরের লোক, বা কেউ না।
 * আর যে প্রশ্নের উত্তর "যে কেউ", সেটা আসলে কেউ জিজ্ঞেস করে না, আর
 * ঘাটতিটা প্রতি সপ্তাহে ফিরে আসে।
 *
 * ── কেন কেবল গোনা সংখ্যাটাই রাখা হয় ─────────────────────────────────
 * খোলা আর বন্ধের সময় মানুষ যা গুনেছেন — কেবল সেই দুইটা সংখ্যা নতুন
 * তথ্য। "খাতা কী বলে" আর "পার্থক্য কত" দুইটাই খতিয়ান থেকে গোনা যায়,
 * তাই কলাম হিসেবে রাখা হয় না: রাখলে একদিন একটা বাতিল হওয়া আদায়ের পর
 * জমানো সংখ্যাটা পুরনো হয়ে যেত, আর কোনটা সত্যি তা বলার উপায় থাকত না।
 * (একই যুক্তিতে CashTill-এও ব্যালেন্স কলাম নেই।)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sal_counter_shifts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();

            /*
             * কোন ড্রয়ার — টিলই ড্রয়ার।
             *
             * restrictOnDelete: যে টিলের শিফটের ইতিহাস আছে সেটা মুছে
             * ফেলা যাবে না। মুছলে "ওই দিনের ঘাটতিটা কোন ড্রয়ারে" প্রশ্নের
             * উত্তর হারাত।
             */
            /*
             * টেবিলের নামটা মডেল থেকে, হাতে লেখা নয়।
             *
             * `acc_cash_tills` ধরে নিয়েছিলাম — বাকি হিসাবের টেবিলগুলোর
             * মতো — আর নামটা আসলে `cash_tills`, উপসর্গ ছাড়া। ভুলটার
             * শাস্তি ছিল অপ্রীতিকর: CREATE TABLE চলে গিয়েছিল, তারপর
             * বিদেশি কী-র ALTER ব্যর্থ হয়েছে, আর MySQL-এ DDL লেনদেনের
             * বাইরে বলে টেবিলটা রয়ে গিয়েছিল অথচ মাইগ্রেশন নথিভুক্ত
             * হয়নি — পরের বার "table already exists"।
             */
            $table->foreignId('cash_till_id')
                ->constrained((new CashTill)->getTable())
                ->restrictOnDelete();

            // কে বসেছিলেন — দায়টা তাঁরই
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();

            // সময়টা মানুষের পড়ার জন্য; সীমানা টানা হয় নিচের সারি-নম্বরে
            $table->timestamp('opened_at');
            $table->decimal('opening_counted', 18, 4)->default(0);

            $table->timestamp('closed_at')->nullable();

            /*
             * শিফটের সীমানা — খতিয়ানের সারি-নম্বর, ঘড়ি নয়।
             *
             * ── কেন ঘড়ি দিয়ে হয় না ──────────────────────────────────
             * খতিয়ানের সারিতে `created_at` সেকেন্ড-নির্ভুল। এক শিফট
             * বন্ধ করে পরেরটা খোলা এক সেকেন্ডের ভেতরেই ঘটে (শিফট বদলের
             * সময় ঠিক তা-ই হয়), আর তখন ওই সেকেন্ডের বিক্রিটা কোন
             * শিফটের তা বলার উপায় থাকে না — সকালের ঘাটতি বিকেলের লোকের
             * ঘাড়ে পড়ে।
             *
             * শিফটের টেবিলে মাইক্রোসেকেন্ড বসিয়ে দেখেছি; তাতে অবস্থা
             * খারাপ হয়েছে, কারণ খতিয়ানের সংখ্যাটা তখনো সেকেন্ডেই
             * থেমে আছে — অর্থাৎ তুলনাটাই অসম।
             *
             * সারি-নম্বর ক্রমিক ও অনন্য, আর ওটাই একমাত্র জিনিস যা
             * সত্যিই "আগে না পরে" বলতে পারে।
             *
             * খোলার সময় শেষ সারিটা কত, সেটাই `opening_ledger_id`; এই
             * শিফটের সারিগুলো তার পরের।
             */
            $table->unsignedBigInteger('opening_ledger_id')->nullable();
            $table->unsignedBigInteger('closing_ledger_id')->nullable();

            /*
             * বন্ধের সময় গোনা নগদ — খালি থাকে যতক্ষণ শিফট খোলা।
             *
             * শূন্য নয়, খালি: শূন্য মানে "গুনে দেখলাম কিছু নেই", আর খালি
             * মানে "এখনো গোনা হয়নি"। দুইটা এক করলে খোলা শিফটও ঘাটতির
             * তালিকায় উঠত।
             */
            $table->decimal('closing_counted', 18, 4)->nullable();

            $table->string('status', 20);
            $table->string('narration', 500)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            /*
             * এক টিলে একটার বেশি খোলা শিফট নয়।
             *
             * ── কেন ইনডেক্স, কেবল কোডের পরীক্ষা নয় ──────────────────
             * দুইজন একই মুহূর্তে "শিফট খুলুন" চাপলে দুইজনেই দেখতেন
             * কোনো খোলা শিফট নেই, আর দুইটাই বসে যেত। তখন এক ড্রয়ারে
             * দুইজন দায়ী — অর্থাৎ কেউ দায়ী নয়, যেটা শিফট না থাকার
             * সমান।
             *
             * MySQL-এ NULL কখনো সংঘর্ষ করে না, তাই বন্ধ শিফটে
             * `open_marker` NULL রাখা হয় আর একই টিলে যত খুশি বন্ধ
             * শিফট থাকতে পারে। খোলা শিফটে ওটা ১, তাই দ্বিতীয়টা
             * ডাটাবেজেই আটকে যায়।
             */
            $table->unsignedTinyInteger('open_marker')->nullable();
            $table->unique(['cash_till_id', 'open_marker'], 'uq_sal_shift_one_open_per_till');

            $table->index(['company_id', 'status']);
            $table->index(['user_id', 'opened_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sal_counter_shifts');
    }
};
