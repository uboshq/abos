<?php

declare(strict_types=1);

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Modules\Accounts\Services\StandardChart;
use Illuminate\Database\Migrations\Migration;

/**
 * টাকা নেওয়ার তিন নতুন খাত — চালু কোম্পানিগুলোতেও (মালিকের নকশা ১০০, ২১ অক্টোবর ২০২৬; fe-র D2, D3):
 *   · ১১৩৮ উৎসে কাটা ভ্যাট পাওনা — গ্রাহক কেটে রাখা VDS, মূসক-৬.৬ সনদ এলে সমন্বয় ([[StandardChart::VDS_RECEIVABLE]])
 *   · ৪১২০ আদায়ে বিক্রয় ছাড় — বিক্রয়ের বিপরীত-আয় (IFRS 15), খরচ নয় ([[StandardChart::COLLECTION_DISCOUNT]])
 *   · ৫৩০৫ রাউন্ডিং পার্থক্য — অন্য খরচ, কোম্পানির সীমার ভেতরে ([[StandardChart::ROUNDING_DIFFERENCE]])
 *
 * ⓘ ছক বসানো নিজে থেকে কেবল নেই এমন খাত বসায় ([[StandardChart::install()]]), তাই আবার চালালেও কিছু দ্বিগুণ হয় না।
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
