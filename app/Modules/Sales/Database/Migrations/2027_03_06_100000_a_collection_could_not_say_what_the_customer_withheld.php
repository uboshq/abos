<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * টাকা নেওয়া — আন্তর্জাতিক মানের আদায় (মালিকের নকশা ১০০, ২১ অক্টোবর ২০২৬; fe-র D1)।
 *
 * ⓘ গ্রাহক বিল থেকে কেটে রাখেন: ভ্যাট (VDS, মূসক-৬.৬ সনদের অপেক্ষায় ১১৩৮-এ) আর আয়কর (AIT, ১১৩৫-এ) — দুটোতেই তাঁর পাওনা পুরো
 * অঙ্কে কমে। আর কম পড়া অংশ ছাড় (৪১২০, মালিকের সই) বা রাউন্ডিং (৫৩০৫, সীমার ভেতরে) হয়ে মেলে। আগে এগুলোর কোনো ঘর ছিল না:
 * কাটা ভ্যাট "বকেয়া" হয়ে গ্রাহকের নামে থাকত।
 * ⓘ `unapplied` — কোনো বিলে না বসা অংশ, গ্রাহকের খাতায় Cr (অগ্রিমের আলাদা খাত নয়, মালিকের নিয়ম)। পরে বিলে প্রয়োগ হয়
 * [[sal_collection_applications]] দিয়ে। আগের আদায়গুলোয় ফাঁকা — তখন অঙ্ক − সারি ধরে গোনা হয়।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sal_collections', function (Blueprint $table) {
            if (! Schema::hasColumn('sal_collections', 'vds')) {
                $table->decimal('vds', 18, 4)->default(0)->after('bank_charge');
            }
            if (! Schema::hasColumn('sal_collections', 'ait')) {
                $table->decimal('ait', 18, 4)->default(0)->after('vds');
            }
            if (! Schema::hasColumn('sal_collections', 'difference_kind')) {
                $table->string('difference_kind', 16)->nullable()->after('ait');
            }
            if (! Schema::hasColumn('sal_collections', 'difference_amount')) {
                $table->decimal('difference_amount', 18, 4)->default(0)->after('difference_kind');
            }
            if (! Schema::hasColumn('sal_collections', 'difference_account_id')) {
                $table->foreignId('difference_account_id')->nullable()->after('difference_amount')
                    ->constrained('accounts')->restrictOnDelete();
            }
            if (! Schema::hasColumn('sal_collections', 'unapplied')) {
                $table->decimal('unapplied', 18, 4)->nullable()->after('difference_account_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sal_collections', function (Blueprint $table) {
            if (Schema::hasColumn('sal_collections', 'difference_account_id')) {
                $table->dropConstrainedForeignId('difference_account_id');
            }
            foreach (['vds', 'ait', 'difference_kind', 'difference_amount', 'unapplied'] as $column) {
                if (Schema::hasColumn('sal_collections', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
