<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Engines\Report\ReportResult;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Dashboard\FinanceDashboard;
use App\Modules\Finance\Models\HandLoanAccount;
use App\Modules\Finance\Models\HandLoanMovement;
use App\Modules\Finance\Reports\HandLoanReports;
use App\Modules\Finance\Services\HandLoanService;
use App\Modules\MasterData\Models\Person;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * ⭐ হাতধারের পরিশোধের সময়সূচি, ফেরতের তারিখ, ব্যক্তির ধরন আর ড্যাশবোর্ডের "ফেরতের দিন পার" — অর্থ-মডিউলের পরিকল্পনা
 * ১.৮ ও ১-এর শেষ দুই লাইন, ৫ অক্টোবর ২০২৬ ([[HandLoanReports::SCHEDULE]])।
 *
 * ⭐ দাবি:
 *   · প্রতিটা দেওয়া-নেওয়ার নিজের ফেরতের তারিখ; সারি = বাকি টুকরো, পুরনোটা আগে শোধ — তাই আংশিক ফেরতের পরে বাকিটাই দেখায়
 *   · তারিখ না দিলে হিসাবের তারিখ, তাও না থাকলে "তারিখ নেই"; দিন পার / আজ / সামনে ঠিক রিপোর্টের তারিখ ধরে
 *   · দুই দিক দুই কলামে; "দিন পার" ছাঁকনি কেবল দিন পার
 *   · ফেরতের তারিখ দেওয়ার দিনের আগে হয় না
 *   · ড্যাশবোর্ডের সতর্কতা = সময়সূচির "দিন পার" — মানুষ একবার করে গোনা
 *   · ব্যক্তির ধরন তালিকা থেকে বসে, আর পাওনার রিপোর্টে দেখায়
 */
final class HandLoanScheduleTest extends TestCase
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
        $this->putMoneyIn(Account::query()->findOrFail($this->till), '100000', now()->subDays(60)->toDateString());
    }

    public function test_each_open_part_keeps_its_own_return_day_and_a_part_repaid_leaves(): void
    {
        $karim = $this->person('Karim Sch');
        $a = $this->open($karim);
        $this->move($a, HandLoanMovement::OUT, '5000', 20, returnIn: -5);
        $this->move($a, HandLoanMovement::OUT, '1000', 3, returnIn: 10);
        // ⓘ ২০০০ ফেরত — পুরনো ৫০০০ থেকে কাটে, তাই বাকি ৩০০০ (দিন পার) + ১০০০ (সামনে)
        $this->move($a, HandLoanMovement::IN, '2000', 1);

        $rows = $this->rowsOf($this->report(), $karim);

        $this->assertCount(2, $rows);
        $this->assertSame(now()->subDays(5)->toDateString(), substr((string) $rows[0]['return_on'], 0, 10));
        $this->assertSame(0, bccomp((string) $rows[0]['they_owe'], '3000', 4), '⛔ আংশিক ফেরতের পরে পুরনো ধারের বাকিটা ঠিক নয়।');
        $this->assertSame(HandLoanReports::OVERDUE, $rows[0]['state']);
        $this->assertSame(-5, (int) $rows[0]['days_left']);
        $this->assertSame(0, bccomp((string) $rows[1]['they_owe'], '1000', 4));
        $this->assertSame(HandLoanReports::UPCOMING, $rows[1]['state']);
        $this->assertSame(10, (int) $rows[1]['days_left']);
    }

    public function test_no_own_date_falls_back_to_the_loans_date_then_to_none(): void
    {
        $withDate = $this->person('Dated Sch');
        $none = $this->person('Undated Sch');
        $this->move(app(HandLoanService::class)->open(['person_id' => $withDate->id, 'next_due_on' => now()->toDateString()]),
            HandLoanMovement::OUT, '700', 4);
        $this->move($this->open($none), HandLoanMovement::OUT, '300', 4);

        $this->assertSame(HandLoanReports::DUE_TODAY, $this->rowsOf($this->report(), $withDate)[0]['state'], 'হিসাবের তারিখ খাটেনি।');
        $undated = $this->rowsOf($this->report(), $none)[0];
        $this->assertSame(HandLoanReports::UNDATED, $undated['state']);
        $this->assertNull($undated['return_on']);
    }

    public function test_what_we_owe_sits_in_its_own_column_and_the_overdue_filter_keeps_only_the_overdue(): void
    {
        $karim = $this->person('Karim Flt');
        $rahim = $this->person('Rahim Flt');
        $this->move($this->open($karim), HandLoanMovement::OUT, '900', 10, returnIn: 5);
        $this->move($this->open($rahim), HandLoanMovement::IN, '2000', 10, returnIn: -2);

        $ours = $this->rowsOf($this->report(), $rahim)[0];
        $this->assertSame(0, bccomp((string) $ours['we_owe'], '2000', 4));
        $this->assertSame(0, bccomp((string) $ours['they_owe'], '0', 4), 'আমাদের দেনা "তিনি দেবেন" কলামে উঠল।');

        $overdue = $this->report(['state' => HandLoanReports::OVERDUE]);
        $this->assertSame([], $this->rowsOf($overdue, $karim), '⛔ সামনের তারিখের সারি "দিন পার"-এ উঠল।');
        $this->assertCount(1, $this->rowsOf($overdue, $rahim));
    }

    public function test_a_return_day_before_the_day_of_the_money_is_refused(): void
    {
        $a = $this->open($this->person('Karim Val'));

        $this->post(route('finance.hand_loan.move', $a), [
            'direction' => HandLoanMovement::OUT, 'amount' => '500', 'moved_on' => now()->toDateString(),
            'return_on' => now()->subDay()->toDateString(), 'money_account_id' => $this->till,
        ])->assertSessionHasErrors('return_on');

        $this->post(route('finance.hand_loan.move', $a), [
            'direction' => HandLoanMovement::OUT, 'amount' => '500', 'moved_on' => now()->toDateString(),
            'return_on' => now()->addDays(15)->toDateString(), 'money_account_id' => $this->till,
        ])->assertSessionHasNoErrors();

        $this->assertSame(now()->addDays(15)->toDateString(), HandLoanMovement::query()->latest('id')->first()?->return_on?->toDateString(),
            'ফর্মের ফেরতের তারিখ চলাচলে বসেনি।');
    }

    public function test_the_dashboard_warning_is_the_schedules_overdue_people_counted_once(): void
    {
        config(['abos.dashboards_v2' => true]);

        $karim = $this->person('Karim Dash');
        $salam = $this->person('Salam Dash');
        $a = $this->open($karim);
        $this->move($a, HandLoanMovement::OUT, '1000', 20, returnIn: -6);
        $this->move($a, HandLoanMovement::OUT, '500', 15, returnIn: -1);
        $this->move($this->open($salam), HandLoanMovement::OUT, '800', 10, returnIn: 20);

        $method = new \ReflectionMethod(FinanceDashboard::class, 'handLoansOverdue');
        $stats = $method->invoke(null);

        $this->assertCount(1, $stats);
        $overdue = $this->report(['state' => HandLoanReports::OVERDUE]);
        $theirs = array_reduce($overdue->rows, fn (string $s, array $r) => bcadd($s, (string) $r['they_owe'], 4), '0');
        $this->assertSame(\App\Core\Support\Money::format($theirs), $stats[0]->value, '⛔ ড্যাশবোর্ড আর সময়সূচি দুই সংখ্যা বলে।');
        $people = count(array_unique(array_map(fn (array $r) => (int) $r['person_id'],
            array_filter($overdue->rows, fn (array $r) => bccomp((string) $r['they_owe'], '0', 4) > 0))));
        $this->assertStringContainsString((string) $people, (string) $stats[0]->hint);
        $this->assertGreaterThanOrEqual(1, $people);
        // ⓘ করিমের দুই টুকরোই দিন পার — তবু তিনি একজন
        $this->assertCount(2, $this->rowsOf($overdue, $karim));
    }

    public function test_a_persons_kind_is_kept_and_shown_in_the_receivable_list(): void
    {
        $this->post(route('master_data.person.store'), [
            'code' => 'P-KIND1', 'name_en' => 'Cousin Kind', 'kind' => Person::RELATIVE, 'is_active' => '1',
        ])->assertSessionHasNoErrors();

        $cousin = Person::query()->where('name_en', 'Cousin Kind')->firstOrFail();
        $this->assertSame(Person::RELATIVE, $cousin->kind, 'ধরনটা তালিকা থেকে বসেনি।');

        $this->move($this->open($cousin), HandLoanMovement::OUT, '400', 2);

        $row = collect(app(ReportEngine::class)->run(HandLoanReports::RECEIVABLE, [], perPage: 1000)->rows)
            ->firstWhere('person_id', $cousin->id);
        $this->assertSame(__('master_data::person_kind.relative'), $row['kind_label'] ?? null, 'পাওনার রিপোর্টে ধরন নেই।');
    }

    // ── ৯ · জের নিশ্চিতকরণের চিঠি ───────────────────────────────────────────────

    public function test_the_letter_says_the_same_balance_as_the_receivable_list_and_turns_with_the_side(): void
    {
        $karim = $this->person('Karim Ltr');
        $rahim = $this->person('Rahim Ltr');
        $none = $this->person('Nobody Ltr');
        $a = $this->open($karim);
        $this->move($a, HandLoanMovement::OUT, '5000', 9);
        $this->move($a, HandLoanMovement::IN, '1250', 2);
        $this->move($this->open($rahim), HandLoanMovement::IN, '800', 3);

        $theirs = $this->letterFor($karim);
        $owed = collect(app(ReportEngine::class)->run(HandLoanReports::RECEIVABLE, [], perPage: 1000)->rows)->firstWhere('person_id', $karim->id);
        $this->assertSame(\App\Core\Support\Money::format((string) $owed['balance']), $theirs['amount'], '⛔ চিঠি আর পাওনা তালিকা দুই সংখ্যা বলে।');
        $this->assertSame(1, $theirs['side']);
        $this->assertStringContainsString($theirs['amount'], $theirs['line']);

        $this->assertSame(-1, $this->letterFor($rahim)['side'], 'আমাদের দেনায় চিঠি "আপনার কাছে পাওনা" বলল।');
        $this->assertSame(\App\Core\Support\Money::format('800'), $this->letterFor($rahim)['amount']);
        $this->assertSame(0, $this->letterFor($none)['side']);

        // ⓘ পেছনের তারিখের চিঠি — ফেরতের আগের দিন পুরো ৫০০০
        $this->assertSame(\App\Core\Support\Money::format('5000'), $this->letterFor($karim, now()->subDays(5)->toDateString())['amount']);

        $stranger = User::factory()->create(['is_active' => true, 'current_company_id' => CompanyContext::id()]);
        $stranger->companies()->attach(CompanyContext::id(), ['is_active' => true]);
        $this->actingAs($stranger)->get(route('finance.hand_loan.letter', $karim))->assertForbidden();
    }

    /** @return array<string, mixed> চিঠির পাতায় যা গেল */
    private function letterFor(Person $person, ?string $asOf = null): array
    {
        $seen = [];
        \Illuminate\Support\Facades\View::composer('finance::hand-loan.print.letter', function ($view) use (&$seen) {
            $seen = $view->getData()['letter'];
        });

        $this->get(route('finance.hand_loan.letter', ['person' => $person->id, 'as_of' => $asOf]))
            ->assertOk()->assertHeader('Content-Type', 'application/pdf');

        return $seen;
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────────────

    /** @param  array<string, mixed>  $filters */
    private function report(array $filters = []): ReportResult
    {
        return app(ReportEngine::class)->run(HandLoanReports::SCHEDULE, $filters, perPage: 1000);
    }

    /** @return list<array<string, mixed>> */
    private function rowsOf(ReportResult $result, Person $person): array
    {
        return array_values(array_filter($result->rows, fn (array $r) => (int) $r['person_id'] === (int) $person->id));
    }

    private function open(Person $person): HandLoanAccount
    {
        return app(HandLoanService::class)->open(['person_id' => $person->id]);
    }

    private function move(HandLoanAccount $account, string $direction, string $amount, int $daysAgo, ?int $returnIn = null): HandLoanMovement
    {
        return app(HandLoanService::class)->move($account, [
            'direction' => $direction, 'amount' => $amount, 'money_account_id' => $this->till,
            'moved_on' => now()->subDays($daysAgo)->toDateString(),
            'return_on' => $returnIn === null ? null : now()->addDays($returnIn)->toDateString(),
        ]);
    }

    private function person(string $name): Person
    {
        return Person::query()->create([
            'company_id' => CompanyContext::id(), 'code' => 'P-'.substr(md5($name), 0, 6), 'name_en' => $name, 'is_active' => true,
        ]);
    }
}
