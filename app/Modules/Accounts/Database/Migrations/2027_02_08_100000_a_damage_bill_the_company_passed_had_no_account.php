<?php

declare(strict_types=1);

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Modules\Accounts\Services\StandardChart;
use Illuminate\Database\Migrations\Migration;

/**
 * ⭐ ড্যামেজ দাবির খাত (১১৫১) প্রতিটা কোম্পানিতে — মালিক, ৪ অক্টোবর ২০২৬ ([[StandardChart::DAMAGE_CLAIM]])।
 *
 * ⓘ `install()` কেবল না থাকা খাত বসায়; আগের খাতে হাত দেয় না। নতুন কোম্পানি খোলার সময় এমনিতেই পায়।
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
