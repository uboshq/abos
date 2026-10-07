<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\HandLoanAccount;
use App\Modules\Finance\Models\HandLoanMovement;
use App\Modules\Finance\Reports\HandLoanReports;
use App\Modules\Finance\Services\HandLoanService;
use App\Modules\MasterData\Models\Person;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * ⭐ হাতধারের রিপোর্ট ৩–৭ — অর্থ-মডিউলের পরিকল্পনা, ৫ অক্টোবর ২০২৬ ([[HandLoanReports]])।
 *
 * ⭐ দাবি (পরিকল্পনার নিয়ম: "তালিকার যোগফল = খাতের জের"):
 *   ৩ · মিলানো: "খাতে" কলামের যোগফল = ১১৭০ খাতের জের, হুবহু; "তালিকায়" যোগফল = হাতধারের পাতার বাকির যোগফল; ফাঁক ঠিক
 *       সেখানে যেখানে সত্যিই ফাঁক — খাতায় না-বসা চলাচলের মানুষের সারিতে, আর নাম ছাড়া খাতে বসা টাকা নামহীন সারিতে
 *   ৪/৫ · পাওনা আর দেনা: জন প্রতি বাকি = হাতধারের পাতার বাকি; বড় থেকে ছোট
 *   ৬ · বয়স: পুরনোটা আগে শোধ (FIFO) — ধাপগুলোর যোগ = বাকি, আর শোধ হওয়া পুরনো ধার ৯০+-এ দেখায় না
 *   ৭ · দেওয়া-নেওয়া: খোলা + দেওয়া − নেওয়া = শেষ = পাওনা তালিকার বাকি
 *   · পাতা খোলে, চাবি ছাড়া নয়
 */
final class HandLoanReportsAddUpTest extends TestCase
{
    use PutsMoneyInTheTill;
    use RefreshDatabase;

    private int $till;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();
        $this->till = (int) app(CashTillService::class)->ensurePrimaryTill()->account_id;
        $this->putMoneyIn(Account::query()->findOrFail($this->till), '100000', now()->subDays(95)->toDateString());
    }

    // ── ৩ · মিলানো ─────────────────────────────────────────────────────────────

    public function test_the_books_column_adds_up_to_the_hand_loan_account_and_the_gaps_sit_where_they_are(): void
    {
        $karim = $this->person('Karim Rpt');
        $salam = $this->person('Salam Rpt');
        $a = $this->open($karim);
        $this->move($a, HandLoanMovement::OUT, '5000', 20);
        $this->move($a, HandLoanMovement::IN, '1500', 5);

        // ⓘ খাতায় না-বসা পুরনো চলাচল (খোলার আমদানি, ভাউচার নেই) — তালিকায় আছে, খাতে নেই
        HandLoanMovement::query()->create([
            'company_id' => CompanyContext::id(), 'account_id' => $this->open($salam)->id,
            'direction' => HandLoanMovement::OUT, 'amount' => '700', 'moved_on' => now()->subDays(30)->toDateString(),
        ]);

        // ⓘ জাবেদায় হাতধার খাতে কারও নাম ছাড়া ৩০০
        $head = Account::query()->where('code', StandardChart::HAND_LOAN)->firstOrFail();
        app(PostingEngine::class)->post('test:nameless', 1, now()->subDays(3)->toDateString(), [
            ['account_id' => $head->id, 'debit' => '300', 'credit' => '0'],
            ['account_id' => $this->till, 'debit' => '0', 'credit' => '300'],
        ], 'TST-1');

        // ⓘ আর অন্য পক্ষের নামে ২০০ (গ্রাহক) — আইডি ইচ্ছে করে করিমের সমান: পক্ষের ধরন না দেখলে করিমের সারিতে মিশত।
        // ⓘ নতুন সারিতে হাতধারের খাত গ্রাহক নেয় না (অডিট হিসাব ⚠️১২, [[Account::holdsParty()]]) — তাই এটা নিয়মের আগের পুরনো সারি:
        // ইঞ্জিনে নামছাড়া বসিয়ে, তারপর খাতায় সরাসরি নাম, ঠিক যেমন লাইভের পুরনো খাতায় থাকতে পারে
        app(PostingEngine::class)->post('test:other-party', 2, now()->subDays(3)->toDateString(), [
            ['account_id' => $head->id, 'debit' => '200', 'credit' => '0'],
            ['account_id' => $this->till, 'debit' => '0', 'credit' => '200'],
        ], 'TST-2');
        DB::table('ledger_entries')->where('source_type', 'test:other-party')->where('account_id', $head->id)
            ->update(['party_type' => 'customer', 'party_id' => $karim->id]);

        $result = $this->report(HandLoanReports::RECONCILE);
        $rows = collect($result->rows)->keyBy(fn (array $r) => $r['person_id'] === null ? 'nameless' : (int) $r['person_id']);

        $this->assertSame(0, bccomp((string) $result->totals['books_balance'], $this->headBalance(), 4),
            '⛔ "খাতে" কলামের যোগফল ১১৭০ খাতের জেরের সমান নয়।');
        $this->assertSame(0, bccomp((string) $result->totals['list_balance'], $this->listTotal(), 4),
            '⛔ "তালিকায়" কলামের যোগফল হাতধারের পাতার যোগফল নয়।');

        $this->assertSame(0, bccomp((string) $rows[$karim->id]['gap'], '0', 4), 'খাতায় বসা হাতধারে ফাঁক দেখাল।');
        $this->assertSame(0, bccomp((string) $rows[$salam->id]['gap'], '700', 4), 'খাতায় না-বসা চলাচলের ফাঁক তাঁর সারিতে নেই।');
        $this->assertSame(0, bccomp((string) $rows['nameless']['books_balance'], '500', 4),
            'নাম ছাড়া বা অন্য পক্ষের নামে খাতে বসা টাকা নামহীন সারিতে নেই।');
        $this->assertSame(0, bccomp((string) $result->totals['gap'], '200', 4));

        $summary = ($result->report->summary)($result->totals);
        $this->assertFalse($summary['good'], 'ফাঁক থাকতেও মিলানো "মিলেছে" বলল।');

        // ⓘ পেছনের তারিখের ছবি — জাবেদা দুইটা তখনো হয়নি, তাই নামহীন সারি নেই আর খাতের যোগফল ঐ দিনের জের
        $then = $this->report(HandLoanReports::RECONCILE, ['to' => now()->subDays(4)->toDateString()]);
        $this->assertFalse(collect($then->rows)->contains(fn (array $r) => $r['person_id'] === null), '⛔ পরের তারিখের খাতের সারি পেছনের ছবিতে ঢুকল।');
        $this->assertSame(0, bccomp((string) $then->totals['books_balance'], $this->headBalance(now()->subDays(4)->toDateString()), 4));
    }

    public function test_with_every_movement_in_the_books_the_list_and_the_account_agree(): void
    {
        $a = $this->open($this->person('Karim Clean'));
        $this->move($a, HandLoanMovement::OUT, '4000', 12);
        $this->move($this->open($this->person('Rahim Clean')), HandLoanMovement::IN, '2500', 8);

        $result = $this->report(HandLoanReports::RECONCILE);

        $this->assertSame(0, bccomp((string) $result->totals['list_balance'], (string) $result->totals['books_balance'], 4));
        $this->assertSame(0, bccomp((string) $result->totals['books_balance'], $this->headBalance(), 4));
        $this->assertTrue(($result->report->summary)($result->totals)['good']);
    }

    // ── ৪/৫ · পাওনা আর দেনা ───────────────────────────────────────────────────

    public function test_receivable_and_payable_are_each_persons_hand_loan_balance_biggest_first(): void
    {
        $karim = $this->person('Karim Due');
        $salam = $this->person('Salam Due');
        $rahim = $this->person('Rahim Due');
        $this->move($this->open($karim), HandLoanMovement::OUT, '3500', 9);
        $this->move($this->open($salam), HandLoanMovement::OUT, '9000', 4);
        $this->move($this->open($rahim), HandLoanMovement::IN, '2000', 2);

        // ⓘ সাধারণ জাবেদায় হাতধার খাতে সালামের নামে ২৫০ — হাতধারের পাতা এটা গোনে, পাওনাও গুনবে
        $head = Account::query()->where('code', StandardChart::HAND_LOAN)->firstOrFail();
        app(PostingEngine::class)->post('test:loose', 3, now()->subDays(1)->toDateString(), [
            ['account_id' => $head->id, 'debit' => '250', 'credit' => '0', 'party_type' => 'person', 'party_id' => $salam->id],
            ['account_id' => $this->till, 'debit' => '0', 'credit' => '250'],
        ], 'TST-3');

        $they = $this->report(HandLoanReports::RECEIVABLE)->rows;
        $we = $this->report(HandLoanReports::PAYABLE)->rows;

        $this->assertSame([$salam->id, $karim->id], array_map(fn (array $r) => (int) $r['person_id'], $this->only($they, [$karim, $salam, $rahim])),
            'পাওনা তালিকা বড় থেকে ছোট নয়, অথবা দেনার মানুষ পাওনায় উঠল।');
        $this->assertSame([$rahim->id], array_map(fn (array $r) => (int) $r['person_id'], $this->only($we, [$karim, $salam, $rahim])));

        $people = collect(app(HandLoanService::class)->people()['rows'])->keyBy(fn (array $r) => (int) $r['person']->id);
        foreach ($this->only($they, [$karim, $salam]) as $row) {
            $this->assertSame(0, bccomp((string) $row['balance'], (string) $people[(int) $row['person_id']]['balance'], 4),
                '⛔ পাওনা তালিকা আর হাতধারের পাতা দুই সংখ্যা বলে।');
        }
        $this->assertSame(0, bccomp((string) $this->only($they, [$salam])[0]['balance'], '9250', 4), 'খাতে তাঁর নামের সাধারণ সারি পাওনায় গোনা হয়নি।');
        $this->assertSame(0, bccomp((string) $this->only($we, [$rahim])[0]['balance'], '2000', 4));
    }

    // ── ৬ · বয়স ────────────────────────────────────────────────────────────────

    public function test_ageing_takes_repayments_off_the_oldest_loan_first(): void
    {
        $karim = $this->person('Karim Age');
        $rahim = $this->person('Rahim Age');
        $a = $this->open($karim);
        $this->move($a, HandLoanMovement::OUT, '5000', 92);
        $this->move($a, HandLoanMovement::OUT, '1000', 10);
        $this->move($a, HandLoanMovement::IN, '4500', 5);
        $this->move($this->open($rahim), HandLoanMovement::IN, '2000', 40);

        $row = $this->only($this->report(HandLoanReports::AGE_RECEIVABLE)->rows, [$karim])[0];

        // ⓘ বাকি ১৫০০ = নতুন ১০০০ (১০ দিনের) + পুরনোটার ৫০০ (৯২ দিনের)
        $this->assertSame(0, bccomp((string) $row['bucket_0'], '1000', 4), '⛔ নতুন ধারটা ০–৩০ ঘরে পুরো নেই — ফেরত নতুনটা থেকে কাটা হলো।');
        $this->assertSame(0, bccomp((string) $row['bucket_30'], '0', 4));
        $this->assertSame(0, bccomp((string) $row['bucket_60'], '0', 4));
        $this->assertSame(0, bccomp((string) $row['bucket_90'], '500', 4), '⛔ পুরনো ধারের বাকি অংশ ৯০+ ঘরে নেই।');
        $this->assertSame(0, bccomp((string) $row['balance'], '1500', 4));
        $this->assertSame(now()->subDays(92)->toDateString(), substr((string) $row['oldest'], 0, 10));

        $owe = $this->only($this->report(HandLoanReports::AGE_PAYABLE)->rows, [$rahim])[0];
        $this->assertSame(0, bccomp((string) $owe['bucket_30'], '2000', 4), 'দেনার বয়স ৩১–৬০ ঘরে নেই।');

        // ⓘ পাওনার বয়সের মোট = পাওনা তালিকার মোট
        $this->assertSame(0, bccomp((string) $this->report(HandLoanReports::AGE_RECEIVABLE)->totals['balance'],
            (string) $this->report(HandLoanReports::RECEIVABLE)->totals['balance'], 4));
    }

    public function test_a_loan_paid_back_in_full_leaves_no_age_behind(): void
    {
        $karim = $this->person('Karim Paid');
        $a = $this->open($karim);
        $this->move($a, HandLoanMovement::OUT, '2000', 93);
        $this->move($a, HandLoanMovement::IN, '2000', 1);

        $this->assertSame([], $this->only($this->report(HandLoanReports::AGE_RECEIVABLE)->rows, [$karim]),
            '⛔ পুরো ফেরত দেওয়া ধার বয়সের তালিকায় রয়ে গেল।');
    }

    // ── ৭ · দেওয়া-নেওয়া ───────────────────────────────────────────────────────

    public function test_activity_opens_where_the_period_starts_and_closes_on_the_balance(): void
    {
        $karim = $this->person('Karim Act');
        $a = $this->open($karim);
        $this->move($a, HandLoanMovement::OUT, '5000', 30);
        $this->move($a, HandLoanMovement::OUT, '1000', 3);
        $this->move($a, HandLoanMovement::IN, '1500', 2);

        $row = $this->only($this->report(HandLoanReports::ACTIVITY, ['from' => now()->subDays(7)->toDateString()])->rows, [$karim])[0];

        $this->assertSame(0, bccomp((string) $row['opening'], '5000', 4));
        $this->assertSame(0, bccomp((string) $row['given'], '1000', 4));
        $this->assertSame(0, bccomp((string) $row['taken'], '1500', 4));
        $this->assertSame(0, bccomp((string) $row['closing'], '4500', 4));
        $this->assertSame(0, bccomp((string) $row['closing'],
            (string) $this->only($this->report(HandLoanReports::RECEIVABLE)->rows, [$karim])[0]['balance'], 4));
    }

    // ── পাতা ──────────────────────────────────────────────────────────────────

    public function test_every_report_page_opens_and_needs_the_hand_loan_key(): void
    {
        $this->move($this->open($this->person('Karim Page')), HandLoanMovement::OUT, '800', 3);

        foreach (['hand-loan-reconcile', 'hand-loan-receivable', 'hand-loan-payable', 'hand-loan-receivable-age', 'hand-loan-payable-age', 'hand-loan-activity'] as $slug) {
            $html = (string) $this->get(route('finance.report.show', ['slug' => $slug]))->assertOk()->getContent();
            $this->assertStringContainsString('data-hand-loan-reports', $html, $slug.' পাতায় রিপোর্টের সারি নেই।');
        }

        $this->assertStringContainsString('Karim Page', (string) $this->get(route('finance.report.show', ['slug' => 'hand-loan-receivable']))->getContent());
        $this->assertStringContainsString(route('finance.report.show', ['slug' => 'hand-loan-reconcile']),
            (string) $this->get(route('finance.hand_loan.index'))->assertOk()->getContent(), 'হাতধারের পাতায় রিপোর্টের পথ নেই।');

        $stranger = User::factory()->create(['is_active' => true, 'current_company_id' => CompanyContext::id()]);
        $stranger->companies()->attach(CompanyContext::id(), ['is_active' => true]);
        $this->actingAs($stranger)->get(route('finance.report.show', ['slug' => 'hand-loan-receivable']))->assertForbidden();
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────────────

    /** @param  array<string, mixed>  $filters */
    private function report(string $key, array $filters = []): \App\Core\Engines\Report\ReportResult
    {
        return app(ReportEngine::class)->run($key, $filters, perPage: 1000);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<Person>  $people
     * @return list<array<string, mixed>>
     */
    private function only(array $rows, array $people): array
    {
        $ids = array_map(fn (Person $p) => (int) $p->id, $people);

        return array_values(array_filter($rows, fn (array $r) => in_array((int) ($r['person_id'] ?? 0), $ids, true)));
    }

    private function headBalance(?string $upTo = null): string
    {
        $heads = Account::query()->where('code', StandardChart::HAND_LOAN)->firstOrFail()->selfAndDescendants()->modelKeys();

        return bcadd((string) LedgerEntry::query()->whereIn('account_id', $heads)
            ->when($upTo, fn ($q, $d) => $q->where('trx_date', '<=', $d))
            ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as n')->value('n'), '0', 4);
    }

    private function listTotal(): string
    {
        return bcadd((string) app(HandLoanService::class)->people()['balance'], '0', 4);
    }

    private function open(Person $person): HandLoanAccount
    {
        return app(HandLoanService::class)->open(['person_id' => $person->id]);
    }

    private function move(HandLoanAccount $account, string $direction, string $amount, int $daysAgo): HandLoanMovement
    {
        return app(HandLoanService::class)->move($account, [
            'direction' => $direction, 'amount' => $amount, 'money_account_id' => $this->till, 'moved_on' => now()->subDays($daysAgo)->toDateString(),
        ]);
    }

    private function person(string $name): Person
    {
        return Person::query()->create([
            'company_id' => CompanyContext::id(), 'code' => 'P-'.substr(md5($name), 0, 6), 'name_en' => $name, 'is_active' => true,
        ]);
    }
}
