<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * ⭐ চালানের উপসর্গ CHA — মালিক, ২ অক্টোবর ২০২৬: *"INV-0154 ↔ CHA-0154"*।
 *
 * ⓘ নতুন কোম্পানি CHA পায় ([[NumberSeriesProvisioner]]); পুরনো কোম্পানির DC সিরিজ এখনো "DC" উপসর্গে।
 * কেবল যেখানে উপসর্গটা আগের ডিফল্ট "DC" — কেউ নিজে বদলে থাকলে সেটা ছোঁয়া হয় না। ⛔ কোনো কাগজের নম্বর
 * বদলায় না: ২৯ সেপ্টেম্বর থেকে DC সিরিজ থেকে কোনো নম্বর আসে না, কেবল উপসর্গটা নেওয়া হয় ([[SaleNumber]])।
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('number_series')) {
            return;
        }

        DB::table('number_series')->where('doc_type', 'DC')->where('prefix', 'DC')->update(['prefix' => 'CHA']);
    }

    public function down(): void
    {
        DB::table('number_series')->where('doc_type', 'DC')->where('prefix', 'CHA')->update(['prefix' => 'DC']);
    }
};
