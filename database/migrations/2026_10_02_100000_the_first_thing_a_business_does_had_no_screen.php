<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * মূলধন ও উত্তোলন — ব্যবসার প্রথম কাজটার কোনো পর্দা ছিল না।
 *
 * ── কেন এই দুইটা টেবিল ───────────────────────────────────────────────
 * মালিক ব্যবসার পথটা ক্রমে বললেন: **প্রথমে মূলধন, তারপর বিনিয়োগ**,
 * তারপর গুদাম, সরবরাহকারী, ক্রয়… এগারোটা ধাপের দশটা ABOS-এ ছিল। প্রথমটা
 * ছিল না।
 *
 * খাত ছিল (`3100` মালিকের মূলধন, `3200` উত্তোলন), ভাউচার দিয়ে টাকাটা
 * ঢোকানোও যেত — কিন্তু পর্দা ছিল না। ফলে ব্যবসার সবচেয়ে প্রথম কাজটা
 * হত একটা হাতে লেখা জাবেদা, বিবরণে "ওপেনিং" লিখে। কে কত দিয়েছেন, কার
 * অংশ কত — কোথাও লেখা থাকত না।
 *
 * ── কেন "লেখা" আর "পোস্ট" আলাদা ─────────────────────────────────────
 * "মালিক পাঁচ লাখ দেবেন" কথাটা যেদিন হয়, টাকাটা আসে অন্যদিন — কখনো
 * অন্য মাসে। দুইটাকে এক করলে হয় প্রতিশ্রুতিটা খাতায় ঢুকে যেত (যা
 * মিথ্যা), নয় সিদ্ধান্তটা কোথাও লেখাই থাকত না।
 *
 * পোস্ট করার সময় জিজ্ঞেস করা হয় টাকাটা **কোথায় এসেছে** — সিন্দুকে না
 * ব্যাংকে। ওটা না জেনে এন্ট্রি লেখা যায় না।
 *
 * ── কেন উত্তোলনে মাসিক সীমা ─────────────────────────────────────────
 * ট্রেডিং ব্যবসায় টাকা ফুরানোর সবচেয়ে সাধারণ কারণ বকেয়া নয় — মালিকের
 * এমন এক মাসের বিপরীতে টাকা তোলা যে মাসটা এখনো আয়ই করেনি। সীমাটা
 * মালিককে নিজের থেকে বাঁচায় না; ওটা **আগামী সপ্তাহের সরবরাহকারীকে**
 * এই সপ্তাহের সিদ্ধান্ত থেকে বাঁচায়।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acc_capital_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();

            $table->string('document_no', 64);

            /*
             * কে দিলেন — নাম, আর কোন পরিচয়ে।
             *
             * ── কেন পক্ষের তালিকা থেকে নয় ────────────────────────────
             * মালিক গ্রাহকও নন, সরবরাহকারীও নন। ওই তালিকায় ঢোকালে
             * বকেয়ার রিপোর্টে মালিকের নাম উঠত, আর প্রতি মাসে কেউ
             * জিজ্ঞেস করত ওটা কী।
             */
            $table->string('contributor_name', 191);
            $table->string('contributor_type', 16);   // owner · partner · investor

            $table->string('entry_type', 16);         // contribution · investment

            $table->date('trx_date');
            $table->decimal('amount', 18, 4);

            /* অংশ কত শতাংশ — অংশীদারি ব্যবসায় লাগে, একার ব্যবসায় নাল */
            $table->decimal('share_percent', 9, 4)->nullable();

            $table->string('narration', 500)->nullable();

            $table->string('status', 16)->default('draft');

            /*
             * পোস্ট করা হলে কোন ভাউচারে — খাতার সাথে জোড়াটা এখানেই।
             *
             * ছাড়া রাখলে "এই মূলধনটা খাতায় কোথায়" প্রশ্নের উত্তর
             * খুঁজতে তারিখ আর অঙ্ক মিলিয়ে দেখতে হত।
             */
            $table->foreignId('voucher_id')->nullable()->constrained('vouchers')->nullOnDelete();
            $table->foreignId('received_into_account_id')->nullable()
                ->constrained('accounts')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->uuid('public_id')->unique();

            $table->unique(['company_id', 'document_no']);
            $table->index(['company_id', 'contributor_name']);
            $table->index(['company_id', 'status', 'trx_date']);
        });

        /*
         * কে মাসে কত তুলতে পারবেন।
         *
         * ── কেন সীমাটা আলাদা টেবিলে ─────────────────────────────────
         * সীমা বদলায় — বছরে একবার, বা অংশীদার বসে ঠিক করলে। উত্তোলনের
         * সারিতে বসালে পুরনো উত্তোলনগুলোর সীমাও বদলে যেত, আর "তখন
         * সীমা কত ছিল" প্রশ্নের উত্তর হারাত।
         */
        Schema::create('acc_withdrawal_limits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('contributor_name', 191);
            $table->decimal('monthly_cap', 18, 4);
            $table->timestamps();
            $table->uuid('public_id')->unique();

            $table->unique(['company_id', 'contributor_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acc_withdrawal_limits');
        Schema::dropIfExists('acc_capital_entries');
    }
};
