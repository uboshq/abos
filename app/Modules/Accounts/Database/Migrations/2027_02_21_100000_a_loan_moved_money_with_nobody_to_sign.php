<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ঋণের টাকা নড়ত কারও সই ছাড়া — অডিট ⛔ (সমন্বয়ক, ১০ অক্টোবর ২০২৬)।
 *
 * ⓘ তোলা, শোধ আর সুদের সারি এখন সইয়ের অপেক্ষায় থাকতে পারে ([[LoanService]]); তাই সারির নিজের অবস্থা।
 * ⭐ আগের সব সারি খাতায় বসা — ডিফল্ট "posted", তাই পুরনো কিছুই বদলায় না।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('acc_loan_movements', function (Blueprint $table) {
            $table->string('status', 16)->default('posted')->after('counter_account_id');
        });
    }

    public function down(): void
    {
        Schema::table('acc_loan_movements', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
