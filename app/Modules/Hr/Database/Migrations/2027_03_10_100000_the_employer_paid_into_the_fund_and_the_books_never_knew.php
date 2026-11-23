<?php

declare(strict_types=1);

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Modules\Accounts\Services\StandardChart;
use Illuminate\Database\Migrations\Migration;

/**
 * ⭐ ভবিষ্য তহবিলে প্রতিষ্ঠানের চাঁদার খাত (৫২২৫) প্রতিটা কোম্পানিতে — মালিকের অনুমোদিত আইনি বেতনের পরিকল্পনা, a6, ২১ অক্টোবর
 * ২০২৬ ([[StandardChart::PF_EMPLOYER_EXPENSE]], [[ProvidentFund]])।
 *
 * ⓘ `install()` কেবল না থাকা খাত বসায়; আগের খাতে হাত দেয় না। নতুন কোম্পানি খোলার সময় এমনিতেই পায়। ⓘ সুইচ (`hr.pf.enabled`)
 * ডিফল্টে বন্ধ — খাত থাকলেও কিছু বসে না, যতক্ষণ না কোম্পানি নিজে চালু করে।
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
