<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Supplier;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\AccountService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\Supplier\Reports\PrincipalCommissionReport;
use App\Modules\Supplier\Reports\PrincipalLinesReport;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ⭐ প্রিন্সিপালের কমিশনের প্রতিটা অঙ্কে চাপলে তার উৎস খোলে (মালিক, ২৪ অক্টোবর ২০২৬, ছবিসহ: *"যেখানে চিহ্ন দিয়েছি, সেখানে লিংক
 * থাকার কথা, যাতে হিসাব কোথা থেকে এল বোঝা যায়"*)।
 *
 * ⓘ [[APrincipalEarnsOnWhatTheDepotCollectsTest]]-এর একই মাস: আদায় ১,০০,০০০ · কমিশন ৩,৮৫০ (মার্জিন ৩.৮৫%) · অংশ ৯৬,১৫০ ·
 * দেওয়া ২০,০০০ · জের দিতে হবে ৭৬,১৫০; বাদ পড়া কাগজগুলো (অন্য শাখা, নিজের খাতে সরানো, বাতিল রসিদ ও পরিশোধ) খোলা তালিকাতেও নেই।
 *
 * দাবি: পাঁচটা অঙ্ক আর মোটের পাঁচটা লিংক, পর্দা-পাঠকের কথাসহ; প্রতিটা আসল দরজায় ২০০; খোলা তালিকার মোট = রিপোর্টের অঙ্ক, পয়সা
 * পর্যন্ত; সূত্রের বাক্সে রিপোর্টের নিজের হার; শূন্যও খোলে; টাকার সব কলামে ৳।
 */
final class APrincipalsFiguresOpenWhereTheyCameFromTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Branch $a;

    private Branch $b;

    private Supplier $principal;

    private Supplier $other;

    private Customer $dealer;

    private Account $bank;

    private Account $wallet;

    private int $source = 7000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        Carbon::setTestNow('2026-10-05 10:00:00');

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->a = Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'MMS')->firstOrFail();
        $this->b = Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'NTK')->firstOrFail();

        CompanyContext::set($this->company->id, $this->a->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app()->setLocale('en');

        app(StandardChart::class)->install();

        $this->bank = app(AccountService::class)->create(['name_en' => 'Principal drill bank', 'parent_id' => StandardChart::find(StandardChart::BANK)->id]);
        $this->wallet = app(AccountService::class)->create(['name_en' => 'Principal drill wallet', 'parent_id' => StandardChart::find(StandardChart::MOBILE_MONEY)->id]);

        $suppliers = Supplier::query()->onlySuppliers()->orderBy('id')->take(2)->get();
        $this->principal = $suppliers->first();
        $this->other = $suppliers->last();

        $this->principal->forceFill([
            'principal_branch_id' => $this->a->id, 'commission_basis' => 'margin', 'commission_rate' => '3.850',
            'cycle_start_day' => 2, 'cycle_close_day' => 1,
        ])->save();

        $this->dealer = Customer::query()->orderBy('id')->firstOrFail();
        $this->theMonth();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_every_figure_and_every_total_is_a_link_a_screen_reader_can_read(): void
    {
        $html = $this->page('principal-commission');

        foreach (['inflow', 'commission', 'share', 'paid', 'balance'] as $key) {
            $this->assertMatchesRegularExpression('/<a href="[^"]+"[^>]*aria-label="[^"]+"[^>]*data-report-drill="'.$key.'"/', $html,
                "⛔ {$key}: সারির অঙ্কে চাপলে কিছু খোলে না — হিসাব কোথা থেকে এল বোঝা যায় না");
            $this->assertMatchesRegularExpression('/<a href="[^"]+"[^>]*aria-label="[^"]+"[^>]*data-report-drill-total="'.$key.'"/', $html,
                "⛔ {$key}: মোটের অঙ্কে চাপলে কিছু খোলে না");
        }

        $this->assertStringContainsString('aria-label="Open the collections behind ৳1,00,000.00"', $html,
            '⛔ আদায়ের লিংকের পর্দা-পাঠকের কথা নেই, বা অঙ্কটা তাতে নেই');
    }

    public function test_every_money_column_wears_the_taka_sign(): void
    {
        $html = $this->page('principal-commission');

        foreach (['inflow' => '৳1,00,000.00', 'share' => '৳96,150.00', 'paid' => '৳20,000.00'] as $key => $shown) {
            $this->assertMatchesRegularExpression('/data-report-drill="'.$key.'">'.preg_quote($shown, '/').'</', $html,
                "⛔ {$key}: ৳ নেই — কথার জেরে ৳ আছে, খালি অঙ্কে নেই, এক রিপোর্টে দুই ধাঁচ");
        }
    }

    public function test_every_link_opens_through_the_real_door(): void
    {
        $html = $this->page('principal-commission');
        preg_match_all('/<a href="([^"]+)"[^>]*data-report-drill(?:-total)?="([a-z]+)"/', $html, $links, PREG_SET_ORDER);
        $this->assertCount(10, $links, 'দৃশ্যটাই ঠিক নয় — পাঁচটা সারির আর পাঁচটা মোটের লিংক থাকার কথা');

        foreach ($links as [, $href, $key]) {
            $this->get(html_entity_decode($href))->assertStatus(200);
            $this->addToAssertionCount(1);
        }
    }

    /**
     * ⭐ যে অঙ্ক দেখা যায়, তার উৎসও একই চাবিতে খোলে (fe, ২৪ অক্টোবর ২০২৬) — কেবল supplier.report-এর মানুষ প্রতিটা লিংকে ২০০ পান।
     * ⛔ আগে জেরের লিংক accounts-এর খাতায় যেত (accounts.report লাগে) — রিপোর্টের পাঠক সেখানে ৪০৩ পেতেন।
     */
    public function test_someone_with_only_the_report_key_opens_every_link(): void
    {
        $reader = User::factory()->create(['is_active' => true]);
        $reader->companies()->attach($this->company, ['is_active' => true]);
        $reader->forceFill(['current_company_id' => $this->company->id, 'current_branch_id' => $this->a->id])->save();
        CompanyContext::forCompany($this->company->id, fn () => $reader->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate('supplier.report', 'web')));
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($reader->fresh());

        $html = $this->page('principal-commission');
        preg_match_all('/<a href="([^"]+)"[^>]*data-report-drill(?:-total)?="([a-z]+)"/', $html, $links, PREG_SET_ORDER);
        $this->assertCount(10, $links, 'দৃশ্যটাই ঠিক নয় — রিপোর্টের পাঠক দশটা লিংক দেখেন না');

        foreach ($links as [, $href, $key]) {
            $status = $this->get(html_entity_decode($href))->getStatusCode();
            $this->assertSame(200, $status, "⛔ {$key}: কেবল রিপোর্টের চাবির মানুষ অঙ্কটা দেখেন, অথচ তার উৎস খোলে না ({$status})");
        }
    }

    public function test_the_opened_lists_total_exactly_the_figures(): void
    {
        $row = $this->row();
        $one = ['principal' => (string) $this->principal->id, 'from' => $row['period_from'], 'to' => $row['period_to']];

        $collected = app(ReportEngine::class)->run(PrincipalLinesReport::COLLECTIONS, $one);
        $this->assertSame(0, bccomp((string) $collected->totals['amount'], $row['inflow'], 2),
            '⛔ খোলা আদায়ের তালিকার মোট '.$collected->totals['amount'].', রিপোর্টের আদায় '.$row['inflow'].' — দুই কথা');
        // ⓘ বাতিল রসিদ দুই সারিতে আসে (টাকা এল, উল্টো কাগজে গেল) — নিট শূন্য; অন্য শাখা, নিজের খাতে সরানো আর চক্রের আগেরটা নেই
        $amounts = collect($collected->rows)->map(fn ($r) => bcadd((string) ((array) $r)['amount'], '0', 2))->sort()->values()->all();
        $this->assertSame(['-8000.00', '8000.00', '100000.00'], $amounts,
            '⛔ আদায়ের তালিকায় বাদ পড়া কাগজ (অন্য শাখা, নিজের খাতে সরানো, চক্রের আগের) ঢুকেছে, বা গোনা কাগজ বাদ পড়েছে');

        $paid = app(ReportEngine::class)->run(PrincipalLinesReport::PAYMENTS, $one);
        $this->assertSame(0, bccomp((string) $paid->totals['amount'], $row['paid'], 2),
            '⛔ খোলা দেওয়ার তালিকার মোট '.$paid->totals['amount'].', রিপোর্টের দেওয়া '.$row['paid'].' — দুই কথা');

        // ⓘ মোটের সারি — সবাই, প্রত্যেকে নিজের চক্রে
        $all = app(ReportEngine::class)->run(PrincipalLinesReport::COLLECTIONS, []);
        $report = app(ReportEngine::class)->run(PrincipalCommissionReport::KEY, []);
        $this->assertSame(0, bccomp((string) $all->totals['amount'], (string) $report->totals['inflow'], 2),
            '⛔ মোটের আদায়ের তালিকা আর রিপোর্টের মোট আদায় আলাদা');
    }

    public function test_the_formula_box_uses_the_reports_own_rate(): void
    {
        $row = $this->row();
        $html = $this->page('principal-collections', ['principal' => $this->principal->id, 'from' => $row['period_from'], 'to' => $row['period_to']]);

        $this->assertMatchesRegularExpression('/data-report-explain/', $html, '⛔ সূত্রের বাক্স নেই — কমিশন কীভাবে এল বোঝা যায় না');
        $this->assertStringContainsString('collections ৳1,00,000.00 × '.$row['basis_rate'].' = commission ৳3,850.00', $html,
            '⛔ সূত্রের বাক্সে রিপোর্টের নিজের হার ('.$row['basis_rate'].') আর অঙ্ক নেই');
        $this->assertStringContainsString('= ৳96,150.00', $html, '⛔ সূত্রের বাক্সে প্রিন্সিপালের অংশ নেই');
    }

    public function test_a_zero_still_opens_an_empty_list(): void
    {
        // ⓘ ডিসেম্বরে শেষ হওয়া চক্রে কিছুই নেই — সব অঙ্ক শূন্য
        $html = $this->page('principal-commission', ['month' => '2026-12']);

        $this->assertMatchesRegularExpression('/data-report-drill="paid">৳0\.00</', $html, '⛔ শূন্য দেওয়ায় চাপলে কিছু খোলে না — শূন্যও একটা উত্তর');
        preg_match('/<a href="([^"]+)"[^>]*data-report-drill="paid"/', $html, $paid);
        $this->get(html_entity_decode($paid[1] ?? '/missing'))->assertOk();
    }

    public function test_a_principal_given_twice_is_refused_not_a_crash(): void
    {
        $response = $this->get(route('supplier.report.show', ['slug' => 'principal-collections']).'?principal[]=1');

        $this->assertSame(302, $response->getStatusCode(),
            '⛔ একাধিক প্রিন্সিপাল দিলে পাতা খুলে গেল — অ্যারেটা (int)-এ ১ হয়ে অন্য সরবরাহকারীর টাকা দেখাত');
    }

    /** @param  array<string, mixed>  $query */
    private function page(string $slug, array $query = []): string
    {
        return (string) $this->get(route('supplier.report.show', ['slug' => $slug, ...$query]))->assertOk()->getContent();
    }

    /** @return array<string, mixed> */
    private function row(): array
    {
        $row = collect(app(ReportEngine::class)->run(PrincipalCommissionReport::KEY, [])->rows)->firstWhere('supplier_id', $this->principal->id);
        $this->assertNotNull($row, 'দৃশ্যটাই ঠিক নয় — প্রিন্সিপালের সারি নেই');
        $this->assertSame('100000.00', $row['inflow'], 'দৃশ্যটাই ঠিক নয় — আদায় ১,০০,০০০ নয়');

        return $row;
    }

    /** [[APrincipalEarnsOnWhatTheDepotCollectsTest]]-এর গোটা মাস */
    private function theMonth(): void
    {
        $this->receipt($this->a, '100000', '2026-10-03');
        $this->receipt($this->a, '50000', '2026-10-01');
        $this->receipt($this->b, '70000', '2026-10-03');
        $this->book('contra_voucher', '2026-10-03', [
            ['account_id' => $this->bank->id, 'debit' => '5000', 'branch_id' => $this->a->id],
            ['account_id' => $this->wallet->id, 'credit' => '5000', 'branch_id' => $this->b->id],
        ]);
        $cancelled = $this->receipt($this->a, '8000', '2026-10-03');
        app(PostingEngine::class)->reverse('collection', $cancelled, '2026-10-04');

        $this->pay($this->principal, '20000', '2026-10-03');
        $this->pay($this->other, '7000', '2026-10-03');
        $this->pay($this->principal, '9000', '2026-10-01');
        $paidBack = $this->pay($this->principal, '4000', '2026-10-03');
        app(PostingEngine::class)->reverse('payment_voucher', $paidBack, '2026-10-04');
    }

    private function receipt(Branch $branch, string $amount, string $date): int
    {
        return $this->book('collection', $date, [
            ['account_id' => $this->bank->id, 'debit' => $amount, 'branch_id' => $branch->id],
            ['account_id' => StandardChart::find(StandardChart::RECEIVABLE)->id, 'credit' => $amount,
                'party_type' => 'customer', 'party_id' => $this->dealer->id, 'branch_id' => $branch->id],
        ]);
    }

    private function pay(Supplier $to, string $amount, string $date): int
    {
        return $this->book('payment_voucher', $date, [
            ['account_id' => StandardChart::find(StandardChart::PAYABLE)->id, 'debit' => $amount, 'party_type' => 'supplier', 'party_id' => $to->id],
            ['account_id' => $this->bank->id, 'credit' => $amount],
        ]);
    }

    /** @param  list<array<string, mixed>>  $lines */
    private function book(string $type, string $date, array $lines): int
    {
        $id = ++$this->source;
        app(PostingEngine::class)->post($type, $id, $date, $lines, 'T-'.$id, $this->a->id);

        return $id;
    }
}
