<?php

declare(strict_types=1);

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Modules\Accounts\Services\StandardChart;
use Illuminate\Database\Migrations\Migration;

/**
 * আয়করের দুই খাত — চালু কোম্পানিগুলোতেও (মালিক, ১৭ নভেম্বর ২০২৬ "ok": স্থিতিপত্রে আয়করের সঞ্চিতির লাইন, IAS 12):
 *   · ২১২৬ আয়করের সঞ্চিতি — চলতি দায় ([[StandardChart::INCOME_TAX_PROVISION]])
 *   · ৫৫০০ আয়কর খরচ — লাভ-ক্ষতিতে করপূর্ব মুনাফার নিচে ([[StandardChart::INCOME_TAX_EXPENSE]])
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
