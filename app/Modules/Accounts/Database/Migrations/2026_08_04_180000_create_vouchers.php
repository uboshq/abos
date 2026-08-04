<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ভাউচার — আদায়, পরিশোধ, খরচ, জাবেদা, কন্ট্রা।
 *
 * পাঁচটা ধরন, একটাই টেবিল। আলাদা পাঁচটা টেবিল বানালে ডে বুক লিখতে
 * পাঁচটা ইউনিয়ন লাগত, আর ষষ্ঠ ধরন যোগ হলে সেই ইউনিয়ন আপডেট করতে
 * কেউ ভুলত — তখন রিপোর্ট চুপচাপ কম দেখাত।
 *
 * পার্থক্যটা ফর্মে, সংরক্ষণে নয়: আদায়ের পর্দায় টাকা কোথায় ঢুকল আর কার
 * কাছ থেকে এল — দুইটা ঘর। জাবেদায় যত খুশি লাইন। কিন্তু দুইটাই শেষমেশ
 * ডেবিট-ক্রেডিটের সারি, আর PostingEngine ছাড়া কেউ লেজারে লেখে না।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vouchers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('financial_year_id')->constrained('financial_years')->cascadeOnDelete();

            $table->string('type', 16);
            $table->string('document_no', 64);

            // নিয়ম ৩ — ব্যবহারকারীর দেওয়া তারিখ, আর সিস্টেমের তারিখ (timestamps)
            // আলাদা। রিপোর্ট trx_date-এ চলে, অডিট created_at-এ।
            $table->date('trx_date');

            /*
             * পক্ষ — কার কাছ থেকে বা কাকে।
             *
             * ভাউচারের মাথায়ও আছে, আবার প্রতিটা লাইনেও থাকতে পারে।
             * মাথারটা রিপোর্ট ও খোঁজার জন্য ("করিম স্টোরের সব আদায়"),
             * লাইনেরটা লেজারে বসার জন্য। একটা জাবেদায় দুই পক্ষ থাকতে
             * পারে, তাই লাইনেরটা বাদ দেওয়া যায় না।
             */
            $table->string('party_type', 32)->nullable();
            $table->unsignedBigInteger('party_id')->nullable();

            $table->decimal('amount', 18, 4)->default(0);

            $table->string('narration', 500)->nullable();

            /*
             * কোন মাধ্যমে — নগদ, চেক, বিকাশ। হিসাবের দিক থেকে খাতটাই
             * যথেষ্ট, কিন্তু চেকের নম্বর ও তারিখ ছাড়া ব্যাংকে মেলানো যায় না।
             */
            $table->string('instrument', 16)->nullable();
            $table->string('instrument_no', 64)->nullable();
            $table->date('instrument_date')->nullable();

            $table->string('status', 16)->default('draft');

            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            // বাতিল করা হয় বিপরীত এন্ট্রি দিয়ে, মুছে নয় (নিয়ম ৫)
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'document_no']);
            $table->index(['company_id', 'type', 'trx_date']);
            $table->index(['company_id', 'party_type', 'party_id']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('voucher_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('voucher_id')->constrained('vouchers')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();

            $table->string('party_type', 32)->nullable();
            $table->unsignedBigInteger('party_id')->nullable();

            $table->decimal('debit', 18, 4)->default(0);
            $table->decimal('credit', 18, 4)->default(0);

            $table->string('narration', 500)->nullable();

            // ছাপা ও সম্পাদনায় লাইনের ক্রম ধরে রাখা — নাহলে সেভ করার পর
            // সারিগুলো এলোমেলো হয়ে ফিরত আর ব্যবহারকারী ভাবত কিছু বদলে গেছে
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['voucher_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voucher_lines');
        Schema::dropIfExists('vouchers');
    }
};
