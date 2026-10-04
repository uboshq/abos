<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Finance\Models\BankFacility;
use App\Modules\Finance\Services\BankFacilityService;
use App\Modules\Finance\Services\LoanSchedule;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * দুইটা মেয়াদি ঋণ, একটাই দায়ের খাত — আর প্রত্যেকটা দুইটার যোগফল দেখাত (অডিট গ১৬, ৪ অক্টোবর ২০২৬)।
 *
 * ── ⛔ কী ছিল ─────────────────────────────────────────────────────────
 * সব মেয়াদি ঋণ ২২১১-এ বসে, আর [[BankFacilityService::standing()]] খাতের পুরো জের প্রতিটা ঋণের নামে দেখাত:
 * দুইটা ২৫ লাখের ঋণ প্রত্যেকে "ব্যবহৃত ৫০ লাখ, বাকি ০"। কিস্তির গোনা ছিল "দায়ের ডেবিট − ক্রেডিট ÷ কিস্তি" —
 * টাকা তোলার ক্রেডিট ঢুকে ফল ঋণাত্মক, তাই বারো কিস্তির পরেও "০ দেওয়া", আর আগাম শোধের চার্জ ফুলত।
 *
 * ── ⭐ এখন ─────────────────────────────────────────────────────────────
 * ভাউচার ঋণের নামে বাঁধা (`against_type = bank_facility`, পাতার "কিস্তি দিন" বোতাম), আর খাত ভাগ হলে প্রতিটা ঋণ
 * কেবল নিজের সারি পড়ে; কিস্তি গোনা হয় শোধ হওয়া আসল সূচির সাথে মিলিয়ে।
 */
final class TwoTermLoansOnOneLiabilityAccountTest extends TestCase
{
    use RefreshDatabase;

    private const LIMIT = '2500000';

    private const RATE = '13.55';

    private const MONTHS = 36;

    private BankFacility $first;

    private BankFacility $second;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();

        $this->first = $this->loan('Sonali Bank');
        $this->second = $this->loan('Janata Bank');

        $this->draw($this->first);
        $this->draw($this->second);
    }

    public function test_each_loan_shows_only_its_own_drawing(): void
    {
        $standing = app(BankFacilityService::class)->standing(collect([$this->first, $this->second]));

        foreach ([$this->first, $this->second] as $facility) {
            $this->assertSame(0, bccomp($standing[$facility->id]['used'], self::LIMIT, 4),
                '⛔ '.$facility->bank.' দেখাচ্ছে ব্যবহৃত '.$standing[$facility->id]['used'].' — দুইটা ঋণের যোগফল।');
            $this->assertSame(0, bccomp($standing[$facility->id]['left'], '0', 4));
        }
    }

    public function test_instalments_are_counted_from_the_principal_of_the_repayments(): void
    {
        $rows = LoanSchedule::build(self::LIMIT, self::RATE, self::MONTHS)['rows'];

        foreach (array_slice($rows, 0, 12) as $row) {
            $this->repay($this->first, (string) $row['principal'], (string) $row['interest']);
        }

        $service = app(BankFacilityService::class);

        $this->assertSame(12, $service->instalmentStanding($this->first)['paid'],
            '⛔ বারোটা কিস্তি দেওয়া হলো, অথচ পর্দা বলছে '.$service->instalmentStanding($this->first)['paid'].'টা।');
        $this->assertSame(0, $service->instalmentStanding($this->second)['paid'],
            '⛔ দ্বিতীয় ঋণে একটাও কিস্তি দেওয়া হয়নি, অথচ প্রথমটার শোধ তার নামে গোনা হলো।');

        $standing = $service->standing(collect([$this->first, $this->second]));

        $this->assertSame(0, bccomp($standing[$this->second->id]['used'], self::LIMIT, 4),
            '⛔ প্রথম ঋণের শোধে দ্বিতীয়টার বকেয়া কমেছে।');
    }

    // ── সহায়ক ───────────────────────────────────────────────────────────

    private function loan(string $bank): BankFacility
    {
        return app(BankFacilityService::class)->open([
            'kind' => BankFacility::TERM,
            'bank' => $bank,
            'sanctioned_on' => now()->subDays(10)->toDateString(),
            'limit_amount' => self::LIMIT,
            'interest_rate' => self::RATE,
            'instalments' => self::MONTHS,
            'instalment_amount' => LoanSchedule::instalment(self::LIMIT, self::RATE, self::MONTHS),
            'liability_account_id' => $this->liability()->id,
        ]);
    }

    /** ঋণের টাকা এল — দায় বাড়ল, ঋণের নামে (পাতার "ঋণের টাকা এল" বোতামের জোড়া) */
    private function draw(BankFacility $facility): void
    {
        $this->journal($facility, [
            ['account_id' => $this->equity()->id, 'debit' => self::LIMIT, 'credit' => '0'],
            ['account_id' => $this->liability()->id, 'debit' => '0', 'credit' => self::LIMIT],
        ]);
    }

    /** একটা কিস্তি — আসল দায় কমায়, সুদ খরচ (পাতার "কিস্তি দিন" বোতামের জোড়া) */
    private function repay(BankFacility $facility, string $principal, string $interest): void
    {
        $this->journal($facility, [
            ['account_id' => $this->liability()->id, 'debit' => $principal, 'credit' => '0'],
            ['account_id' => StandardChart::find(StandardChart::INTEREST_EXPENSE)->id, 'debit' => $interest, 'credit' => '0'],
            ['account_id' => $this->equity()->id, 'debit' => '0', 'credit' => bcadd($principal, $interest, 2)],
        ]);
    }

    /** @param  list<array<string, mixed>>  $lines */
    private function journal(BankFacility $facility, array $lines): void
    {
        $vouchers = app(VoucherService::class);

        $vouchers->post($vouchers->create([
            'type' => Voucher::JOURNAL,
            'trx_date' => now()->subDays(5)->toDateString(),
            'narration' => 'loan test',
            'against_type' => BankFacility::drillSourceType(),
            'against_id' => $facility->id,
        ], $lines));
    }

    private function liability(): Account
    {
        return Account::query()->where('code', '2211')->firstOrFail();
    }

    private function equity(): Account
    {
        return StandardChart::find(StandardChart::OWNER_CAPITAL);
    }
}
