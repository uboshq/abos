<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ব্যাংক এমন কিছু জানত যা আমাদের বইয়ে কোনোদিন ওঠেনি।
 *
 * ── কী ছিল ──────────────────────────────────────────────────────────
 * মিলকরণের পর্দা (`acc_bank_reconciliations`) আমাদের **নিজের** সারিগুলো
 * দেখায় আর জিজ্ঞেস করে "এটা কি ব্যাংকে উঠেছে?"। ⛔ কিন্তু উল্টো দিকের
 * প্রশ্নটার কোনো উত্তর ছিল না: **ব্যাংক যা কেটেছে বা জমা করেছে, অথচ
 * আমাদের বইয়ে নেই** — সেটা কোথাও দেখা যেত না।
 *
 * ⓘ আর বাস্তবে ভুলগুলো ঐ দিকেই থাকে: সার্ভিস চার্জ, এসএমএস ফি, সুদ,
 * ফেরত আসা চেক, ভুল করে ডেবিট। ⚠️ সেগুলো কেউ বইয়ে তোলে না, কারণ কেউ
 * জানেই না ওগুলো ঘটেছে — জানার একমাত্র পথ ব্যাংকের স্টেটমেন্ট।
 *
 * ── মানচিত্র §৯: "ব্যাংক স্টেটমেন্ট আমদানি" ─────────────────────────
 * এই টেবিলটা ব্যাংকের নিজের সারিগুলো ধরে রাখে — আমাদের খতিয়ান নয়,
 * **ব্যাংকের বক্তব্য**। দুইটা পাশাপাশি রাখলে তবেই "কী মেলেনি" প্রশ্নের
 * উত্তর দেওয়া যায়।
 *
 * ⛔ এখান থেকে কোনো দাখিলা নিজে থেকে বসে না, আর সেটা ইচ্ছাকৃত: ব্যাংক
 * "SERVICE CHARGE 230" লিখলে সেটা কোন খরচের খাতে যাবে তা ব্যাংক জানে
 * না, আমরা জানি। ⓘ তাই সারিটা দেখানো হয়, আর মানুষ ভাউচার বসান।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acc_bank_statement_lines', function (Blueprint $table) {
            $table->id();
            $table->publicId();

            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();

            /* কোন ব্যাংকের খাতা — আমাদের ছকের খাত, ব্যাংকের নাম নয় */
            $table->foreignId('bank_account_id')->constrained('accounts')->cascadeOnDelete();

            $table->date('trx_date');

            /*
             * ⓘ ব্যাংকের লেখা হুবহু — আমাদের ভাষায় অনুবাদ করা হয় না।
             * ⚠️ "BEFTN/SALARY/SEP" দেখতে কুৎসিত, কিন্তু ঐ লেখাটাই মানুষ
             * ব্যাংকের কাগজে খুঁজবেন; বদলে দিলে আর মেলানো যেত না।
             */
            $table->string('description', 255)->nullable();

            /* চেক নম্বর বা ব্যাংকের লেনদেন নম্বর — মেলানোর সবচেয়ে ভালো সূত্র */
            $table->string('reference', 60)->nullable();

            $table->decimal('debit', 18, 4)->default(0);
            $table->decimal('credit', 18, 4)->default(0);

            /*
             * ⓘ ব্যাংকের চলতি জের — ঐচ্ছিক, কারণ সব ব্যাংক দেয় না।
             * ⚠️ থাকলে এটাই সবচেয়ে ভালো পাহারা: শেষ সারির জের আর
             * স্টেটমেন্টের ক্লোজিং না মিললে ফাইলটাই অসম্পূর্ণ।
             */
            $table->decimal('balance', 18, 4)->nullable();

            /*
             * ⛔ একই ফাইল দুইবার তুললে সারি দ্বিগুণ হবে না।
             *
             * ── ⚠️ কেন কেবল তারিখ+টাকা যথেষ্ট নয় ───────────────────
             * একই দিনে একই পরিমাণের দুইটা সত্যিকারের লেনদেন থাকতেই পারে
             * (দুইবার ৫০০ টাকা তোলা)। ⓘ তাই ছাপটার ভিতরে ঐ দিনের কত
             * নম্বর একই রকম সারি, সেটাও ঢোকে — দুইটা আলাদা সারি আলাদাই
             * থাকে, কিন্তু একই ফাইল আবার তুললে দুইটাই চেনা পড়ে।
             */
            $table->string('fingerprint', 64);

            /*
             * আমাদের বইয়ের যে সারিটার সাথে মিলেছে — না মিললে `null`,
             * আর ঐ `null`-গুলোই আসল প্রশ্ন।
             *
             * ── ⚠️ কেন `voucher_lines`, `ledger_entries` নয় ──────────
             * মিলকরণের টিকটা আগে থেকেই ভাউচারের সারিতে বসে
             * (`voucher_lines.reconciliation_id`), তাই ব্যাংকের সারিও
             * ওখানেই জোড়া লাগে — দুই পাশ এক ভাষায় কথা বলে।
             * ⛔ `ledger_entries` ছোঁয়া হয় না: ওটা কেবল-যোগ, হ্যাশ-শিকলে
             * বাঁধা খাতা, আর ব্যাংকের খবর সেখানে ঢুকলে শিকলটার মানে
             * বদলে যেত।
             */
            $table->foreignId('matched_line_id')->nullable()
                ->constrained('voucher_lines')->nullOnDelete();

            $table->timestamp('matched_at')->nullable();
            $table->foreignId('matched_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            /* ⚠️ নাম ছোট রাখা — জেনারেট করা নাম ৬৪ অক্ষর ছাড়ালে migrate ভাঙে */
            $table->unique(['company_id', 'bank_account_id', 'fingerprint'], 'acc_stmt_line_once');
            $table->index(['company_id', 'bank_account_id', 'trx_date'], 'acc_stmt_line_date');
            $table->index(['company_id', 'matched_line_id'], 'acc_stmt_line_matched');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acc_bank_statement_lines');
    }
};
