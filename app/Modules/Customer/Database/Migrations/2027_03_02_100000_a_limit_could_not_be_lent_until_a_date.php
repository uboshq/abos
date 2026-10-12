<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ অস্থায়ী সীমা — একটা তারিখ পর্যন্ত বাড়তি সীমা, তারপর নিজে থেকেই আগের সীমায় (মালিকের উত্তর ৩, ১১ অক্টোবর ২০২৬; বাকি
 * নিয়ন্ত্রণ ধাপ ২.৩)।
 *
 * ⓘ ফেরানোর কোনো কাজ (job) নেই: চলতি সীমা পড়ার সময়েই হিসাব — আজ শেষ দিনের মধ্যে হলে অস্থায়ী, নইলে সাধারণ
 * ([[Customer::effectiveCreditLimit()]])। ⓘ বসে কেবল মালিকের সইয়ে ([[CustomerCreditRequest]]) — `fillable`-এ নয়।
 * ⓘ দুইটাই খালি-যোগ্য নতুন ঘর; পুরনো কোনো সারির মান বদলায় না।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            if (! Schema::hasColumn('customers', 'temp_credit_limit')) {
                $table->decimal('temp_credit_limit', 18, 4)->nullable()->after('credit_limit');
            }

            if (! Schema::hasColumn('customers', 'temp_credit_limit_until')) {
                $table->date('temp_credit_limit_until')->nullable()->after('temp_credit_limit');
            }
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            foreach (['temp_credit_limit_until', 'temp_credit_limit'] as $column) {
                if (Schema::hasColumn('customers', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
