<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Branch;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
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
 * ⭐ খ — অগ্রিম সমন্বয় (অর্থ-মডিউলের পরিকল্পনা, অংশ ৫, ৬ অক্টোবর ২০২৬, [[RentalReports::ADVANCE]])।
 *
 * ⛔ জামানতের প্রতিটা নড়াচড়া খাতা থেকে: দেওয়া আর ফেরত চুক্তির নামে বাঁধা ভাউচারের খতিয়ান-সারি, কাটা কেবল সই-পড়া মাস।
 * বিপজ্জনক ইনপুট: বাতিল (উল্টানো) বাড়ানো, সইয়ের অপেক্ষায় মাস, বাতিল ভাউচারের মাস, ভাউচার ছাড়া পুরনো জামানত, ফেরত দিয়ে
 * শেষ চুক্তি, অন্য শাখা, সইয়ের অপেক্ষায় খোলা আর অন্য কোম্পানির চুক্তি।
 */
final class TheDepositLeftIsReadFromTheBooksTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Branch $mymensingh;

    private Branch $netrakona;

    private Carbon $m0;

    private RentalContract $godown;

    private RentalContract $legacy;

    private RentalContract $returned;

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

        // ⓘ গুদাম, ময়মনসিংহ — ৬০০০০ জামানত ভাউচারে, মাসে ৩০০০ কাটে
        $this->godown = $this->contract('Godown Landlord', $this->mymensingh, '60000', '3000', $this->month(-3)->addDays(14));
        $this->deposit($this->godown, '60000', $this->month(-3)->addDays(14));
        $this->adjust($this->godown, $this->month(-3), '3000', DocumentStatus::CONFIRMED);
        $this->adjust($this->godown, $this->month(-2), '3000', DocumentStatus::DRAFT);
        $this->adjust($this->godown, $this->month(-1), '3000', DocumentStatus::CANCELLED);
        // গত মাসে ৬৫০০ বাড়ানো, শেষ সই পড়েছে — ৩০০০-এর গুণিতক নয়, তাই "আর কত মাস" গোল করা ধরা পড়ে
        $this->deposit($this->godown, '6500', $this->month(-1)->addDays(9));
        $this->godown->update(['deposit_amount' => '66500']);
        // ⛔ অন্য কাগজের নামে বাঁধা, একই id, একই খাতে — চুক্তির জামানত নয়
        $this->deposit($this->godown, '4000', $this->month(-1)->addDays(11), against: 'hand_loan');
        // ⛔ মুছে-ফেলা মাসের সারি, ভাউচার পোস্ট হওয়া — কাটা নয়
        $this->adjust($this->godown, $this->m0, '3000', DocumentStatus::CONFIRMED, deleted: true);
        // ⛔ আরেকটা ৫০০০ বাড়ানো বসে পরে বাতিল (খাতা উল্টানো) — জামানতের অঙ্কে কখনো ওঠেনি
        $this->deposit($this->godown, '5000', $this->month(-1)->addDays(10), reverse: true);

        // ⓘ পুরনো দোকান, নেত্রকোনা — ২০০০০ জামানত খোলা জেরে, কোনো ভাউচার নেই; মাসে ২০০০ কাটে
        $this->legacy = $this->contract('Legacy Landlord', $this->netrakona, '20000', '2000', $this->month(-6));
        $this->adjust($this->legacy, $this->month(-2), '2000', DocumentStatus::CONFIRMED);

        // ⓘ ফেরত দিয়ে শেষ — ১০০০০ দেওয়া, গত মাসে পুরোটা ফেরত, জামানতের অঙ্ক শূন্য
        $this->returned = $this->contract('Returned Landlord', $this->mymensingh, '10000', '0', $this->month(-3));
        $this->deposit($this->returned, '10000', $this->month(-3));
        $this->deposit($this->returned, '10000', $this->month(-1)->addDays(2), refund: true);
        $this->returned->update(['deposit_amount' => '0', 'status' => RentalContract::CLOSED, 'closed_on' => $this->month(-1)->addDays(2)->toDateString()]);

        // ⛔ কখনো দেখাবে না
        $this->contract('Awaiting Landlord', $this->mymensingh, '777', '0', $this->month(-3), RentalContract::AWAITING);
        CompanyContext::forCompany((int) Company::query()->where('code', 'FMART')->value('id'),
            fn () => $this->contract('Other Company Landlord', null, '999', '0', $this->month(-3)));
    }

    public function test_each_contracts_deposit_runs_from_opening_to_closing_out_of_the_books(): void
    {
        $rows = $this->advance($this->month(-1));

        $godown = $this->row($rows, 'Godown Landlord');
        $this->assertMoney('57000', $godown['opening_balance'], 'গুদামের শুরুর জের = ৬০০০০ − সই-পড়া ৩০০০');
        $this->assertMoney('6500', $godown['given'], '⛔ বাতিল ৫০০০ বাড়ানো বা অন্য কাগজের ৪০০০ গোনা হল');
        $this->assertMoney('0', $godown['deducted'], '⛔ সইয়ের অপেক্ষায়, বাতিল ভাউচারের বা মুছে-ফেলা মাস কাটা হল');
        $this->assertMoney('63500', $godown['closing_balance'], 'গুদামের শেষ জের');
        $this->assertSame(21, (int) $godown['months_left'], '৬৩৫০০ ÷ ৩০০০ — পুরো মাস, নিচে গোল');

        $legacy = $this->row($rows, 'Legacy Landlord');
        $this->assertMoney('18000', $legacy['opening_balance'], 'ভাউচার ছাড়া পুরনো জামানত − সই-পড়া ২০০০');
        $this->assertMoney('18000', $legacy['closing_balance'], 'পুরনো দোকান');

        $returned = $this->row($rows, 'Returned Landlord');
        $this->assertMoney('10000', $returned['opening_balance'], 'ফেরতের আগে');
        $this->assertMoney('10000', $returned['refunded'], 'ফেরত');
        $this->assertMoney('0', $returned['closing_balance'], 'ফেরতের পরে');

        $this->assertNull($this->find($rows, 'Awaiting Landlord'), '⛔ সইয়ের অপেক্ষায় খোলা চুক্তি দেখাল।');
        $this->assertNull($this->find($rows, 'Other Company Landlord'), '⛔ অন্য কোম্পানির চুক্তি দেখাল।');
    }

    /** ⓘ পুরনো জামানত চুক্তির শুরুর দিনে "দেওয়া" — শুরুটা পরিসরে পড়লে দেওয়ার ঘরে */
    public function test_an_old_deposit_counts_as_given_on_the_day_the_contract_began(): void
    {
        $legacy = $this->row($this->advance($this->month(-7)), 'Legacy Landlord');

        $this->assertMoney('0', $legacy['opening_balance'], 'শুরুর আগে কিছু নেই');
        $this->assertMoney('20000', $legacy['given'], 'শুরুর দিনে পুরনো জামানত');
        $this->assertMoney('2000', $legacy['deducted'], 'সই-পড়া মাস');
    }

    /** ⭐ ভাউচারে বসা আর সব মাস সই-পড়া হলে শেষ জের চুক্তির পাতার "জামানতে বাকি"-র হুবহু */
    public function test_with_every_month_signed_the_closing_is_what_the_contract_page_says(): void
    {
        $rows = $this->advance($this->month(-7));

        foreach ([$this->legacy, $this->returned] as $contract) {
            $this->assertMoney($contract->fresh()->depositLeft(), $this->row($rows, $contract->counterparty)['closing_balance'],
                "{$contract->counterparty}: রিপোর্ট আর চুক্তির পাতা আলাদা");
        }
    }

    public function test_one_branch_shows_only_its_own_contracts(): void
    {
        $rows = $this->advance($this->month(-1), ['branch_id' => $this->netrakona->id]);

        $this->assertSame(['Legacy Landlord'], array_column($rows, 'counterparty'));
    }

    /** ⭐ গ — জামানতের খাতা: খোলা জের থেকে প্রতিটা কাগজ, শেষ জের অগ্রিম সমন্বয়ের হুবহু */
    public function test_the_deposit_book_runs_paper_by_paper_and_ends_where_the_adjustment_ends(): void
    {
        foreach ([[$this->godown, -1], [$this->godown, -7], [$this->legacy, -7], [$this->returned, -7]] as [$contract, $offset]) {
            $book = $this->book($contract, $this->month($offset));
            $advance = $this->row($this->advance($this->month($offset)), $contract->counterparty);

            $this->assertMoney((string) $advance['closing_balance'], end($book)['balance'], "{$contract->counterparty} {$offset}: খাতার শেষ জের");
        }

        $godown = $this->book($this->godown, $this->month(-1));
        $this->assertMoney('57000', $godown[0]['debit'], 'খোলা জের দেওয়ার ঘরে');
        $this->assertSame([__('finance::rental_report.opening_row'), ...array_fill(0, 3, __('finance::rental_report.given'))], array_column($godown, 'narration'),
            'খোলা জের, ৬০০০ বাড়ানো, বাতিল ৫০০০ আর তার উল্টো — সইয়ের অপেক্ষায় বা বাতিল মাস নয়');

        // ⓘ সব জামানত ভাউচারে বসলে "শুরুর জামানত" সারি নেই; ভাউচার ছাড়া হলে একটাই সারি
        $this->assertNotContains(__('finance::rental_report.legacy'), array_column($this->book($this->godown, $this->month(-7)), 'narration'));
        $legacy = $this->book($this->legacy, $this->month(-7));
        $this->assertSame([__('finance::rental_report.opening_row'), __('finance::rental_report.legacy'), __('finance::rental_report.deducted')], array_column($legacy, 'narration'));
        $this->assertMoney('20000', $legacy[1]['debit'], 'পুরনো জামানত');

        $this->assertSame([], app(ReportEngine::class)->run(RentalReports::DEPOSIT_BOOK, ['from' => $this->month(-7)->toDateString(), 'to' => now()->toDateString()])->rows,
            'চুক্তি না বাছলে কোনো সারি নয়।');
    }

    public function test_the_pages_open(): void
    {
        $this->get(route('finance.rental.report.show', ['slug' => 'advance', 'from' => $this->month(-1)->toDateString()]))
            ->assertOk()->assertSee('Godown Landlord')->assertSee('data-rental-reports', false);

        $this->get(route('finance.rental.report.show', ['slug' => 'deposit-book', 'from' => $this->month(-7)->toDateString(), 'rental_contract_id' => $this->legacy->id]))
            ->assertOk()->assertSee(__('finance::rental_report.legacy'))->assertSee('Godown Landlord');
    }

    /** @return list<array<string, mixed>> */
    private function book(RentalContract $contract, Carbon $from): array
    {
        $result = app(ReportEngine::class)->run(RentalReports::DEPOSIT_BOOK, ['from' => $from->toDateString(), 'to' => now()->toDateString(), 'rental_contract_id' => $contract->id]);

        return array_map(fn ($r) => (array) $r, $result->rows);
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $extra
     * @return list<array<string, mixed>>
     */
    private function advance(Carbon $from, array $extra = []): array
    {
        $result = app(ReportEngine::class)->run(RentalReports::ADVANCE, ['from' => $from->toDateString(), 'to' => now()->toDateString(), ...$extra]);

        return array_map(fn ($r) => (array) $r, $result->rows);
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function find(array $rows, string $who): ?array
    {
        foreach ($rows as $row) {
            if ($row['counterparty'] === $who) {
                return $row;
            }
        }

        return null;
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function row(array $rows, string $who): array
    {
        $row = $this->find($rows, $who);
        $this->assertNotNull($row, "সারি নেই: {$who}");

        return $row;
    }

    private function month(int $offset): Carbon
    {
        return $this->m0->copy()->addMonths($offset);
    }

    private function assertMoney(string $expected, mixed $actual, string $message): void
    {
        $this->assertSame(0, bccomp($expected, (string) ($actual ?? '0'), 4), "{$message}: চাই {$expected}, এল ".var_export($actual, true));
    }

    private function cash(): int
    {
        return (int) Account::query()->money()->where('is_group', false)->firstOrFail()->id;
    }

    private function contract(string $who, ?Branch $branch, string $deposit, string $monthly, Carbon $starts, string $status = RentalContract::ACTIVE): RentalContract
    {
        return RentalContract::query()->create([
            'company_id' => CompanyContext::id(), 'branch_id' => $branch?->id, 'document_no' => 'RNT-'.random_int(1, 999999),
            'counterparty' => $who, 'subject' => $who.' place',
            'account_id' => StandardChart::find(StandardChart::SECURITY_DEPOSIT)->id,
            'expense_account_id' => StandardChart::find(StandardChart::RENT)->id,
            'deposit_amount' => $deposit, 'monthly_rent' => '10000', 'monthly_adjustment' => $monthly,
            'starts_on' => $starts->toDateString(), 'term_months' => 36,
            'ends_on' => $starts->copy()->addMonths(36)->subDay()->toDateString(), 'status' => $status,
        ]);
    }

    /** জামানত দেওয়া (খরচের ভাউচার) বা ফেরত (রসিদ), চুক্তির নামে বাঁধা, খাতায় বসানো — চাইলে পরে উল্টানো */
    private function deposit(RentalContract $contract, string $amount, Carbon $on, bool $refund = false, bool $reverse = false, ?string $against = null): void
    {
        $type = $refund ? Voucher::RECEIPT : Voucher::PAYMENT;
        $voucher = Voucher::query()->create([
            'company_id' => $this->company->id, 'branch_id' => $contract->branch_id,
            'financial_year_id' => FinancialYear::query()->value('id'), 'type' => $type,
            'document_no' => 'V-RNT-'.random_int(1, 999999), 'trx_date' => $on->toDateString(), 'amount' => $amount,
            'status' => $reverse ? DocumentStatus::CANCELLED : DocumentStatus::CONFIRMED,
            'against_type' => $against ?? RentalContract::drillSourceType(), 'against_id' => $contract->id,
        ]);

        $deposit = ['account_id' => $contract->account_id, $refund ? 'credit' : 'debit' => $amount];
        $money = ['account_id' => $this->cash(), $refund ? 'debit' : 'credit' => $amount];

        app(PostingEngine::class)->post(sourceType: Voucher::SOURCE_TYPES[$type], sourceId: (int) $voucher->id, trxDate: $on->toDateString(),
            lines: [$deposit, $money], branchId: $contract->branch_id === null ? null : (int) $contract->branch_id);

        if ($reverse) {
            app(PostingEngine::class)->reverse(Voucher::SOURCE_TYPES[$type], (int) $voucher->id, $on->toDateString(), 'test');
        }
    }

    private function adjust(RentalContract $contract, Carbon $month, string $fromDeposit, string $voucherStatus, bool $deleted = false): void
    {
        $voucher = Voucher::query()->create([
            'company_id' => $this->company->id, 'branch_id' => $contract->branch_id,
            'financial_year_id' => FinancialYear::query()->value('id'), 'type' => Voucher::PAYMENT,
            'document_no' => 'PV-RNT-'.random_int(1, 999999), 'trx_date' => $month->toDateString(), 'amount' => '10000', 'status' => $voucherStatus,
        ]);

        $row = RentalAdjustment::query()->create([
            'company_id' => $this->company->id, 'branch_id' => $contract->branch_id, 'rental_contract_id' => $contract->id,
            'for_month' => $month->toDateString(), 'rent' => '10000', 'paid_cash' => bcsub('10000', $fromDeposit, 4), 'from_deposit' => $fromDeposit,
            'voucher_id' => $voucher->id,
        ]);

        if ($deleted) {
            $row->delete();
        }
    }
}
