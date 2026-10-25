<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\NetWorthStatement;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ⭐ নিট সম্পদ বিবরণীর ওয়েব পাতা (মালিক, ২৫ অক্টোবর ২০২৬; [[NetWorthController]])।
 *
 * দাবি:
 *  - মালিক পাতা পান, আর বড় অঙ্কটা সেবার নিট অঙ্কই — পাতা আলাদা কিছু গোনে না;
 *  - কোম্পানির চিপে এক কোম্পানি বাছলে সেই কোম্পানির নিট অঙ্ক;
 *  - মালিক নন এমন কেউ ৪০৪ পান — পাতাটা যে আছে সেটাও জানেন না।
 */
final class TheOwnerReadsHisNetWorthOnTheWebTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-20 11:00:00'));
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        app(StandardChart::class)->install();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_owner_reads_the_same_net_figure_the_statement_counts(): void
    {
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $statement = app(NetWorthStatement::class);
        $this->assertTrue($statement->mayView($owner, (int) $this->company->id), 'প্রস্তুতি: ডেমোর মালিক এই কোম্পানির মালিক নন।');

        $all = $statement->forOwner($owner)['all'];
        $mine = $statement->forCompany((int) $this->company->id)['figures'];

        $page = $this->actingAs($owner)->get(route('accounts.net_worth'));
        $page->assertOk();
        $this->assertStringContainsString(\App\Core\Support\Money::format($all['net']), $page->getContent(),
            '⛔ নিট সম্পদ বিবরণীর পাতার বড় অঙ্ক সেবার "সব কোম্পানি"-র নিট অঙ্ক নয়।');

        $one = $this->actingAs($owner)->get(route('accounts.net_worth', ['company' => $this->company->code]));
        $one->assertOk();
        $this->assertStringContainsString(\App\Core\Support\Money::format($mine['net']), $one->getContent(),
            '⛔ এক কোম্পানি বাছলে পাতা সেই কোম্পানির নিট অঙ্ক দেখায় না।');
    }

    public function test_anyone_else_gets_a_404(): void
    {
        $clerk = User::query()->where('email', 'accounts@abos.test')->firstOrFail();
        $this->assertFalse(app(NetWorthStatement::class)->mayView($clerk, (int) $this->company->id), 'প্রস্তুতি: হিসাবরক্ষক মালিক হয়ে গেছেন।');

        $this->assertSame(404, $this->actingAs($clerk)->get(route('accounts.net_worth'))->status(),
            '⛔ মালিক নন এমন কেউ নিট সম্পদ বিবরণীর পাতা পেলেন (বা পাতাটা আছে বলে জানলেন)।');
    }
}
