<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ চালানের সারি দামের ভেতরের ভ্যাট মনে রাখে — পুনঃনিরীক্ষা, ৯ অক্টোবর ২০২৬ (মালিক, ১০ অক্টোবর ২০২৬: "বিল যেভাবে দেখায়")।
 *
 * ⓘ ভ্যাট দামের ভিতরে হলে চালানের দর আর মূল্য ভ্যাট বাদে বসে (মজুদে সেটাই ঢোকে); ভ্যাটটা এখানে আলাদা, যাতে কাগজ বিলের মতো
 * দেখায় — দর ভ্যাট বাদে, ভ্যাট আলাদা, মোট ভ্যাটসহ। ⚠️ পণ্যের হার থেকে পরে গোনা নয়: হার বদলালে পুরনো চালানের ভ্যাটও বদলাত।
 * আজকের সারিতে শূন্য — আগে সেগুলোর দরেই ভ্যাট ঢুকে ছিল।
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('pur_receipt_lines', 'tax')) {
            Schema::table('pur_receipt_lines', function (Blueprint $table): void {
                $table->decimal('tax', 18, 4)->default(0)->after('amount');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('pur_receipt_lines', 'tax')) {
            Schema::table('pur_receipt_lines', function (Blueprint $table): void {
                $table->dropColumn('tax');
            });
        }
    }
};
