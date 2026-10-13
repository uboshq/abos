<?php

declare(strict_types=1);

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Modules\Accounts\Services\StandardChart;
use Illuminate\Database\Migrations\Migration;

/**
 * ব্যাংক ওভারড্রাফটের দল (২১০৫) — পুরনো কোম্পানিতেও (মালিক, ১৩ অক্টোবর ২০২৬: "int standrad onuzayi koro"; IAS 32/IAS 7)।
 *
 * ⓘ খাতটা মান হিসাব-ছকে ([[StandardChart::BANK_OVERDRAFT]]); নতুন কোম্পানি নিজে পায়। পুরনোগুলোর জন্য ছকের একই পথ —
 * [[StandardChart::install()]], যা কেবল অনুপস্থিত খাত বসায়; কোনো দাখিলা নয়, কোনো সারি বদল নয়।
 * ⓘ ব্যাংক-প্রতি উপ-খাত এখানে তৈরি হয় না — প্রথম Cr খোলা জেরে নিজে ([[AccountService::overdraftFor()]])।
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
