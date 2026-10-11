<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ অস্থায়ী সীমা — একটা তারিখ পর্যন্ত বাড়তি সীমা, তারপর নিজে থেকেই সাধারণ সীমায় ফেরা (বাকি নিয়ন্ত্রণ ধাপ ২.৩, মালিকের উত্তর ৩,
 * ১১ অক্টোবর ২০২৬)।
 *
 * ⓘ দুইটা ঘর, দুইটাই খালি হতে পারে: অঙ্ক আর শেষ তারিখ। ⭐ "ফিরিয়ে আনার" কোনো কাজ (job) নেই — চলতি সীমা পড়ার সময়েই
 * হিসাব: আজ ≤ শেষ তারিখ হলে অস্থায়ী সীমা, নইলে সাধারণ ([[Customer::effectiveCreditLimit()]])। তাই কোনো রাতের কাজ না চললেও
 * সীমা ঠিক দিনে ফেরে।
 *
 * ⓘ নতুন ঘর, খালি — কোনো চলতি সারির অঙ্ক বদলায় না; সবার চলতি সীমা আজকের মতোই সাধারণ সীমা।
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('customers')) {
            return;
        }

        Schema::table('customers', function (Blueprint $table): void {
            if (! Schema::hasColumn('customers', 'temporary_credit_limit')) {
                $table->decimal('temporary_credit_limit', 18, 4)->nullable();
            }

            if (! Schema::hasColumn('customers', 'temporary_credit_until')) {
                $table->date('temporary_credit_until')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('customers')) {
            return;
        }

        Schema::table('customers', function (Blueprint $table): void {
            foreach (['temporary_credit_limit', 'temporary_credit_until'] as $column) {
                if (Schema::hasColumn('customers', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
