<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Approval;
use App\Models\Branch;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\PeriodLock;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\TrialBalanceStatement;
use Carbon\Carbon;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ⭐ রেওয়ামিল NEXUS-এর নমুনার মানে, আমাদের নিয়মে (মালিক, ১৭ নভেম্বর ২০২৬ "ok"; [[TrialBalanceStatement]], [[TrialBalancePaper]])।
 *
 *   - হিসাবের ধরন ধরে দল (সম্পদ → দায় → মালিকানা স্বত্ব → আয় → খরচ), প্রতিটার উপমোট = সারিগুলোর যোগ; কোড নিজের কলামে
 *   - উপরে অবস্থার সারি: মেলে কি না; এই সময়ের খসড়া আর অনুমোদন-বাকি ভাউচার (গোনা হয়, যোগে নয়); উল্টো জের; তালা দেওয়া মাস
 *   - উল্টো জেরে ⚠ — এখানে একটা সম্পদের খাত ক্রেডিটে ৮,২০০
 *   - কাগজে সবুজ "মিলেছে" সনদ আর তিন সই; বাংলা ও ইংরেজি — কাঁচা চাবি নয়
 *   - শাখার দেয়াল অবস্থার সারিতেও; দরজা রেওয়ামিলের চাবি চায়
 */
final class TheTrialBalanceSaysWhereItStandsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Account $box;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-16 12:00'));
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $id = fn (string $c) => (int) Account::query()->where('code', $c)->value('id');

        // ⓘ সম্পদের একটা খাত, যা থেকে বেতন দেওয়া হল অথচ টাকা ঢোকেনি — ক্রেডিটে ৮,২০০ (উল্টো জের)
        $this->box = Account::query()->create([
            'company_id' => $this->company->id, 'code' => '1199', 'name_en' => 'Petty box', 'name_bn' => 'খুচরা বাক্স',
            'parent_id' => $id('1100'), 'type' => Account::ASSET, 'nature' => Account::DEBIT, 'is_group' => false,
            'is_active' => true, 'status' => DocumentStatus::CONFIRMED,
        ]);
        app(PostingEngine::class)->post(sourceType: 'test.tb_wrong_side', sourceId: 1, trxDate: '2026-11-10', lines: [
            ['account_id' => $id('5201'), 'debit' => '8200'],
            ['account_id' => $this->box->id, 'credit' => '8200'],
        ]);

        // ⓘ নভেম্বরের দুই খসড়া ভাউচার (একটা অনুমোদন-বাকি), আর অক্টোবরের একটা — শেষটা এই সময়ের নয়
        $this->draft('JV-T1', '2026-11-05', '1500', awaiting: true);
        $this->draft('JV-T2', '2026-11-12', '2500');
        $this->draft('JV-T0', '2026-10-20', '9999');

        // ⓘ অক্টোবর আর নভেম্বর তালা — নভেম্বরের রেওয়ামিলে কেবল নভেম্বর
        PeriodLock::query()->create(['company_id' => $this->company->id, 'year' => 2026, 'month' => 10, 'locked_at' => now()]);
        PeriodLock::query()->create(['company_id' => $this->company->id, 'year' => 2026, 'month' => 11, 'locked_at' => now()]);
    }

    public function test_the_classes_their_subtotals_and_the_grand_total_are_the_books(): void
    {
        $tb = app(TrialBalanceStatement::class)->build(['from' => '2026-11-01', 'to' => '2026-11-16']);

        // ⓘ যে ধরনে জের আছে কেবল সে দল — ক্রম সবসময় সম্পদ → দায় → মালিকানা স্বত্ব → আয় → খরচ
        $types = array_column($tb['groups'], 'type');
        $this->assertSame(array_values(array_intersect(TrialBalanceStatement::CLASSES, $types)), $types,
            '⛔ দলের ক্রম সম্পদ → দায় → মালিকানা স্বত্ব → আয় → খরচ নয়');
        $this->assertContains('asset', $types);
        $this->assertContains('expense', $types);

        foreach ($tb['groups'] as $group) {
            foreach (TrialBalanceStatement::CELLS as $cell) {
                $sum = array_reduce($group['rows'], fn (string $t, array $r) => bcadd($t, $r['values'][$cell], 4), '0');
                $this->assertSame(0, bccomp($sum, $group['sum'][$cell], 4), "⛔ {$group['type']}-এর {$cell} উপমোট সারিগুলোর যোগ নয়");
            }
            foreach ($group['rows'] as $row) {
                $this->assertSame($group['type'], Account::query()->whereKey($row['account_id'])->value('type'), "⛔ {$row['code']} ভুল দলে");
            }
        }

        // ⓘ শেষ জের = খাতা: সব খাতের ১৬ নভেম্বর পর্যন্ত নিট; লেনদেন = নভেম্বরের সব ডেবিট
        $books = DB::table('ledger_entries')->where('company_id', $this->company->id)->where('trx_date', '<=', '2026-11-16')
            ->groupBy('account_id')->selectRaw('SUM(debit) - SUM(credit) as net')->pluck('net');
        $closingDebit = $books->filter(fn ($n) => bccomp((string) $n, '0', 4) > 0)->reduce(fn ($t, $n) => bcadd($t, (string) $n, 4), '0');
        $periodDebit = (string) DB::table('ledger_entries')->where('company_id', $this->company->id)
            ->whereBetween('trx_date', ['2026-11-01', '2026-11-16'])->sum('debit');

        $this->assertSame(0, bccomp($closingDebit, $tb['total']['debit'], 4), '⛔ শেষ জেরের মোট ডেবিট খাতার সাথে মেলে না');
        $this->assertSame(0, bccomp($periodDebit, $tb['total']['period_debit'], 4), '⛔ এই সময়ের মোট ডেবিট খাতার সাথে মেলে না — খসড়া যোগ হয়েছে?');
        $this->assertTrue($tb['agrees'], '⛔ খাতা মেলে অথচ রেওয়ামিল "মেলেনি" বলে');

        $box = collect($tb['groups'][0]['rows'])->firstWhere('code', '1199');
        $this->assertNotNull($box, '⛔ সম্পদের দলে 1199 নেই');
        $this->assertSame('8200.0000', $box['values']['credit'], '⛔ বাক্সের শেষ জের ক্রেডিটে ৮,২০০ নয়');
        $this->assertTrue($box['wrong_side'], '⛔ ক্রেডিটে থাকা সম্পদ ⚠ পেল না');
        $this->assertSame(['1199'], array_column($tb['wrong_side'], 'code'), '⛔ উল্টো জেরের তালিকায় কেবল 1199 থাকার কথা');
        $this->assertSame('8200.0000', $tb['wrong_side'][0]['amount']);
    }

    public function test_drafts_are_counted_but_never_added_and_only_this_periods_locks_are_named(): void
    {
        $tb = app(TrialBalanceStatement::class)->build(['from' => '2026-11-01', 'to' => '2026-11-16']);

        $this->assertSame(2, $tb['held']['count'], '⛔ নভেম্বরের দুই খসড়া গোনা হয়নি (অক্টোবরেরটা বাদ থাকার কথা)');
        $this->assertSame(1, $tb['held']['awaiting'], '⛔ অনুমোদন-বাকি একটা — গোনা ভুল');
        $this->assertSame('4000.0000', $tb['held']['amount'], '⛔ বাদ পড়া ভাউচারের মোট ১,৫০০ + ২,৫০০ নয়');
        $this->assertCount(1, $tb['locked'], '⛔ নভেম্বরের রেওয়ামিলে অক্টোবরের তালাও এল');
        $this->assertStringContainsString('2026', $tb['locked'][0]);
    }

    public function test_the_screen_shows_the_strip_the_flag_and_the_code_column(): void
    {
        $html = (string) $this->get(route('accounts.report.show', ['slug' => 'trial-balance', 'from' => '2026-11-01', 'to' => '2026-11-16']))
            ->assertOk()->getContent();

        foreach (['data-tb-status', 'data-tb-agrees="yes"', 'data-tb-held="2"', 'data-tb-wrong="1"', 'data-tb-locked="1"', 'data-tb-flag', 'data-tb-account="1199"'] as $mark) {
            $this->assertStringContainsString($mark, $html, "⛔ রেওয়ামিলের পর্দায় {$mark} নেই");
        }

        $positions = array_values(array_filter(array_map(fn (string $t) => strpos($html, 'data-tb-class="'.$t.'"'), TrialBalanceStatement::CLASSES)));
        $this->assertGreaterThanOrEqual(2, count($positions), '⛔ পর্দায় দলের সারি নেই');
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions, '⛔ পর্দায় দলের ক্রম ভুল');
        $this->assertStringNotContainsString('accounts::fs', $html, '⛔ পর্দায় কাঁচা চাবি');
    }

    public function test_the_paper_carries_the_certificate_and_three_signatures_in_both_languages(): void
    {
        $pdf = $this->get(route('accounts.report.show', ['slug' => 'trial-balance', 'from' => '2026-11-01', 'to' => '2026-11-16', 'export' => 'print']))->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('content-type'), '⛔ রেওয়ামিলের প্রিন্ট ভিউ PDF নয়');

        foreach (['bn' => ['প্রস্তুতকারী', 'যাচাইকারী', 'অনুমোদনকারী', 'সম্পদ'], 'en' => ['Prepared by', 'Checked by', 'Approved by', 'Assets']] as $locale => $words) {
            app()->setLocale($locale);
            $html = view('accounts::print.trial-balance', [
                'company' => $this->company, 'locale' => $locale,
                'tb' => app(TrialBalanceStatement::class)->build(['from' => '2026-11-01', 'to' => '2026-11-16']),
                'branch' => 'All', 'printedBy' => 'Owner', 'printedAt' => '16-11-2026', 'signatures' => true,
            ])->render();

            $this->assertStringContainsString('data-cert="agrees"', $html, "⛔ ({$locale}) কাগজে \"মিলেছে\" সনদ নেই");
            $this->assertStringContainsString('data-signs="3"', $html, "⛔ ({$locale}) কাগজে তিন সই নেই");
            foreach ($words as $word) {
                $this->assertStringContainsString($word, $html, "⛔ ({$locale}) কাগজে \"{$word}\" নেই");
            }
            $this->assertStringContainsString('data-tb-flag', $html, "⛔ ({$locale}) কাগজে উল্টো জেরের ⚠ নেই");
            $this->assertDoesNotMatchRegularExpression('/(accounts|core)::/', $html, "⛔ ({$locale}) কাগজে কাঁচা চাবি");
        }
    }

    public function test_one_branch_counts_only_its_own_drafts(): void
    {
        $other = Branch::query()->where('company_id', $this->company->id)->where('code', 'NTK')->firstOrFail();
        $this->draft('JV-T3', '2026-11-13', '700', branchId: (int) $other->id);

        $all = app(TrialBalanceStatement::class)->build(['from' => '2026-11-01', 'to' => '2026-11-16']);
        $one = app(TrialBalanceStatement::class)->build(['from' => '2026-11-01', 'to' => '2026-11-16', 'branch_id' => $other->id]);

        $this->assertSame(3, $all['held']['count'], '⛔ সব শাখায় তিন খসড়া');
        $this->assertSame(1, $one['held']['count'], '⛔ নেত্রকোনা বাছলে অন্য শাখার খসড়াও গোনা হল — শাখার দেয়াল নেই');
        $this->assertSame('700.0000', $one['held']['amount']);
    }

    public function test_the_page_and_the_paper_ask_for_the_trial_balance_key(): void
    {
        $clerk = User::factory()->create(['current_company_id' => $this->company->id]);
        $clerk->companies()->attach($this->company->id, ['is_active' => true]);
        $clerk->givePermissionTo(['accounts.report', 'system_admin.list.export']);
        $page = route('accounts.report.show', ['slug' => 'trial-balance', 'from' => '2026-11-01', 'to' => '2026-11-16']);

        $this->actingAs($clerk)->get($page)->assertForbidden();
        $this->actingAs($clerk)->get($page.'&export=print')->assertForbidden();

        $clerk->givePermissionTo('accounts.report.final');
        $this->actingAs($clerk->fresh())->get($page)->assertOk();
        $this->actingAs($clerk->fresh())->get($page.'&export=print')->assertOk();
    }

    private function draft(string $no, string $date, string $amount, bool $awaiting = false, ?int $branchId = null): void
    {
        $voucher = Voucher::query()->create([
            'company_id' => $this->company->id,
            'branch_id' => $branchId ?? $this->company->defaultBranch()?->id,
            'financial_year_id' => FinancialYear::query()->where('company_id', $this->company->id)->value('id'),
            'type' => Voucher::JOURNAL, 'document_no' => $no, 'trx_date' => $date, 'amount' => $amount,
            'narration' => 'খসড়া', 'status' => DocumentStatus::DRAFT, 'created_by' => auth()->id(),
        ]);

        if ($awaiting) {
            Approval::query()->create([
                'company_id' => $this->company->id, 'approvable_type' => $voucher->getMorphClass(), 'approvable_id' => $voucher->id,
                'module' => 'accounts', 'action' => 'post', 'amount' => $amount, 'status' => Approval::PENDING,
                'requested_by' => auth()->id(), 'requested_at' => now(),
            ]);
        }
    }
}
