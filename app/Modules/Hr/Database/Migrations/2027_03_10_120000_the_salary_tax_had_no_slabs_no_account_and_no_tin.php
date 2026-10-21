<?php

declare(strict_types=1);

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Modules\Accounts\Services\StandardChart;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ বেতন থেকে উৎসে কর — কর্মীর e-TIN আর "প্রদেয় উৎসে কর (বেতন)" খাত (আয়কর আইন ২০২৩ ধারা ৮৬; মালিকের অনুমোদিত আইনি বেতনের
 * পরিকল্পনা, a6, ২১ অক্টোবর ২০২৬; [[SalaryTax]])।
 *
 *   · `hr_employees.e_tin` — এনক্রিপ্ট করা (NID-এর মতো), তাই `text`; পর্দায় পরিচয়ের চাবির পেছনে ([[Employee::GUARDED]])।
 *   · ২১৩২ প্রতিটা কোম্পানিতে — `install()` কেবল না থাকা খাত বসায়।
 *
 * ⓘ করের ধাপ ডিফল্টে খালি — খালি থাকলে কিছুই কাটা হয় না।
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('hr_employees', 'e_tin')) {
            Schema::table('hr_employees', function (Blueprint $table): void {
                $table->text('e_tin')->nullable()->after('national_id');
            });
        }

        foreach (Company::query()->orderBy('id')->get() as $company) {
            CompanyContext::forCompany($company->id, fn () => app(StandardChart::class)->install());
        }
    }

    public function down(): void
    {
        // ⓘ খাত (২১৩২) মোছা হয় না — দাখিলা থাকলে মুছলে খাতা ভাঙত; কেবল ঘরটা ফেরে
        if (Schema::hasColumn('hr_employees', 'e_tin')) {
            Schema::table('hr_employees', function (Blueprint $table): void {
                $table->dropColumn('e_tin');
            });
        }
    }
};
