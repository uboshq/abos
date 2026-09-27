<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * একটা খসড়া সরিয়ে রাখা যেত না — মালিকের নির্দেশ, ২৮ সেপ্টেম্বর ২০২৬: খসড়া তালিকায়
 * "নিষ্ক্রিয় / সক্রিয় / মুছুন"।
 *
 * ⓘ নিষ্ক্রিয় খসড়া তালিকায় থাকে, কিন্তু বাকির সীমা ধরে রাখে না আর ক্রেতার নতুন বিলও
 * আটকায় না ([[DirectSaleService::pauseDraft()]]); সক্রিয় করতে সীমা আর "একটাই খসড়া"
 * আবার যাচাই হয়।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sal_invoices', function (Blueprint $table): void {
            $table->timestamp('draft_paused_at')->nullable()->after('counter_draft');
        });
    }

    public function down(): void
    {
        Schema::table('sal_invoices', function (Blueprint $table): void {
            $table->dropColumn('draft_paused_at');
        });
    }
};
