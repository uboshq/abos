<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * উত্তোলন — মালিক বা অংশীদার ব্যবসা থেকে টাকা নিলেন।
 *
 * ── কেন নিজের টেবিল, কেবল একটা ভাউচার নয়, ৩০ আগস্ট ২০২৬ ─────────────
 * টাকাটা তোলা যেত আগেও: একটা পরিশোধ ভাউচার, ডেবিট ৩২০০ উত্তোলন। কিন্তু
 * ভাউচারে **কে তুলল** বলার কোনো ঘর নেই — নামটা কেবল বিবরণে থাকত, আর
 * [[CapitalService::withdrawnBy()]] ওই লেখা মিলিয়ে খুঁজত। নাম বানান
 * ভুল হলে বা কেউ বিবরণ বদলালে টাকাটা কারও নামেই বসত না।
 *
 * অংশীদারি ব্যবসায় ওই সংখ্যাটা নিয়েই ঝগড়া হয়, তাই ওটা লেখা মেলানোর
 * উপর দাঁড়িয়ে থাকতে পারে না।
 *
 * ── কেন অনুরোধ ও অনুমোদন আলাদা ধাপ ──────────────────────────────────
 * মালিকের পরিকল্পনার ভাষায়: **অনুরোধ → অনুমোদন → ভাউচার → খতিয়ান**।
 * অনুমোদন ইঞ্জিন একটা মডেল চায় ([[ApprovalEngine::request()]]), আর
 * ভাউচারকে অনুমোদনে পাঠালে ওটা **সব** ভাউচারে লেগে যেত।
 *
 * ── কেন সীমা এখানে নেই, আলাদা টেবিলে ─────────────────────────────────
 * সীমা বদলায়; উত্তোলনের সারিতে বসালে পুরনো উত্তোলনগুলোর সীমাও বদলে
 * যেত, আর "তখন সীমা কত ছিল" প্রশ্নের উত্তর হারাত।
 * (`acc_withdrawal_limits` আগেই বসানো।)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_withdrawals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();

            $table->string('document_no', 64);

            /*
             * কে তুলল — নাম ধরে, মূলধনের সারির মতোই।
             *
             * ── কেন ব্যবহারকারীর সাথে জোড়া নয় ───────────────────────
             * মালিক বা অংশীদার ব্যবস্থার ব্যবহারকারী নাও হতে পারেন —
             * অনেক ডিপোতে মালিক কম্পিউটার ছোঁন না, ম্যানেজার সব লেখেন।
             * ব্যবহারকারী বাধ্যতামূলক করলে ম্যানেজারের নামে মালিকের
             * উত্তোলন বসত।
             *
             * নামটা মূলধনের সারির নামের সাথে মেলে, আর সেটাই দুইটাকে
             * এক পাতায় আনে: কে কত দিয়েছেন, কে কত তুলেছেন।
             */
            $table->string('contributor_name', 191);

            $table->decimal('amount', 18, 4);
            $table->date('trx_date');

            /* কোন টিল বা ব্যাংক থেকে — খাতায় বসানোর সময় লাগে */
            $table->foreignId('money_account_id')->nullable()
                ->constrained('accounts')->nullOnDelete();

            /*
             * কেন তুলছেন।
             *
             * ঐচ্ছিক, কিন্তু ঘরটা থাকা দরকার: বছর শেষে "এই বিশ লাখ
             * কোথায় গেল" প্রশ্নের উত্তর কারও মাথায় না থেকে কাগজে
             * থাকা ভালো।
             */
            $table->text('reason')->nullable();

            /* draft · pending · confirmed · cancelled — DocumentStatus */
            $table->string('status', 16)->default('draft');

            /* যে দাখিলাটা হলো — প্রতিটা সংখ্যা ওখানেই খোলে (নিয়ম ১) */
            $table->foreignId('voucher_id')->nullable()->constrained('vouchers')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->uuid('public_id')->unique();

            $table->unique(['company_id', 'document_no']);
            $table->index(['company_id', 'contributor_name', 'trx_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_withdrawals');
    }
};
