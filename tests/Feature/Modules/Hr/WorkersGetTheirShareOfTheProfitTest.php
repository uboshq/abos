<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Hr;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Security\FieldSecurity;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\Money;
use App\Models\Branch;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Hr\Models\WppfProvision;
use App\Modules\Hr\Services\WppfService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ শ্রমিকের মুনাফায় অংশগ্রহণ তহবিল (WPPF) — বাংলাদেশ শ্রম আইন ২০০৬, অধ্যায় ১৫, ধারা ২৩৪(১)(খ) (২০১৩-র সংশোধনী) — a6, ২১ অক্টোবর ২০২৬
 * ([[WppfService]], [[WppfController]])।
 *
 * দাবি:
 *  - সুইচ বন্ধ (ডিফল্ট): মেনুতে সারি নেই, পর্দা আর "সই দিন" দুইটাই ৪০৪ — মালিকের কাছেও;
 *  - চালু: নিট মুনাফা ওয়েবের লাভ-ক্ষতির রিপোর্টের (রিপোর্ট ইঞ্জিন, একই বছর, সব শাখা) সাথে পয়সায় পয়সায় এক; ৫% পয়সায় গোল;
 *    ৮০:১০:১০ — তিন ভাগের যোগ ঠিক ৫%; পর্দায় সব অঙ্ক;
 *  - "সই দিন": বছরের শেষ দিনে খরচ ৫২২৩ ডেবিট, দেনা ২১৩২ ক্রেডিট, তহবিলের অঙ্কে; বছরে একবারই; জাবেদার পরে পর্দা তহবিলের
 *    আগের মুনাফায় একই তহবিল দেখায়;
 *  - ক্ষতির বছরে তহবিল শূন্য, জাবেদা হয় না; শাখায় সীমিত মানুষ আর চাবিহীন মানুষ পর্দা পান না।
 */
final class WorkersGetTheirShareOfTheProfitTest extends TestCase
{
    use RefreshDatabase;

    /** ⓘ ১,৫০,০০০.০০ − ২৬,৫৪২.৬৯ = ১,২৩,৪৫৭.৩১ → ৫% = ৬,১৭২.৮৬৫৫ → ৬,১৭২.৮৭; ৮০% ৪,৯৩৮.৩০, ১০% ৬১৭.২৯, বাকি ৬১৭.২৮ */
    private const SALES = '150000.00';

    private const RENT = '26542.69';

    private Company $company;

    private User $owner;

    private FinancialYear $year;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        app(StandardChart::class)->install();
        $this->year = FinancialYear::query()->where('starts_on', '2026-07-01')->firstOrFail();

        // ⓘ বছর শেষের পরে — বছরের হিসাব বছর শেষে হয়
        $this->travelTo('2027-07-10 10:00:00');

        $this->book(1, '2026-08-10', StandardChart::SALES, self::SALES, 'credit');
        $this->book(2, '2026-09-15', '5202', self::RENT, 'debit');
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    public function test_switched_off_there_is_no_menu_and_no_door(): void
    {
        $this->assertFalse(app(WppfService::class)->enabled(), '⛔ সুইচ ডিফল্টে চালু — আইন যেখানে খাটে না, সেখানেও তহবিল দেখাত।');

        $this->get(route('hr.wppf.show'))->assertNotFound();
        $this->post(route('hr.wppf.provision', ['year' => $this->year->id]))->assertNotFound();
        $this->get(route('hr.employee.index'))->assertOk()->assertDontSee(route('hr.wppf.show'), false);

        $this->assertSame(0, WppfProvision::query()->count());
        $this->assertSame(0, LedgerEntry::query()->where('source_type', WppfProvision::SOURCE_TYPE)->count());
    }

    public function test_switched_on_the_figures_are_the_profit_and_loss_to_the_paisa(): void
    {
        $this->switchOn();

        $pl = app(ReportEngine::class)->run('accounts.profit_loss', [
            'from' => '2026-07-01', 'to' => '2027-06-30', 'branch_id' => null,
        ]);
        $plNet = bcsub((string) $pl->totals['credit'], (string) $pl->totals['debit'], 4);
        $this->assertSame('123457.3100', $plNet, 'প্রস্তুতি — লাভ-ক্ষতির নিট মুনাফা জানা অঙ্কে নেই।');
        $this->assertSame(0, bccomp($plNet, (string) ($pl->report->summary)($pl->totals)['value'], 4), 'প্রস্তুতি — রিপোর্টের নিজের সারাংশ অন্য কথা বলে।');

        $figures = app(WppfService::class)->figuresFor($this->year);

        $this->assertSame($plNet, $figures['pl_net'], '⛔ নিট মুনাফা লাভ-ক্ষতির রিপোর্টের সাথে মেলে না।');
        $this->assertSame($plNet, $figures['net_profit'], '⛔ জাবেদার আগে ভিত্তি লাভ-ক্ষতির নিট নয়।');
        $this->assertSame('6172.8700', $figures['wppf'], '⛔ ৫% পয়সায় গোল নয়।');
        $this->assertSame('4938.3000', $figures['participation_fund'], '⛔ অংশগ্রহণ তহবিল ৮০% নয়।');
        $this->assertSame('617.2900', $figures['welfare_fund'], '⛔ কল্যাণ তহবিল ১০% নয়।');
        $this->assertSame('617.2800', $figures['foundation_fund'], '⛔ ফাউন্ডেশনের ভাগ বাকিটা নয়।');
        $this->assertSame($figures['wppf'], bcadd(bcadd($figures['participation_fund'], $figures['welfare_fund'], 4), $figures['foundation_fund'], 4),
            '⛔ তিন ভাগের যোগ তহবিল নয় — পয়সা হারাল।');

        $this->get(route('hr.wppf.show', ['year' => $this->year->id]))->assertOk()
            ->assertSee(Money::format($plNet), false)
            ->assertSee(Money::format('6172.87'), false)
            ->assertSee(Money::format('4938.30'), false)
            ->assertSee(Money::format('617.29'), false)
            ->assertSee(Money::format('617.28'), false)
            ->assertSee(route('hr.wppf.provision', ['year' => $this->year->id]), false);
        $this->get(route('hr.employee.index'))->assertOk()->assertSee(route('hr.wppf.show'), false);
    }

    public function test_the_signed_provision_books_the_fund_once_on_the_last_day_of_the_year(): void
    {
        $this->switchOn();

        $this->post(route('hr.wppf.provision', ['year' => $this->year->id]))
            ->assertRedirect(route('hr.wppf.show', ['year' => $this->year->id]))->assertSessionHasNoErrors();

        $rows = LedgerEntry::query()->where('source_type', WppfProvision::SOURCE_TYPE)->get();
        $this->assertCount(2, $rows, '⛔ তহবিলের জাবেদা দুই সারির নয়।');
        $debit = $rows->first(fn ($r) => bccomp((string) $r->debit, '0', 4) > 0);
        $credit = $rows->first(fn ($r) => bccomp((string) $r->credit, '0', 4) > 0);
        $this->assertSame((int) StandardChart::find(StandardChart::WPPF_EXPENSE)->id, (int) $debit->account_id, '⛔ খরচ ৫২২৩-এ নয়।');
        $this->assertSame((int) StandardChart::find(StandardChart::WPPF_PAYABLE)->id, (int) $credit->account_id, '⛔ দেনা ২১৩২-এ নয়।');
        $this->assertSame(0, bccomp('6172.87', (string) $debit->debit, 4));
        $this->assertSame(0, bccomp('6172.87', (string) $credit->credit, 4));
        $this->assertSame('2027-06-30', $debit->trx_date->toDateString(), '⛔ জাবেদা বছরের শেষ দিনে নয়।');

        $provision = WppfProvision::query()->sole();
        $this->assertTrue($provision->isPosted());
        $this->assertSame((int) $this->owner->id, (int) $provision->posted_by);

        // ⓘ জাবেদার পরে — লাভ-ক্ষতি কমেছে, ভিত্তি তহবিলের আগের মুনাফা, তহবিল একই
        $after = app(WppfService::class)->figuresFor($this->year);
        $this->assertSame('117284.4400', $after['pl_net']);
        $this->assertSame('123457.3100', $after['net_profit'], '⛔ জাবেদার পরে তহবিল নিজের খরচের উপর কমল।');
        $this->assertSame('6172.8700', $after['wppf']);

        // ⛔ বছরে একবারই
        $this->post(route('hr.wppf.provision', ['year' => $this->year->id]))->assertSessionHasErrors('provision');
        $this->assertSame(2, LedgerEntry::query()->where('source_type', WppfProvision::SOURCE_TYPE)->count(), '⛔ একই বছরের তহবিল দুইবার খরচে।');
    }

    /**
     * ⭐ লাভ-ক্ষতিতে তহবিল নিজের লাইনে, "কর-পূর্ব মুনাফা"-র ঠিক উপরে (IAS 19; fe, ২১ অক্টোবর ২০২৬); মোট পয়সায় পয়সায় মেলে।
     */
    public function test_the_profit_and_loss_shows_the_fund_on_its_own_line_above_profit_before_tax(): void
    {
        $page = fn () => (string) $this->get(route('accounts.report.show', ['slug' => 'profit-loss', 'from' => '2026-07-01', 'to' => '2027-06-30']))
            ->assertOk()->getContent();

        // ⓘ জাবেদার আগে — লাইন দুটো নেই, পর্দা আগের মতো
        $this->assertStringNotContainsString((string) __('accounts::message.profit_before_tax'), $page());

        $this->switchOn();
        $this->post(route('hr.wppf.provision', ['year' => $this->year->id]))->assertSessionHasNoErrors();

        $after = $page();
        $wppf = strpos($after, __('accounts::message.pl_wppf_line').': ');
        $pbt = strpos($after, __('accounts::message.profit_before_tax').': ');
        $this->assertNotFalse($wppf, '⛔ লাভ-ক্ষতিতে তহবিলের লাইন নেই');
        $this->assertNotFalse($pbt, '⛔ লাভ-ক্ষতিতে কর-পূর্ব মুনাফা নেই');
        $this->assertLessThan($pbt, $wppf, '⛔ তহবিলের লাইন কর-পূর্ব মুনাফার উপরে নয়');
        $this->assertStringContainsString(__('accounts::message.pl_wppf_line').': <span class="num tabular-nums">'.Money::format('6172.87'), $after);
        $this->assertStringContainsString(__('accounts::message.profit_before_tax').': <span class="num tabular-nums">'.Money::format('117284.44'), $after);

        // ⓘ মোট মেলে — রিপোর্টের ডেবিট-ক্রেডিট থেকে নিট = কর-পূর্ব মুনাফা, আর তহবিল + কর-পূর্ব = তহবিলের আগের মুনাফা
        $pl = app(ReportEngine::class)->run('accounts.profit_loss', ['from' => '2026-07-01', 'to' => '2027-06-30', 'branch_id' => null]);
        $this->assertSame('117284.4400', bcsub((string) $pl->totals['credit'], (string) $pl->totals['debit'], 4));
        $lines = array_column(($pl->report->summary)($pl->totals, $pl->filters)['lines'], 'value', 'label');
        $this->assertSame(0, bccomp('6172.87', (string) $lines[__('accounts::message.pl_wppf_line')], 4));
        $this->assertSame(0, bccomp('123457.31', bcadd((string) $lines[__('accounts::message.pl_wppf_line')], (string) $lines[__('accounts::message.profit_before_tax')], 4), 4),
            '⛔ তহবিল + কর-পূর্ব মুনাফা ≠ তহবিলের আগের মুনাফা — পয়সা হারাল');
        $row = collect($pl->rows)->first(fn ($r) => (int) ((array) $r)['account_id'] === (int) StandardChart::find(StandardChart::WPPF_EXPENSE)->id);
        $this->assertNotNull($row, '⛔ ৫২২৩-এর সারি লাভ-ক্ষতিতে নেই');
        $this->assertSame((string) __('accounts::message.pl_wppf'), (string) ((array) $row)['section'], '⛔ ৫২২৩-এর সারি নিজের ভাগে নয়');
    }

    public function test_a_loss_year_has_no_fund_and_no_journal(): void
    {
        $this->switchOn();
        $this->book(3, '2027-01-20', '5202', '200000.00', 'debit');

        $figures = app(WppfService::class)->figuresFor($this->year);
        $this->assertSame(-1, bccomp($figures['net_profit'], '0', 4));
        $this->assertSame('0.0000', $figures['wppf'], '⛔ ক্ষতির বছরে তহবিল।');

        $this->post(route('hr.wppf.provision', ['year' => $this->year->id]))->assertSessionHasErrors('provision');
        $this->assertSame(0, LedgerEntry::query()->where('source_type', WppfProvision::SOURCE_TYPE)->count());
    }

    public function test_a_branch_limited_or_keyless_user_does_not_get_the_whole_companys_fund(): void
    {
        $this->switchOn();

        $limited = $this->person(['hr.wppf.view', 'hr.wppf.manage']);
        $other = Branch::query()->create(['company_id' => $this->company->id, 'code' => 'WPF', 'name_en' => 'Second', 'is_active' => true]);
        UserDataScope::query()->create(['company_id' => $this->company->id, 'user_id' => $limited->id,
            'scope_type' => UserDataScope::BRANCH, 'scope_id' => $other->id]);

        $this->as($limited)->get(route('hr.wppf.show'))->assertForbidden();
        $this->as($limited)->post(route('hr.wppf.provision', ['year' => $this->year->id]))->assertForbidden();

        $keyless = $this->person(['hr.employee.view']);
        $this->as($keyless)->get(route('hr.wppf.show'))->assertForbidden();

        // ⓘ দেখার চাবি আছে, জাবেদার নেই — পর্দা খোলে, বোতাম নেই, দরজা ৪০৩
        $viewer = $this->person(['hr.wppf.view']);
        $this->as($viewer)->get(route('hr.wppf.show'))->assertOk()
            ->assertDontSee(route('hr.wppf.provision', ['year' => $this->year->id]), false);
        $this->as($viewer)->post(route('hr.wppf.provision', ['year' => $this->year->id]))->assertForbidden();

        $this->assertSame(0, LedgerEntry::query()->where('source_type', WppfProvision::SOURCE_TYPE)->count());
    }

    /** ⭐ খাতার সারি নিজের বছরের পাতা খোলে (মালিকের লিংকের নিয়ম, ২৩ নভেম্বর ২০২৬); চাবি ছাড়া নয় */
    public function test_the_ledger_row_opens_the_years_page_and_not_without_the_key(): void
    {
        $this->switchOn();
        app(WppfService::class)->provision($this->year, $this->owner);
        $row = WppfProvision::query()->sole();
        $this->assertTrue(LedgerEntry::query()->where('source_type', WppfProvision::SOURCE_TYPE)->where('source_id', $row->id)->exists(), 'প্রস্তুতি — জাবেদা নেই।');

        $target = app(\App\Core\Engines\Drill\DrillResolver::class)->describe(WppfProvision::SOURCE_TYPE, $row->id);
        $this->assertTrue($target['resolved'], '⛔ খাতার সারি থেকে তহবিলের কাগজ মেলে না।');
        $this->assertSame(route('hr.wppf.show', ['year' => $this->year->id]), route($target['route'][0], $target['route'][1]));
        $this->get(route($target['route'][0], $target['route'][1]))->assertOk()->assertViewHas('year', fn ($y) => (int) $y->id === (int) $this->year->id);

        $this->as($this->person(['hr.employee.view', 'accounts.report']))->get(route($target['route'][0], $target['route'][1]))->assertForbidden();
    }

    // ── যন্ত্রপাতি ──

    private function switchOn(): void
    {
        app(SettingsService::class)->set(WppfService::SETTING, true);
    }

    /** ⓘ আয় বা খরচের একটা সারি, উল্টো দিকে মালিকের মূলধন */
    private function book(int $id, string $date, string $code, string $amount, string $side): void
    {
        $account = (int) StandardChart::find($code)->id;
        $capital = (int) StandardChart::find(StandardChart::OWNER_CAPITAL)->id;
        $other = $side === 'credit' ? 'debit' : 'credit';

        app(PostingEngine::class)->post(sourceType: 'test.wppf_seed', sourceId: $id, trxDate: $date, lines: [
            ['account_id' => $account, $side => $amount, $other => '0'],
            ['account_id' => $capital, $other => $amount, $side => '0'],
        ], documentNo: 'WPPF-SEED-'.$id, branchId: $this->company->defaultBranch()?->id);
    }

    /** @param list<string> $keys */
    private function person(array $keys): User
    {
        $user = User::factory()->create(['current_company_id' => $this->company->id, 'is_active' => true]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);
        CompanyContext::forCompany($this->company->id, function () use ($user, $keys) {
            foreach ($keys as $key) {
                $user->givePermissionTo(Permission::findOrCreate($key, 'web'));
            }
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        FieldSecurity::forget();

        return $user->fresh();
    }

    private function as(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user->fresh());
    }
}
