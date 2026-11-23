<?php

declare(strict_types=1);

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Modules\Accounts\Services\StandardChart;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ উৎসব ভাতা — বেতনের রানের আলাদা ধরন, নিজের সই আর নিজের খরচের খাত (শ্রম বিধিমালা ২০১৫ বিধি ১১১; মালিকের অনুমোদিত আইনি বেতনের
 * পরিকল্পনা, a6, ২১ অক্টোবর ২০২৬; [[PayrollService::buildBonus()]])।
 *
 *   · `hr_payroll_runs.run_type` — `salary` (আগের সব রান, ডিফল্ট) বা `bonus`। "এক মাসে একটা জীবিত রান" নিয়ম এখন ধরন ধরে।
 *   · ৫২২৬ "উৎসব ভাতা" খরচের খাত প্রতিটা কোম্পানিতে — `install()` কেবল না থাকা খাত বসায়।
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('hr_payroll_runs', 'run_type')) {
            Schema::table('hr_payroll_runs', function (Blueprint $table): void {
                $table->string('run_type', 16)->default('salary')->after('document_no');
                $table->index(['company_id', 'run_type', 'month'], 'hr_run_type_month');
            });
        }

        foreach (Company::query()->orderBy('id')->get() as $company) {
            CompanyContext::forCompany($company->id, fn () => app(StandardChart::class)->install());
        }
    }

    public function down(): void
    {
        // ⓘ খাত (৫২২৬) মোছা হয় না — দাখিলা থাকলে মুছলে খাতা ভাঙত; কেবল ঘরটা ফেরে
        if (Schema::hasColumn('hr_payroll_runs', 'run_type')) {
            Schema::table('hr_payroll_runs', function (Blueprint $table): void {
                $table->dropIndex('hr_run_type_month');
                $table->dropColumn('run_type');
            });
        }
    }
};
