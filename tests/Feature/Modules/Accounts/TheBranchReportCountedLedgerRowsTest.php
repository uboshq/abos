<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Reports\BranchesSideBySideReport;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "শাখা পাশাপাশি" লিখত "৯০টি সারি", দেখাত একটা — পাতা-ঝাড়ু, ধাপ ০ (১০ অক্টোবর ২০২৬)।
 *
 * ⓘ কারণ: কোয়েরি নিজে শাখা ধরে দল বাঁধে, অথচ রিপোর্ট `groupBy` ঘোষণা করেনি — ইঞ্জিনের সরল `count()` প্রথম দলের খাতার সারি গুনত।
 * ⭐ সারির সংখ্যা = দেখানো সারি = খাতায় যত শাখা ([[ReportEngine::countFor()]])।
 */
final class TheBranchReportCountedLedgerRowsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_row_count_is_the_number_of_branches_shown(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, null);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $branches = LedgerEntry::query()->where('company_id', $company->id)->distinct()->count('branch_id')
            + (LedgerEntry::query()->where('company_id', $company->id)->whereNull('branch_id')->exists() ? 1 : 0);
        $lines = LedgerEntry::query()->where('company_id', $company->id)->count();
        $this->assertGreaterThan($branches, $lines, 'দৃশ্যটাই বানানো যায়নি — খাতার সারি শাখার চেয়ে বেশি হওয়ার কথা।');

        $result = app(ReportEngine::class)->run(BranchesSideBySideReport::KEY, ['from' => '2000-01-01', 'to' => now()->toDateString()]);
        $this->assertCount($result->totalRows, $result->rows, '⛔ "এতটি সারি" বলে, দেখায় অন্য সংখ্যা।');
        $this->assertSame($branches, $result->totalRows, '⛔ সারির সংখ্যা শাখার সংখ্যা নয় — খাতার সারি গোনা হলো।');
    }
}
