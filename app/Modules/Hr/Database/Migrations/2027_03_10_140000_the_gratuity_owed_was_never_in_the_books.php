<?php

declare(strict_types=1);

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Modules\Accounts\Services\StandardChart;
use Illuminate\Database\Migrations\Migration;

/**
 * ⭐ গ্র্যাচুইটির খাত (৫২২৭ খরচ, ২১৩৪ দায়) আর বাকি আইনি বেতনের খাত প্রতিটা কোম্পানিতে (a6, ২১ অক্টোবর ২০২৬;
 * [[StandardChart::HR_LAW_CODES]])। ⓘ একই কাজ হাতে: `php artisan abos:hr-law-accounts [--dry]`। কেবল না থাকা খাত বসে।
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (Company::query()->orderBy('id')->get() as $company) {
            CompanyContext::forCompany($company->id, fn () => app(StandardChart::class)->install(StandardChart::HR_LAW_CODES));
        }
    }

    public function down(): void
    {
        // ⓘ খাত মোছা হয় না — দাখিলা থাকলে মুছলে খাতা ভাঙত; না থাকলে খাতটা নিরীহ
    }
};
