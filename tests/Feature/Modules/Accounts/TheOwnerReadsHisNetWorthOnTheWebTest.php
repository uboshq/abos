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
        // ⭐ টাকার পাতার রঙিন মাথা, আর কোম্পানির বোতামে নাম — সংকেত নয় (৭ নভেম্বর ২০২৬)
        $this->assertStringContainsString('data-page-kind="networth"', $page->getContent(), '⛔ নিট সম্পদ বিবরণী রঙিন মাথায় নেই।');
        // ⓘ বোতামের সারির ভেতরেই খোঁজা — নামটা পাতার মাথাতেও থাকে (Mac, ৮ নভেম্বর ২০২৬: NW3 বেঁচে গিয়েছিল)
        $this->assertMatchesRegularExpression('/data-net-worth-companies.*?title="'.preg_quote($this->company->code, '/').'"[^>]*>\s*(?:'.preg_quote(e($this->company->name('bn')), '/').'|'.preg_quote(e($this->company->name('en')), '/').')\s*</su',
            (string) $page->getContent(), '⛔ কোম্পানির বোতামে কোম্পানির নাম নেই — সংকেত লেখা।');

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

    /**
     * ⛔ লাইভে, ৮ নভেম্বর ২০২৬: হেডারে এক শাখা বাছা থাকায় অন্য শাখার নগদ টিল (UB-র ৮.২৪ কোটি) নিট সম্পদ থেকে বাদ পড়ত, আর
     * দুই দিক মিলত না। নিট সম্পদ পুরো কোম্পানির — বাছা শাখা যাই হোক।
     */
    public function test_a_till_in_another_branch_still_counts_and_both_sides_agree(): void
    {
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($owner);
        $home = $this->company->defaultBranch();
        $other = \App\Models\Branch::query()->create(['company_id' => $this->company->id, 'code' => 'NWB', 'name_en' => 'Far Depot', 'is_active' => true]);

        $till = app(\App\Modules\Accounts\Services\CashTillService::class)->create(['code' => 'NW-FAR', 'name_en' => 'Far till', 'branch_id' => $other->id]);
        $capital = \App\Modules\Accounts\Models\Account::query()->where('code', StandardChart::OWNER_CAPITAL)->firstOrFail();
        app(\App\Core\Engines\Posting\PostingEngine::class)->post(
            sourceType: 'test:money-in', sourceId: 820000, trxDate: '2026-10-15',
            lines: [
                ['account_id' => $till->account_id, 'debit' => '820000', 'narration' => 'অন্য শাখার টিলে টাকা'],
                ['account_id' => $capital->id, 'credit' => '820000', 'narration' => 'অন্য শাখার টিলে টাকা'],
            ],
            branchId: $other->id,
        );

        // ⓘ দেখার শাখা ঘর-শাখা — অন্য শাখার টিল এই চোখে লুকানো থাকে, তবু নিট সম্পদে আসতে হবে
        CompanyContext::set($this->company->id, $home?->id);
        $owner->forceFill(['view_all_branches' => false])->save();

        $mine = app(NetWorthStatement::class)->forCompany((int) $this->company->id);
        $this->assertGreaterThanOrEqual(0, bccomp($mine['figures']['cash'], '820000', 2),
            '⛔ অন্য শাখার টিলের টাকা নিট সম্পদের "নগদ ও ব্যাংক"-এ নেই: '.$mine['figures']['cash']);
        $this->assertTrue($mine['agrees'], '⛔ দুই দিক মেলেনি — সম্পদ − দায় '.$mine['figures']['net'].', মূলধন + লাভ '.$mine['figures']['equity']);
    }
}
