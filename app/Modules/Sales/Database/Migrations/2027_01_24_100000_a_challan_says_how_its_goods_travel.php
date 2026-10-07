<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * চালান বলে মাল কীভাবে গেল — "পরিবহন লাগবে না (ক্রেতার নিজের)" (ধাপ ৫, ২৮ সেপ্টেম্বর ২০২৬)।
 *
 * ⓘ গাড়ি আর বাহকের ঘর আগে থেকেই আছে; নেই কেবল "কিছুই লাগেনি" বলার জায়গা।
 * ঘরটা না থাকলে ফাঁকা গাড়ির ঘর দুই কথা বলত — "ক্রেতা নিজে নিলেন" আর
 * "কেউ লিখতে ভুলে গেছেন" — আর দুইটা আলাদা করার উপায় থাকত না।
 * ⓘ সূচক নেই: ঘরটা কেবল পড়া হয়, খোঁজা হয় না।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sal_challans', function (Blueprint $table): void {
            $table->boolean('own_transport')->default(false)->after('transport_cost');
        });
    }

    public function down(): void
    {
        Schema::table('sal_challans', function (Blueprint $table): void {
            $table->dropColumn('own_transport');
        });
    }
};
