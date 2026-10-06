<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ হাতধারের ফেরতের তারিখ আর ব্যক্তির ধরন — অর্থ-মডিউলের পরিকল্পনা ১.৮ আর ১-এর শেষ লাইন, ৫ অক্টোবর ২০২৬।
 *
 * ⓘ `return_on` প্রতিটা দেওয়া বা নেওয়ার নিজের — "৫ হাজার দিলাম, ৩০ তারিখে ফেরত"; না দিলে হিসাবের তারিখ
 * (`next_due_on`/`due_on`) খাটে ([[HandLoanReports::SCHEDULE]])। ⓘ `kind` — কর্মী, আত্মীয়, ব্যবসায়ী, অন্যান্য
 * ([[Person::KINDS]]); IAS 24: মালিক বা আত্মীয়ের সাথে লেনদেন আলাদা চেনা যায়। দুইটাই ঐচ্ছিক, পুরনো সারি অক্ষত।
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('fin_hand_loan_movements', 'return_on')) {
            Schema::table('fin_hand_loan_movements', function (Blueprint $table): void {
                $table->date('return_on')->nullable()->after('moved_on');
            });
        }

        if (! Schema::hasColumn('mdm_people', 'kind')) {
            Schema::table('mdm_people', function (Blueprint $table): void {
                $table->string('kind', 16)->nullable()->after('mobile');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('fin_hand_loan_movements', 'return_on')) {
            Schema::table('fin_hand_loan_movements', fn (Blueprint $table) => $table->dropColumn('return_on'));
        }

        if (Schema::hasColumn('mdm_people', 'kind')) {
            Schema::table('mdm_people', fn (Blueprint $table) => $table->dropColumn('kind'));
        }
    }
};
