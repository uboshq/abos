<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * খরচ ভাউচারের চৌদ্দটা ঘর, আর পর্দায় ছিল তিনটা।
 *
 * ── ⛔ কী ধরা পড়েছিল, ১৫ সেপ্টেম্বর ২০২৬ ─────────────────────────────
 * মালিক নমুনা আর আসল ফর্ম পাশাপাশি রেখে দেখালেন। গুনে দেখা গেল নমুনার
 * **চৌদ্দটা ঘরের মাত্র তিনটা** পর্দায় আছে — তারিখ, যে খাত থেকে, বিবরণ।
 *
 * ⚠️ আর যেগুলো নেই সেগুলোই খরচ ভাউচারকে খরচ ভাউচার বানায়: খরচের খাত,
 * খরচের কেন্দ্র, বিল নম্বর, উৎসে কর্তন, আর সবচেয়ে বড়টা — **কোন
 * চালানের জন্য**।
 *
 * ── ⭐ চালান-ট্যাগটা কেন সবচেয়ে জরুরি ───────────────────────────────
 * মালিকের নিজের কথায়: *"ইনভয়েস ট্যাগ করলেই ডাইরেক্ট এক্সপেন্স আর
 * ইনডাইরেক্ট ট্যাগ করা সহজ হবে"*। ⓘ ভাড়া-হাম্মালি কোন মালের দামে উঠবে
 * সেটা এই ট্যাগ ছাড়া কেউ বলতে পারে না, আর তখন **"কোন পণ্যে কত লাভ"
 * প্রশ্নের উত্তরটাই ভুল** থাকে।
 *
 * ⛔ আর ব্যবস্থাটা আটকায় না, দেখায় — মালিকের নির্দেশ:
 * *"আটকে দেব না। দেখিয়ে দেব।"*
 *
 * ── ⚠️ কেন কেবল পর্দা বানালে হত না ──────────────────────────────────
 * ঘরগুলো ডাটাবেজে না থাকলে ব্যবহারকারী পূরণ করতেন, সংরক্ষণ করতেন, আর
 * লেখাগুলো **নীরবে হারিয়ে যেত** — কোনো ভুল বার্তা ছাড়াই। ⓘ আজ সারাদিন
 * এই শ্রেণির ছয়টা ভুল ধরা পড়েছে, তাই কলাম আগে, পর্দা পরে।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vouchers', function (Blueprint $table): void {
            /*
             * খরচের কেন্দ্র — কোন ডিপো, গুদাম, অফিস বা গাড়ি।
             *
             * ⓘ `branch_id` থাকতেই পারে, কিন্তু সেটা **কোথায় বসে লেখা
             * হলো** তা বলে; এটা বলে **কার খরচ**। ⚠️ একজন প্রধান অফিসে
             * বসে নেত্রকোনা গুদামের বিদ্যুৎ বিল লিখতে পারেন, আর তখন
             * দুইটা দুই রকম।
             */
            $table->foreignId('cost_centre_id')->nullable()->after('branch_id')
                ->constrained('branches')->nullOnDelete();

            /*
             * খরচের খাত — ৫৩১০ পরিবহন ভাড়া, ৫৩২০ বিদ্যুৎ বিল…
             *
             * ⓘ `money_account_id` টাকা কোথা থেকে গেল তা ধরে; এটা ধরে
             * **কোন খাতে বসল**। দুইটা আলাদা প্রশ্ন, আর একটা ঘরে রাখলে
             * খরচের হিসাবটাই করা যেত না।
             */
            $table->foreignId('expense_account_id')->nullable()->after('money_account_id')
                ->constrained('accounts')->nullOnDelete();

            // সরবরাহকারীর নিজের বিল নম্বর — ছয় মাস পরে মিলিয়ে দেখার একমাত্র সূত্র
            $table->string('bill_no', 60)->nullable()->after('document_no');

            /*
             * উৎসে কর্তন — বিলের মোট থেকে যা কাটা হলো।
             *
             * ⚠️ `amount` হাতে যাওয়া টাকা, `gross_amount` বিলের মোট।
             * ⓘ দুইটা এক নয়, আর পার্থক্যটা সরকারের ঘরে যায় (২১২১)।
             * শতাংশ নয়, **টাকার অঙ্কই** রাখা হয়: হার বদলায়, আর পুরনো
             * ভাউচার তখন নতুন হারে হিসাব করলে ভুল দেখাত।
             */
            $table->decimal('gross_amount', 18, 4)->nullable()->after('amount');
            $table->decimal('ait_amount', 18, 4)->default(0)->after('gross_amount');
            $table->decimal('vds_amount', 18, 4)->default(0)->after('ait_amount');

            $table->index(['company_id', 'expense_account_id'], 'vouchers_expense_head_index');
            $table->index(['company_id', 'cost_centre_id'], 'vouchers_cost_centre_index');
        });

        /*
         * কোন চালানের জন্য — এক খরচ, একাধিক চালান।
         *
         * ── ⭐ কেন আলাদা টেবিল ──────────────────────────────────────
         * মালিকের কথা: *"এক ট্রাকে একাধিক চালান এলে সবগুলোই বাছুন"*।
         * ⓘ ভাউচারে একটা `bill_id` রাখলে ঐ ট্রাকের দ্বিতীয় চালানটা
         * কোনোদিন ভাড়া পেত না।
         *
         * ⚠️ আর `share_amount` এখানেই লেখা থাকে, হিসাব করে বের করা হয়
         * না — কারণ ভাগের অনুপাত (পরিমাণ/মূল্য/ওজন) পরে বদলালে পুরনো
         * ভাউচারের ভাগও বদলে যেত, আর অনুমোদিত কাগজ নিজে থেকে বদলায় না।
         */
        Schema::create('acc_voucher_bill_shares', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('voucher_id')->constrained('vouchers')->cascadeOnDelete();
            $table->foreignId('purchase_bill_id')->constrained('pur_bills')->cascadeOnDelete();
            $table->decimal('share_amount', 18, 4);

            /*
             * কীসের অনুপাতে ভাগ হয়েছিল — পরিমাণ, মূল্য, না ওজন।
             *
             * ⓘ সংখ্যাটা উপরে লেখা আছেই; এটা রাখা হয় **কেন ঐ সংখ্যা**
             * তার উত্তর দিতে। ছয় মাস পরে কেউ প্রশ্ন করলে এটাই একমাত্র
             * সাক্ষী।
             */
            $table->string('basis', 12)->default('qty');
            $table->timestamps();

            $table->unique(['voucher_id', 'purchase_bill_id'], 'voucher_bill_share_unique');
            $table->index('purchase_bill_id', 'bill_share_bill_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acc_voucher_bill_shares');

        Schema::table('vouchers', function (Blueprint $table): void {
            $table->dropIndex('vouchers_expense_head_index');
            $table->dropIndex('vouchers_cost_centre_index');
            $table->dropConstrainedForeignId('cost_centre_id');
            $table->dropConstrainedForeignId('expense_account_id');
            $table->dropColumn(['bill_no', 'gross_amount', 'ait_amount', 'vds_amount']);
        });
    }
};
