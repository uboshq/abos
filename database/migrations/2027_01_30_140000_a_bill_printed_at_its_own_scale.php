<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ছাপার ইতিহাসে মাপ % — মালিকের বাছাই (খ), ১ অক্টোবর ২০২৬ ([[PrintScale]])।
 *
 * ⓘ কাগজে মাপ লেখা হয় না (মালিক: কাগজ পরিষ্কার), তাই "কোন মাপে ছাপা হয়েছিল" প্রশ্নের উত্তর কেবল এখানে।
 * দুটোই খালি হতে পারে — আগের সারি, শেয়ারের লিংক, থার্মাল।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('doc_deliveries', function (Blueprint $table): void {
            $table->unsignedSmallInteger('scale')->nullable()->after('paper');
            $table->boolean('scale_auto')->nullable()->after('scale');
        });
    }

    public function down(): void
    {
        Schema::table('doc_deliveries', function (Blueprint $table): void {
            $table->dropColumn(['scale', 'scale_auto']);
        });
    }
};
