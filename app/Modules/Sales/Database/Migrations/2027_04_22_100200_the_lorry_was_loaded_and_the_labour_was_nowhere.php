<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ চালানের লোডিং খরচ — মালিক, ১৮ নভেম্বর ২০২৬ ("ওকে")।
 *
 * ⓘ ঐচ্ছিক; নিয়ন্ত্রণ প্যানেলের সুইচ `sales.loading.mandatory` চালু হলে ভাড়ার মতোই বাধ্যতামূলক — অঙ্ক বা "লাগেনি"।
 *   খরচটা বিক্রয়ের খরচ (লোডিং, ৫২১৮): নগদে দিলে Cr টিল, বাকি হলে Cr প্রদেয় হাম্মালি (২১১৭) ঠিকাদারের নামে।
 *
 * ঘর: `loading_mode` (cash · due · none; null = লেখা হয়নি) · `loading_amount` · `loading_account_id` (নগদের খাত) ·
 *     `loading_payer_id` (কে দিলেন) · `loading_contractor_id` (বাকি হলে ঠিকাদার) · `loading_reason` ("লাগেনি" হলে কেন)।
 * ⓘ সব খালি-যোগ্য বা শূন্য ডিফল্ট — কোনো পুরনো চালান বদলায় না।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sal_challans', function (Blueprint $t): void {
            $t->string('loading_mode', 16)->nullable();
            $t->decimal('loading_amount', 18, 4)->default(0);
            $t->foreignId('loading_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $t->foreignId('loading_contractor_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $t->foreignId('loading_payer_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('loading_reason', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sal_challans', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('loading_payer_id');
            $t->dropConstrainedForeignId('loading_contractor_id');
            $t->dropConstrainedForeignId('loading_account_id');
            $t->dropColumn(['loading_mode', 'loading_amount', 'loading_reason']);
        });
    }
};
