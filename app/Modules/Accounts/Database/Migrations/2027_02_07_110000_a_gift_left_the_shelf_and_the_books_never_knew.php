<?php

declare(strict_types=1);

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Modules\Accounts\Services\StandardChart;
use Illuminate\Database\Migrations\Migration;

/**
 * "প্রচারের খরচ" (৫২২২) — চালু কোম্পানিগুলোতেও (মালিকের পরিকল্পনা সংস্করণ ২, ৪ অক্টোবর ২০২৬; [[StandardChart::PROMOTION_EXPENSE]])।
 *
 * ⓘ ছক বসানো নিজে থেকে কেবল নেই এমন খাত বসায় ([[StandardChart::install()]]), তাই আবার চালালেও কিছু দ্বিগুণ হয় না।
 * ⛔ আগের উপহারগুলোর খাতা এখানে ঠিক করা হয় না — কতগুলো আর কত টাকা, তা গুনে মালিক সমন্বয় ভাউচারের সিদ্ধান্ত নেবেন।
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
