<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Finance\Models\BankFacility;
use App\Modules\Finance\Reports\LoanLedgerReports;
use App\Modules\Finance\Services\BankFacilityService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ ব্যাংক ঋণের খাতা — মালিকের সরাসরি আদেশ, ৫ অক্টোবর ২০২৬ (সমন্বয়কের মারফত): হাতধারের একই ধাঁচ।
 *
 * ⭐ দাবি:
 *   তালিকার "বাকি আসল" ([[BankFacilityService::standing()]]) আর খাতার শেষ জের এক সংখ্যা — (Cr) মানে আমরা ধারি;
 *   শোধ খাতায় ডেবিট, জের কমে; তারিখ দিলে আগের সব খোলা জেরে;
 *   খাতায় না-বসা পুরনো তোলা (`opening_drawn`) খোলা জেরে আসে — তালিকাও ওটা গোনে;
 *   এক দায়ের খাতে দুই ঋণ — প্রত্যেকের খাতায় কেবল নিজের সারি (অডিট গ১৬-এর একই নিয়ম);
 *   তালিকায় নাম চাপলে খাতা, আর "বাকি সুদ" কিস্তির সূচি থেকে; একই মানুষ চাবি ছাড়া খাতায় ৪০৩,
 *   আর হাতধারের চাবিতে ব্যাংক ঋণের খাতা খোলে না।
 */
final class TheBankLoanBookEndsWhereTheListSaysTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private BankFacilityService $service;

    private int $bank;

    private int $liability;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();
        app(CashTillService::class)->ensurePrimaryTill();
        $this->service = app(BankFacilityService::class);

        $head = Account::query()->where('code', StandardChart::BANK)->firstOrFail();
        $this->bank = (int) Account::query()->create([
            'parent_id' => $head->id, 'code' => '110292', 'name_en' => 'Loan Book Test Bank', 'type' => $head->type,
            'nature' => $head->nature, 'is_group' => false, 'money_kind' => Account::BANK,
        ])->id;
        $this->liability = (int) Account::query()->postable()->where('type', Account::LIABILITY)->value('id');
    }

    public function test_the_list_and_the_book_end_on_one_number_and_a_repayment_lowers_it(): void
    {
        $loan = $this->loan('Islami Bank', 12);
        $this->journal($this->liability, $this->bank, '300000', now()->subDays(10)->toDateString(), 'LOAN-DRAW-11', $loan);   // ছাড়: Cr দায় / Dr ব্যাংক
        $this->journal($this->bank, $this->liability, '50000', now()->subDays(3)->toDateString(), 'LOAN-PAY-11', $loan);      // শোধ: Cr ব্যাংক / Dr দায়

        $used = $this->service->standing(collect([$loan]))[$loan->id]['used'];
        $this->assertSame(0, bccomp('250000', $used, 4), 'প্রস্তুতিটাই ভুল — ব্যবহৃত ২,৫০,০০০ নয়।');

        $book = $this->book($loan, ReportEngine::ALL_TIME, now()->toDateString());
        $this->assertSame(0, bccomp(bcmul($used, '-1', 4), $book['closing'], 4), '⛔ খাতার শেষ জের আর তালিকার বাকি আসল আলাদা।');

        $later = $this->book($loan, now()->subDays(5)->toDateString(), now()->toDateString());
        $this->assertSame(0, bccomp('-300000', $later['opening'], 4), 'খোলা জেরে আগের ছাড় নেই।');
        $this->assertSame(0, bccomp($book['closing'], $later['closing'], 4), '⛔ তারিখ বদলালে শেষ জের বদলে গেল।');
    }

    public function test_an_old_draw_never_booked_opens_the_book_and_two_loans_on_one_account_keep_their_own_rows(): void
    {
        // ⓘ পুরনো সারি — খোলা দাখিলা নেই, কেবল লেখা অঙ্ক (২০ সেপ্টেম্বরের আগের নিয়মে খোলা ঋণ)
        $old = $this->loan('Old Bank', 12);
        $old->forceFill(['opening_drawn' => '80000'])->save();
        $this->assertSame(0, bccomp('80000', $this->service->standing(collect([$old]))[$old->id]['used'], 4), 'প্রস্তুতিটাই ভুল।');
        $this->assertSame(0, bccomp('-80000', $this->book($old, ReportEngine::ALL_TIME, now()->toDateString())['closing'], 4),
            '⛔ খাতায় না-বসা পুরনো তোলা খাতার জেরে নেই, অথচ তালিকা ওটা গোনে।');

        $a = $this->loan('Bank A', 12);
        $b = $this->loan('Bank B', 12);
        $this->journal($this->liability, $this->bank, '10000', now()->toDateString(), 'LOAN-DRAW-A1', $a);
        $this->journal($this->liability, $this->bank, '20000', now()->toDateString(), 'LOAN-DRAW-B1', $b);

        foreach ([[$a, '-10000'], [$b, '-20000']] as [$loan, $want]) {
            $used = $this->service->standing(collect([$loan]))[$loan->id]['used'];
            $closing = $this->book($loan, ReportEngine::ALL_TIME, now()->toDateString())['closing'];
            $this->assertSame(0, bccomp($want, $closing, 4), '⛔ এক খাতে দুই ঋণ — অন্যের সারিও খাতায় এলো।');
            $this->assertSame(0, bccomp(bcmul($used, '-1', 4), $closing, 4));
        }
    }

    public function test_the_name_opens_the_book_with_interest_left_and_the_book_needs_its_own_key(): void
    {
        $loan = $this->loan('Islami Bank', 12);
        $page = $this->get(route('finance.bank_facility.index'))->assertOk();
        $page->assertSee('data-bank-loan-book', false)
            ->assertSee(route('finance.report.show', ['slug' => 'bank-loan-book', 'facility_id' => $loan->id, 'from' => ReportEngine::ALL_TIME]))
            ->assertSee(__('finance::loan_ledger.interest_left'));
        $this->assertNotNull($page->viewData('interest')[$loan->id], 'কিস্তির সূচি আছে, অথচ বাকি সুদ নেই।');

        $url = route('finance.report.show', ['slug' => 'bank-loan-book', 'facility_id' => $loan->id]);
        $this->get($url)->assertOk()->assertSee(__('finance::loan_ledger.bank_loan_title'));

        $clerk = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id]);
        $clerk->companies()->attach($this->company->id, ['is_active' => true]);
        $this->give($clerk, 'finance.hand_loan.view');
        $this->actingAs($clerk->fresh())->get($url)->assertForbidden();
        $this->give($clerk, 'finance.bank_facility.view');
        $this->actingAs($clerk->fresh())->get($url)->assertOk();
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    /** @param  array<string, mixed>  $extra */
    private function loan(string $bank, int $instalments, array $extra = []): BankFacility
    {
        return $this->service->open([
            'kind' => BankFacility::TERM, 'bank' => $bank, 'sanctioned_on' => now()->subMonth()->toDateString(),
            'limit_amount' => '500000.0000', 'liability_account_id' => $this->liability, 'interest_rate' => '12',
            ...($instalments > 0 ? ['instalments' => $instalments] : []),
            ...$extra,
        ]);
    }

    /** `$from` খাত থেকে (ক্রেডিট) `$to` খাতে (ডেবিট) — [[VoucherService::twoLineEntry()]]-এর অর্থে */
    private function journal(int $from, int $to, string $amount, string $on, string $ref, BankFacility $loan): void
    {
        $vouchers = app(VoucherService::class);
        $voucher = $vouchers->create(
            ['type' => 'journal', 'trx_date' => $on, 'narration' => $ref, 'instrument_no' => $ref,
                'against_type' => BankFacility::drillSourceType(), 'against_id' => $loan->id],
            $vouchers->twoLineEntry('journal', $from, $to, $amount.'.00', $ref),
        );
        $vouchers->post($voucher);
    }

    /** @return array{opening: string, closing: string} */
    private function book(BankFacility $loan, string $from, string $to): array
    {
        $result = app(ReportEngine::class)->run(LoanLedgerReports::BANK_LOAN, ['facility_id' => $loan->id, 'from' => $from, 'to' => $to], perPage: 500);
        $opening = '0';
        $closing = '0';
        foreach ($result->rows as $r) {
            $r = (array) $r;
            $net = bcsub((string) $r['debit'], (string) $r['credit'], 4);
            $closing = bcadd($closing, $net, 4);
            if ((int) $r['sort'] === 0) {
                $opening = $net;
            }
        }

        return ['opening' => $opening, 'closing' => $closing];
    }

    private function give(User $user, string $key): void
    {
        CompanyContext::forCompany($this->company->id, fn () => $user->givePermissionTo(Permission::findOrCreate($key, 'web')));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
