<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * ⭐ DO বিক্রয় আদেশে মেশানো — ধাপ ১ (M1): আদেশ আর তার লাইনের নতুন ঘর (মালিক, ৪ অক্টোবর ২০২৬: *"বাকির সীমা ছাড়া
 * আর কোনো পুরনো নিয়ম থাকবে না — পুরো ধারা আন্তর্জাতিক মানে"*; নকশা "DO বিক্রয় আদেশে মেশানো" §১.৩, §১.৪, §২.১)।
 *
 * ⓘ সব ঘর খালি-যোগ্য বা ডিফল্টসহ, তাই আজকের প্রতিটা সারি যেমন ছিল তেমনই থাকে: পুরনো আদেশ `hold_mode = ledger`
 * (আজকের সংরক্ষণের নিয়ম), অগ্রগতি `none`, লাইন `open`। ⛔ কোনো ডেটা-মাইগ্রেশন নয় (নকশার M3)।
 *
 * ⓘ চালান আর বিলের অগ্রগতি (`delivery_status`, `billing_status`) লেখে কেবল [[OrderProgress::refresh()]] — চালান আর বিলের
 * পরিমাণ থেকে গুনে; হাতে কেউ লেখে না।
 *
 * ⚠️ সূচক আর বিদেশি চাবির নাম হাতে লেখা, ৬৪ অক্ষরের নিচে (নকশার §২.২)।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sal_orders', function (Blueprint $table): void {
            // ⓘ জমা আর শেষ সইয়ের মুহূর্ত — শেষ সই থেকে মাল আটকানোর ঘড়ি চলে
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();

            // ⓘ বাকির যাচাই — DO-র `accounts_*` ঘরের হুবহু (abos-86-এর সেবা লেখে)
            $table->decimal('credit_short', 18, 4)->nullable();
            $table->timestamp('credit_held_at')->nullable();
            $table->timestamp('credit_checked_at')->nullable();
            $table->json('credit_warnings')->nullable();

            // ⓘ ডিপো যাচাই অবস্থা নয়, চিহ্ন — আংশিক চালানের পরে একই আদেশ আবার ডিপোতে যায়
            $table->timestamp('depot_check_at')->nullable();
            $table->foreignId('depot_check_by')->nullable()->constrained('users', 'id', 'sal_ord_depot_by_fk')->nullOnDelete();

            // ⓘ অগ্রগতি — none | partial | full
            $table->string('delivery_status', 8)->default('none');
            $table->string('billing_status', 8)->default('none');

            // ⓘ বন্ধ — কে, কখন, আর কম রেখে বন্ধ হলে কেন
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users', 'id', 'sal_ord_closer_fk')->nullOnDelete();
            $table->string('close_reason', 500)->nullable();

            // ⭐ কোন সংরক্ষণের নিয়ম — `ledger` আজকের (নিশ্চিতে সরাসরি reserved), `holds` নতুন ধারার (ঘড়িসহ হোল্ড)
            $table->string('hold_mode', 8)->default('ledger');

            $table->index(['company_id', 'status', 'trx_date'], 'sal_ord_status_idx');
            $table->index(['company_id', 'customer_id', 'credit_held_at'], 'sal_ord_credit_idx');
        });

        Schema::table('sal_order_lines', function (Blueprint $table): void {
            // ⓘ যা চাওয়া হয়েছিল — সুপারভাইজার কমালে `ordered_qty` কমে, এটা থাকে
            $table->decimal('requested_qty', 18, 4)->nullable();
            $table->decimal('free_qty', 18, 4)->default(0);

            // ⓘ বাকিটা "আর দেওয়া হবে না" (SAP-এর reason for rejection)
            $table->decimal('rejected_qty', 18, 4)->default(0);
            $table->string('reject_reason', 255)->nullable();

            $table->string('delivery_status', 8)->default('none');
            $table->string('billing_status', 8)->default('none');
            $table->string('line_status', 8)->default('open');
        });
    }

    public function down(): void
    {
        Schema::table('sal_order_lines', function (Blueprint $table): void {
            $table->dropColumn([
                'requested_qty', 'free_qty', 'rejected_qty', 'reject_reason',
                'delivery_status', 'billing_status', 'line_status',
            ]);
        });

        Schema::table('sal_orders', function (Blueprint $table): void {
            $table->dropIndex('sal_ord_status_idx');
            $table->dropIndex('sal_ord_credit_idx');
            $table->dropForeign('sal_ord_depot_by_fk');
            $table->dropForeign('sal_ord_closer_fk');
            $table->dropColumn([
                'submitted_at', 'approved_at',
                'credit_short', 'credit_held_at', 'credit_checked_at', 'credit_warnings',
                'depot_check_at', 'depot_check_by',
                'delivery_status', 'billing_status',
                'closed_at', 'closed_by', 'close_reason',
                'hold_mode',
            ]);
        });
    }
};
