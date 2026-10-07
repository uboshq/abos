<?php

declare(strict_types=1);

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Modules\Accounts\Services\StandardChart;
use Illuminate\Database\Migrations\Migration;

/**
 * পরিবহন ভাড়া আদায়ের খাত (৪৩৬০) — পুরনো কোম্পানিতেও (মালিক, ৪ অক্টোবর ২০২৬, সংস্করণ ২)।
 *
 * ⓘ খাতটা মান হিসাব-ছকে ([[StandardChart::FREIGHT_INCOME]]); নতুন কোম্পানি নিজে পায়। পুরনোগুলোর জন্য ছকের
 * একই পথ — [[StandardChart::install()]], যা কেবল অনুপস্থিত খাত বসায় (`abos:sync-chart`-এর হুবহু কাজ)।
 * ⛔ এখানে হাতে `DB::table('accounts')->insert()` লিখলে খাত বসানোর নিয়ম দুই জায়গায় থাকত — রক্ষক, সিস্টেম-চিহ্ন,
 * প্রকৃতি — আর একদিন আলাদা হয়ে যেত।
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (Company::query()->orderBy('id')->get() as $company) {
            CompanyContext::forCompany($company->id, fn () => app(StandardChart::class)->install());
        }
    }

    public function down(): void
    {
        // ⓘ খাত মোছা হয় না — দাখিলা থাকলে মুছলে খাতা ভাঙত; না থাকলে খাতটা নিরীহ
    }
};
