<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * কাগজের কোন রূপ ছাপা হলো — মালিক, ২ অক্টোবর ২০২৬: চালান টাকাসহ না টাকা ছাড়া, ছাপার আগে বেছে নেওয়া যায়।
 *
 * ⓘ ছাপার খাতায় ([[PaperTrail]]) লেখা থাকে কোনটা গেল — পরে প্রশ্ন উঠলে ("গ্রাহক দাম দেখল কী করে?") উত্তর এখানেই।
 * ⓘ খালি = আগের মতো, রূপের প্রশ্ন নেই (বিল, ভাউচার …)।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('doc_deliveries', function (Blueprint $table): void {
            $table->string('variant', 24)->nullable()->after('paper');
        });
    }

    public function down(): void
    {
        Schema::table('doc_deliveries', function (Blueprint $table): void {
            $table->dropColumn('variant');
        });
    }
};
