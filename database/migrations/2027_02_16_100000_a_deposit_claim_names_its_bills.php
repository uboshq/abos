<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ জমার বিজ্ঞপ্তিতে "কোন বিলের বিপরীতে" — টাকার পরিকল্পনা ২, ৭ অক্টোবর ২০২৬ ([[DepositClaimService::raise()]])।
 *
 * ঐচ্ছিক: ডিলার বা SR এক বা একাধিক খোলা বিল বাছেন, প্রতিটায় কত। গ্রহণের সময় আদায় সেই বিলগুলোতে মেলে
 * ([[DepositClaimService::accept()]]); না বাছলে আগের মতো গ্রাহকের খাতায় মোট টাকা। ⓘ সারি `[{sales_invoice_id, amount}]`
 * — দাবির ভেতরের একটা প্রস্তাব, খাতার কিছু নয়; আসল ভাগ আদায়ের সারিতে বসে, গ্রহণের মুহূর্তের বকেয়া মেপে।
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('sal_deposit_claims', 'bills')) {
            return;
        }

        Schema::table('sal_deposit_claims', function (Blueprint $table): void {
            $table->json('bills')->nullable()->after('note');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('sal_deposit_claims', 'bills')) {
            Schema::table('sal_deposit_claims', fn (Blueprint $table) => $table->dropColumn('bills'));
        }
    }
};
