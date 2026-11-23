<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ এক ব্যাংক-সারি, কয়েকটা জমার খবর — মালিক, ২১ নভেম্বর ২০২৬ (৳১,০০,০০০ = দুই ডিলারের ৳৫০,০০০ করে)।
 *
 * ⓘ খবরের `statement_line_id` আগের মতোই খবরের নিজের ঘর — একটা খবর একটাই সারিতে (একবারই মেলে)। ⛔ কেবল unique
 * `sal_claim_stmt_line_once` যায়, যাতে এক সারিতে কয়েকটা খবর বসতে পারে; মোট = সারির অঙ্ক, পাহারা সেবায়
 * ([[\App\Modules\Sales\Services\DepositMatchService::matchMany()]])।
 * ⓘ পুরনো এক-এক মিল যেমন ছিল তেমনই থাকে — কোনো তথ্য সরে না (প্রতিটা এক সদস্যের দল)।
 *
 * ⚠️ ক্রম: আগে সাধারণ সূচি, তারপর unique তোলা — MySQL বিদেশি চাবির জন্য unique সূচিটাই ব্যবহার করছে; আগে তুললে
 * "needed in a foreign key constraint"।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sal_deposit_claims', function (Blueprint $table) {
            $table->index('statement_line_id', 'sal_claim_stmt_line');
        });

        Schema::table('sal_deposit_claims', function (Blueprint $table) {
            $table->dropUnique('sal_claim_stmt_line_once');
        });
    }

    public function down(): void
    {
        Schema::table('sal_deposit_claims', function (Blueprint $table) {
            $table->unique('statement_line_id', 'sal_claim_stmt_line_once');
        });

        Schema::table('sal_deposit_claims', function (Blueprint $table) {
            $table->dropIndex('sal_claim_stmt_line');
        });
    }
};
