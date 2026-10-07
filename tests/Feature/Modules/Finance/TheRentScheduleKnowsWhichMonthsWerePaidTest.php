<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Branch;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\User;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\RentalAdjustment;
use App\Modules\Finance\Models\RentalContract;
use App\Modules\Finance\Reports\RentalReports;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ⭐ ক — ভাড়ার সময়সূচি (অর্থ-মডিউলের পরিকল্পনা, অংশ ৫, ৬ অক্টোবর ২০২৬, [[RentalReports::SCHEDULE]])।
 *
 * ⛔ মাসটা "দেওয়া" কেবল যখন তার ভাউচারে শেষ সই পড়েছে। বিপজ্জনক ইনপুট: সইয়ের অপেক্ষায় মাস, বাতিল ভাউচারের মাস,
 * মুছে-ফেলা (ফিরিয়ে দেওয়া) মাসের সারি, আগেভাগে শেষ চুক্তি, সইয়ের অপেক্ষায় খোলা চুক্তি, মুছে-ফেলা চুক্তি, অন্য
 * কোম্পানির চুক্তি — কোনোটা "দেওয়া" বা তালিকায় দেখালে লাল।
 */
final class TheRentScheduleKnowsWhichMonthsWerePaidTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Branch $mymensingh;

    private Branch $netrakona;

    /** এই মাসের প্রথম দিন, আর তার আগের তিন মাস */
    private Carbon $m0;

    private RentalContract $godown;

    private RentalContract $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->mymensingh = Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'MMS')->firstOrFail();
        $this->netrakona = Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'NTK')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $this->m0 = now()->startOfMonth();

        // ⓘ গুদাম, ময়মনসিংহ — তিন মাস আগের মাঝামাঝি শুরু, এখনো চলছে
        $this->godown = $this->contract('Godown Landlord', $this->mymensingh, '10000', $this->month(-3)->addDays(14));
        // ⓘ দোকান, নেত্রকোনা — একই সময়ে শুরু, গত মাসের ৫ তারিখে আগেভাগে শেষ
        $this->shop = $this->contract('Shop Landlord', $this->netrakona, '5000', $this->month(-3), closedOn: $this->month(-1)->addDays(4));
        // ⓘ দোকানের প্রথম মাস সই-ব্যবস্থার আগের — ভাউচার নেই, তবু দেওয়া (চুক্তির পাতার একই নিয়ম)
        $this->adjust($this->shop, $this->month(-3), '5000', '0', null);

        // m-3: শেষ সই পড়েছে — নগদ ৭০০০ + জামানত থেকে ৩০০০
        $this->adjust($this->godown, $this->month(-3), '7000', '3000', DocumentStatus::CONFIRMED);
        // m-2: সইয়ের অপেক্ষায় — টাকা যায়নি
        $this->adjust($this->godown, $this->month(-2), '10000', '0', DocumentStatus::DRAFT);
        // m-1: ভাউচার পরে বাতিল — মাসটা আবার বাকি
        $this->adjust($this->godown, $this->month(-1), '10000', '0', DocumentStatus::CANCELLED);
        // m0: ফিরিয়ে দেওয়া — সারি মুছে গেছে, ভাউচার যেমনই থাকুক
        $this->adjust($this->godown, $this->m0, '10000', '0', DocumentStatus::CONFIRMED, deleted: true);

        // ⓘ মেয়াদ ফুরিয়েছে, কেউ শেষ করেননি (এখনো "চলছে") — চোদ্দ মাস আগে শুরু, বারো মাসের, তাই m-3-এর শেষ দিনে শেষ
        $this->contract('Expired Landlord', $this->mymensingh, '3000', $this->month(-14));

        // ⛔ কখনো দেখাবে না
        $this->contract('Awaiting Landlord', $this->mymensingh, '777', $this->month(-3), status: RentalContract::AWAITING);
        $this->contract('Deleted Landlord', $this->mymensingh, '888', $this->month(-3))->delete();
        CompanyContext::forCompany((int) Company::query()->where('code', 'FMART')->value('id'),
            fn () => $this->contract('Other Company Landlord', null, '999', $this->month(-3)));
    }

    public function test_each_month_is_paid_only_once_its_voucher_is_signed(): void
    {
        $rows = $this->schedule();

        $godown = array_values(array_filter($rows, fn (array $r) => $r['counterparty'] === 'Godown Landlord'));
        $this->assertCount(4, $godown, 'গুদামের চার মাস — শুরুর আংশিক মাস থেকে এই মাস পর্যন্ত');

        $this->assertMoney('7000', $godown[0]['paid_cash'], 'm-3 নগদ');
        $this->assertMoney('3000', $godown[0]['from_deposit'], 'm-3 জামানত থেকে');
        $this->assertMoney('0', $godown[0]['outstanding'], 'm-3 বাকি');

        $this->assertMoney('0', $godown[1]['paid_cash'], '⛔ সইয়ের অপেক্ষায় মাসটা দেওয়া দেখাল');
        $this->assertMoney('10000', $godown[1]['waiting'], 'm-2 সইয়ের অপেক্ষায়');
        $this->assertMoney('10000', $godown[1]['outstanding'], 'm-2 বাকি');

        $this->assertMoney('0', $godown[2]['paid_cash'], '⛔ বাতিল ভাউচারের মাসটা দেওয়া দেখাল');
        $this->assertMoney('0', $godown[2]['waiting'], 'বাতিল ভাউচার সইয়ের অপেক্ষায় নয়');
        $this->assertMoney('10000', $godown[2]['outstanding'], 'm-1 বাকি');

        $this->assertMoney('0', $godown[3]['paid_cash'], '⛔ মুছে-ফেলা সারির মাসটা দেওয়া দেখাল');
        $this->assertMoney('10000', $godown[3]['outstanding'], 'm0 বাকি');
    }

    public function test_a_contract_closed_early_stops_in_its_closing_month_and_nothing_else_shows(): void
    {
        $rows = $this->schedule();
        $shop = array_filter($rows, fn (array $r) => $r['counterparty'] === 'Shop Landlord');

        $this->assertCount(3, $shop, 'দোকান — m-3, m-2, m-1 (শেষের মাস পর্যন্ত), এই মাস নয়');
        $this->assertSame([$this->month(-4)->toDateString(), $this->month(-3)->toDateString()],
            array_values(array_map(fn (array $r) => substr((string) $r['for_month'], 0, 10), array_filter($rows, fn (array $r) => $r['counterparty'] === 'Expired Landlord'))),
            '⛔ মেয়াদ ফুরানো চুক্তির ভাড়া মেয়াদের পরেও দেয় দেখাল।');
        $this->assertSame([], array_values(array_filter($rows, fn (array $r) => in_array($r['counterparty'],
            ['Awaiting Landlord', 'Deleted Landlord', 'Other Company Landlord'], true))),
            '⛔ সইয়ের অপেক্ষায় খোলা, মুছে-ফেলা বা অন্য কোম্পানির চুক্তি দেখাল।');

        $result = app(ReportEngine::class)->run(RentalReports::SCHEDULE, $this->range());
        $summary = ($result->report->summary)($result->totals);
        $this->assertMoney('46000', $summary['value'], 'মোট বাকি = গুদাম ৩০০০০ + দোকান ১০০০০ (প্রথম মাস ভাউচার ছাড়া দেওয়া) + মেয়াদ ফুরানো ৬০০০');
        $this->assertFalse($summary['good']);
    }

    public function test_one_branch_shows_only_its_own_contracts(): void
    {
        $rows = $this->schedule(['branch_id' => $this->netrakona->id]);

        $this->assertSame(['Shop Landlord'], array_values(array_unique(array_column($rows, 'counterparty'))));
    }

    /** ⓘ শুরু শেষের পরে, বা শত বছরের পরিসর — পাতা ভাঙে না, দশ বছরে থামে */
    public function test_odd_ranges_never_break_the_page(): void
    {
        // ⓘ ইঞ্জিন উল্টো পরিসর আগেই থামায়; মাসের তালিকা নিজেও খালি হয় না (খালি হলে কোয়েরিটাই অবৈধ হত)
        $this->assertSame([$this->month(-2)->toDateString()],
            RentalReports::months(['from' => now()->toDateString(), 'to' => $this->month(-2)->toDateString()])->pluck('m')->map(fn ($m) => (string) $m)->all());

        $century = RentalReports::months(['from' => '1926-01-01', 'to' => now()->toDateString()])->count();
        $this->assertSame(120, $century);
    }

    public function test_the_page_opens_from_the_rental_list(): void
    {
        $this->get(route('finance.rental.index'))->assertOk()->assertSee('data-rental-reports', false)
            ->assertSee(route('finance.rental.report.show', ['slug' => 'schedule']));

        $this->get(route('finance.rental.report.show', ['slug' => 'schedule', ...$this->range()]))->assertOk()
            ->assertSee('Godown Landlord')->assertSee('data-rental-reports', false);

        $this->get(route('finance.rental.report.show', ['slug' => 'nothing']))->assertNotFound();
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    /** @return array{from: string, to: string} */
    private function range(): array
    {
        return ['from' => $this->month(-4)->toDateString(), 'to' => now()->toDateString()];
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return list<array<string, mixed>>
     */
    private function schedule(array $extra = []): array
    {
        $result = app(ReportEngine::class)->run(RentalReports::SCHEDULE, [...$this->range(), ...$extra]);

        return array_map(fn ($r) => (array) $r, $result->rows);
    }

    private function month(int $offset): Carbon
    {
        return $this->m0->copy()->addMonths($offset);
    }

    private function assertMoney(string $expected, mixed $actual, string $message): void
    {
        $this->assertSame(0, bccomp($expected, (string) ($actual ?? '0'), 4), "{$message}: চাই {$expected}, এল ".var_export($actual, true));
    }

    private function contract(string $who, ?Branch $branch, string $rent, Carbon $starts, string $status = RentalContract::ACTIVE, ?Carbon $closedOn = null): RentalContract
    {
        return RentalContract::query()->create([
            'company_id' => CompanyContext::id(),
            'branch_id' => $branch?->id,
            'document_no' => 'RNT-'.random_int(1, 999999),
            'counterparty' => $who,
            'subject' => $who.' place',
            'account_id' => StandardChart::find(StandardChart::SECURITY_DEPOSIT)->id,
            'expense_account_id' => StandardChart::find(StandardChart::RENT)->id,
            'deposit_amount' => '100000',
            'monthly_rent' => $rent,
            'monthly_adjustment' => '0',
            'starts_on' => $starts->toDateString(),
            'term_months' => 12,
            'ends_on' => $starts->copy()->addMonths(12)->subDay()->toDateString(),
            'status' => $closedOn !== null ? RentalContract::CLOSED : $status,
            'closed_on' => $closedOn?->toDateString(),
        ]);
    }

    private function adjust(RentalContract $contract, Carbon $month, string $cash, string $fromDeposit, ?string $voucherStatus, bool $deleted = false): void
    {
        $rent = bcadd($cash, $fromDeposit, 4);

        $voucher = $voucherStatus === null ? null : Voucher::query()->create([
            'company_id' => $this->company->id, 'branch_id' => $contract->branch_id,
            'financial_year_id' => FinancialYear::query()->value('id'), 'type' => Voucher::PAYMENT,
            'document_no' => 'PV-RNT-'.random_int(1, 999999), 'trx_date' => $month->toDateString(), 'amount' => $rent, 'status' => $voucherStatus,
        ]);

        $row = RentalAdjustment::query()->create([
            'company_id' => $this->company->id, 'branch_id' => $contract->branch_id, 'rental_contract_id' => $contract->id,
            'for_month' => $month->toDateString(), 'rent' => $rent, 'paid_cash' => $cash, 'from_deposit' => $fromDeposit,
            'voucher_id' => $voucher?->id,
        ]);

        if ($deleted) {
            $row->delete();
        }
    }
}
