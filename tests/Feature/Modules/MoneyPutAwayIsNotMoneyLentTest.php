<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\Deposit;
use App\Modules\Finance\Models\DepositKind;
use App\Modules\Finance\Models\DepositMovement;
use App\Modules\Finance\Services\DepositKindInstaller;
use App\Modules\Finance\Services\DepositService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * সরিয়ে রাখা টাকা — FD, DPS, সঞ্চয়পত্র, বন্ড।
 *
 * ── যে প্রশ্নটার উত্তর এই ফাইলটা পাহারা দেয় ──────────────────────────
 * মালিকের প্রশ্ন ছিল: সঞ্চয়পত্র কি কোম্পানির সম্পদ, নাকি মালিকের
 * উত্তোলন হয়ে বেরিয়ে যাবে?
 *
 * উত্তর **দুইটাই** — আর সারির `held_by` ঘরটাই বলে দেয় কোনটা:
 *
 *   `business` → Dr ১১৬০ জমা (সম্পদ)  · Cr নগদ
 *   `owner`    → Dr ৩২০০ উত্তোলন      · Cr নগদ
 *
 * ── কেন এটার টেস্ট থাকতেই হবে ────────────────────────────────────────
 * ভুলটা নীরব। মালিকের সঞ্চয়পত্র সম্পদ হিসেবে বসলে স্থিতিপত্র ঠিক ওই
 * পরিমাণ বেশি দেখায় — আর সেই সংখ্যাটা দিয়েই ব্যাংকে ঋণের আবেদন হয়।
 * ধরা পড়ে অডিটে, এক বছর পরে।
 */
class MoneyPutAwayIsNotMoneyLentTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();
        app(DepositKindInstaller::class)->install();
    }

    private function service(): DepositService
    {
        return app(DepositService::class);
    }

    private function kind(string $code): DepositKind
    {
        return DepositKind::query()->where('code', $code)->firstOrFail();
    }

    private function cash(): Account
    {
        return Account::query()->postable()->where('name_en', 'like', '%Cash%')->firstOrFail();
    }

    private function chartHead(string $code): Account
    {
        return Account::query()->where('code', $code)->firstOrFail();
    }

    /**
     * একটা খাতে কত ডেবিট হয়েছে, কত ক্রেডিট — খাতা থেকেই, সারি থেকে নয়।
     *
     * ── কেন খতিয়ান পড়া হয়, সেবার ফেরত নয় ───────────────────────────
     * সেবা যা বলে সেটা পরীক্ষা করলে সেবার ভুলটাই সত্যি হয়ে যেত। যে
     * সংখ্যাটা স্থিতিপত্রে ওঠে সেটা খতিয়ানের, তাই যাচাইটাও ওখানেই।
     *
     * @return array{debit: string, credit: string}
     */
    private function ledger(string $code): array
    {
        $account = $this->chartHead($code);

        $row = LedgerEntry::query()
            ->where('company_id', $this->company->id)
            ->where('account_id', $account->id)
            ->selectRaw('COALESCE(SUM(debit), 0) as d, COALESCE(SUM(credit), 0) as c')
            ->first();

        return ['debit' => (string) $row->d, 'credit' => (string) $row->c];
    }

    /** @param  array<string, mixed>  $extra */
    private function open(string $kindCode, string $heldBy, string $amount, array $extra = []): Deposit
    {
        return $this->service()->open(array_merge([
            'kind_id' => $this->kind($kindCode)->id,
            'institution' => 'সোনালী ব্যাংক',
            'held_by' => $heldBy,
            'principal' => $amount,
            'return_word' => 'interest',
            'opened_on' => now()->toDateString(),
            'funded_from_account_id' => $this->cash()->id,
        ], $extra));
    }

    /**
     * ব্যবসার নামের FD স্থিতিপত্রে সম্পদ হয়ে বসে।
     */
    public function test_a_deposit_in_the_business_name_is_an_asset(): void
    {
        $this->open('FDR', Deposit::BUSINESS, '500000');

        $this->assertSame(0, bccomp($this->ledger(StandardChart::DEPOSITS_AND_INVESTMENTS)['debit'],
            '500000', 4), 'ব্যবসার FD সম্পদ খাতে বসেনি।');

        $this->assertSame(0, bccomp($this->ledger(StandardChart::DRAWINGS)['debit'], '0', 4),
            'ব্যবসার FD ভুল করে উত্তোলনে গেছে।');
    }

    /**
     * মালিকের নামের সঞ্চয়পত্র উত্তোলন — ব্যবসার সম্পদ নয়।
     *
     * এই একটা টেস্টই মালিকের প্রশ্নটার উত্তর ধরে রাখে।
     */
    public function test_a_certificate_in_the_owner_name_is_a_drawing(): void
    {
        $this->open('PSP', Deposit::OWNER, '4500000', ['institution' => 'জাতীয় সঞ্চয় ব্যুরো',
            'payout_account_id' => $this->cash()->id]);

        $this->assertSame(0, bccomp($this->ledger(StandardChart::DRAWINGS)['debit'], '4500000', 4),
            'মালিকের সঞ্চয়পত্রের টাকাটা উত্তোলনে বসেনি।');

        $this->assertSame(0, bccomp($this->ledger(StandardChart::DEPOSITS_AND_INVESTMENTS)['debit'],
            '0', 4), 'মালিকের সঞ্চয়পত্র ব্যবসার সম্পদ হয়ে বসেছে — স্থিতিপত্র মিথ্যা বলবে।');
    }

    /**
     * সঞ্চয়পত্র ব্যবসার নামে কেনাই যায় না — সেবাই আটকায়।
     *
     * পর্দার সতর্কবার্তা যথেষ্ট নয়: ভুলটা ধরা পড়ে এক বছর পরে অডিটে,
     * ততদিনে ওই সংখ্যাটা দিয়ে ঋণের আবেদন হয়ে গেছে।
     */
    public function test_a_firm_cannot_buy_a_savings_certificate(): void
    {
        $this->expectException(ValidationException::class);

        $this->open('PSP', Deposit::BUSINESS, '100000', ['payout_account_id' => $this->cash()->id]);
    }

    /**
     * কিস্তি মূলধন বাড়ায়, মুনাফা বাড়ায় না।
     *
     * ── কেন দুইটা আলাদা করে দেখা ────────────────────────────────────
     * চিহ্ন দিয়ে চালালে "এ পর্যন্ত কত জমেছে" প্রশ্নের উত্তরে মুনাফাও
     * যোগ হত, আর মেয়াদান্তে ব্যাংকের কাগজের সাথে মিলত না।
     */
    public function test_an_instalment_grows_the_principal_but_a_payout_does_not(): void
    {
        $dps = $this->open('DPS', Deposit::BUSINESS, '5000', ['instalment_amount' => '5000']);

        $this->service()->instalment($dps, [
            'amount' => '5000',
            'moved_on' => now()->toDateString(),
            'money_account_id' => $this->cash()->id,
        ]);

        $this->assertSame(0, bccomp((string) $dps->fresh()->principal, '10000', 4),
            'কিস্তির পরে মূলধন বাড়েনি।');

        $mis = $this->open('MIS', Deposit::BUSINESS, '1000000',
            ['payout_account_id' => $this->cash()->id]);

        $this->service()->payout($mis, [
            'amount' => '8000',
            'moved_on' => now()->toDateString(),
            'money_account_id' => $this->cash()->id,
        ]);

        $this->assertSame(0, bccomp((string) $mis->fresh()->principal, '1000000', 4),
            'মুনাফা তোলায় মূলধন বেড়ে গেছে।');

        $this->assertSame(0, bccomp($this->ledger(StandardChart::INTEREST_INCOME)['credit'], '8000', 4),
            'মুনাফাটা আয়ের খাতে বসেনি।');
    }

    /**
     * মালিকের কাগজের মুনাফা আয় নয় — তাঁর উত্তোলন কমায়।
     *
     * আয় হিসেবে লিখলে ব্যবসার মুনাফা ফুলে যেত এমন টাকায় যা ব্যবসা
     * কখনো উপার্জন করেনি — আর ওই মুনাফার উপর কর বসত।
     */
    public function test_the_owners_profit_is_not_the_business_income(): void
    {
        $psp = $this->open('PSP', Deposit::OWNER, '1000000',
            ['payout_account_id' => $this->cash()->id]);

        $before = $this->ledger(StandardChart::DRAWINGS);

        $this->service()->payout($psp, [
            'amount' => '9000',
            'moved_on' => now()->toDateString(),
            'money_account_id' => $this->cash()->id,
        ]);

        $this->assertSame(0, bccomp($this->ledger(StandardChart::INTEREST_INCOME)['credit'], '0', 4),
            'মালিকের সঞ্চয়পত্রের মুনাফা ব্যবসার আয় হয়ে বসেছে।');

        $this->assertSame(0, bccomp(
            bcsub($this->ledger(StandardChart::DRAWINGS)['credit'], $before['credit'], 4),
            '9000', 4), 'মুনাফাটা মালিকের উত্তোলন কমায়নি।');
    }

    /**
     * মেয়াদান্তে বাড়তিটা মুনাফা, আর মূলধনটা যতটা বসেছিল ততটাই ফেরে।
     *
     * ব্যাংক যা দেয় তা সূত্রের সাথে মেলে না — উৎসে কর, আবগারি শুল্ক।
     * তাই প্রাপ্ত টাকাটা জিজ্ঞেস করা হয়, হিসাব করা হয় না।
     */
    public function test_what_the_bank_actually_paid_decides_the_profit(): void
    {
        $fd = $this->open('FDR', Deposit::BUSINESS, '100000');

        /*
         * মেয়াদপূর্তি নয়, তবু বাড়তি — কারণ তারিখটা এখানে বিষয় নয়।
         *
         * প্রথমে এক বছর পরে বসানো হয়েছিল, আর টেস্ট ভাঙল: ওই তারিখটা
         * বসানো অর্থবছরের বাইরে, আর পিছনের তালা সেটাই বলল। ঠিকই
         * বলেছে — যা যাচাই করা হচ্ছে তা হলো ব্যাংক যা দিল সেটাই
         * মুনাফা ঠিক করে, কবে দিল সেটা নয়।
         */
        $this->service()->close($fd, [
            'amount' => '108500',
            'moved_on' => now()->addMonths(3)->toDateString(),
            'money_account_id' => $this->cash()->id,
        ]);

        $asset = $this->ledger(StandardChart::DEPOSITS_AND_INVESTMENTS);

        $this->assertSame(0, bccomp($asset['credit'], '100000', 4),
            'সম্পদ খাত থেকে ঠিক মূলধনটাই বেরোয়নি।');

        $this->assertSame(0, bccomp($this->ledger(StandardChart::INTEREST_INCOME)['credit'], '8500', 4),
            'বাড়তি টাকাটা মুনাফা হিসেবে বসেনি।');

        $this->assertSame(Deposit::CLOSED, $fd->fresh()->status);
    }

    /**
     * আগে ভাঙলে যা কম আসে সেটা খরচ — চুপচাপ হারিয়ে যায় না।
     */
    public function test_breaking_early_leaves_the_shortfall_as_a_cost(): void
    {
        $fd = $this->open('FDR', Deposit::BUSINESS, '100000');

        $this->service()->close($fd, [
            'amount' => '97000',
            'moved_on' => now()->addMonths(3)->toDateString(),
            'money_account_id' => $this->cash()->id,
        ]);

        $this->assertSame(0, bccomp($this->ledger(StandardChart::INTEREST_EXPENSE)['debit'], '3000', 4),
            'আগে ভাঙার ঘাটতিটা কোথাও বসেনি — খাতা মিলত না।');
    }

    /**
     * চুকে যাওয়া জমায় আর কিছু করা যায় না।
     */
    public function test_a_closed_deposit_takes_no_more_movement(): void
    {
        $fd = $this->open('FDR', Deposit::BUSINESS, '50000');

        $this->service()->close($fd, [
            'amount' => '52000',
            'moved_on' => now()->toDateString(),
            'money_account_id' => $this->cash()->id,
        ]);

        $this->expectException(ValidationException::class);

        $this->service()->payout($fd->fresh(), [
            'amount' => '100',
            'moved_on' => now()->toDateString(),
            'money_account_id' => $this->cash()->id,
        ]);
    }

    /**
     * দুইটা যোগফল আলাদা থাকে — একটা স্থিতিপত্রে আছে, অন্যটা নেই।
     */
    public function test_the_two_totals_never_become_one(): void
    {
        $this->open('FDR', Deposit::BUSINESS, '300000');
        $this->open('BSP5', Deposit::OWNER, '3000000', ['institution' => 'ডাকঘর']);

        $standing = $this->service()->standing();

        $this->assertSame(0, bccomp($standing['business'], '300000', 4));
        $this->assertSame(0, bccomp($standing['owner'], '3000000', 4));
    }

    /**
     * প্রতিটা চলাচল তার ভাউচারে নামায় — নিয়ম ১।
     */
    public function test_every_movement_leads_to_its_voucher(): void
    {
        $fd = $this->open('FDR', Deposit::BUSINESS, '75000');

        $opened = $fd->movements()->where('kind', DepositMovement::OPENED)->first();

        $this->assertNotNull($opened, 'খোলার চলাচলটাই লেখা হয়নি।');
        $this->assertNotNull($opened->voucher_id, 'চলাচলটা কোনো ভাউচারে নামায় না।');
    }

    /**
     * ডাকঘরের কাগজও সঞ্চয়পত্রের তালিকাতেই — চতুর্থ কোনো মেনু নেই।
     *
     * ডাকঘর কাগজটা বিক্রি করে, ছাপে না। আলাদা ইস্যুয়ার বসালে মালিক
     * তাঁর কাগজটা "সঞ্চয়পত্র"-এর তালিকায় খুঁজতেন, পেতেন না।
     */
    public function test_a_post_office_paper_sits_with_the_savings_certificates(): void
    {
        $this->assertSame(DepositKind::NATIONAL_SAVINGS, $this->kind('POSB')->issuer);

        $expected = DepositKind::ISSUERS;
        sort($expected);

        $this->assertSame(
            $expected,
            DepositKind::query()->distinct()->pluck('issuer')->sort()->values()->all(),
            'বসানো ধরনগুলোতে এমন ইস্যুয়ার আছে যার কোনো মেনু সারি নেই।',
        );
    }

    /**
     * তিনটা পর্দাই খোলে, আর যার যার কাগজ দেখায়।
     *
     * ── কেন প্রতিটা আলাদা করে দেখা ──────────────────────────────────
     * ছাঁকনিটা ভুল দিকে বসানো সহজ, আর ফলটা নীরব: সঞ্চয়পত্রের পাতায়
     * FD-ও দেখাত, আর কেউ বুঝত না কোনটা কোথায়।
     */
    public function test_each_of_the_three_screens_shows_only_its_own_papers(): void
    {
        $this->open('FDR', Deposit::BUSINESS, '100000');
        $this->open('PSP', Deposit::OWNER, '900000', ['payout_account_id' => $this->cash()->id]);
        $this->open('PRIZE', Deposit::BUSINESS, '1000', ['institution' => 'বাংলাদেশ ব্যাংক']);

        foreach ([
            DepositKind::BANK => ['FDR', ['PSP', 'PRIZE']],
            DepositKind::NATIONAL_SAVINGS => ['PSP', ['FDR', 'PRIZE']],
            DepositKind::BOND => ['PRIZE', ['FDR', 'PSP']],
        ] as $issuer => [$belongs, $doesNot]) {
            $rows = $this->get(route('finance.deposit.index', ['issuer' => $issuer]))
                ->assertOk()->viewData('deposits');

            $codes = collect($rows->items())->map(fn ($d) => $d->kind->code)->all();

            $this->assertContains($belongs, $codes, "{$issuer}-এর পাতায় তার নিজের কাগজটাই নেই।");

            foreach ($doesNot as $stranger) {
                $this->assertNotContains($stranger, $codes,
                    "{$issuer}-এর পাতায় {$stranger} দেখাচ্ছে — ছাঁকনিটা কাজ করছে না।");
            }
        }
    }

    /**
     * জমার নিজের পাতা খোলে, আর ওখান থেকেই কিস্তি দেওয়া যায়।
     *
     * সেবা ঠিক থাকলেও পর্দা ভাঙা থাকতে পারে — ব্লেড ভাঙে চলার সময়,
     * আর সেটা কেবল সত্যিকারের একটা অনুরোধেই ধরা পড়ে।
     */
    public function test_the_record_page_opens_and_takes_an_instalment(): void
    {
        $dps = $this->open('DPS', Deposit::BUSINESS, '5000', ['instalment_amount' => '5000']);

        $where = ['issuer' => DepositKind::BANK, 'deposit' => $dps->id];

        $this->get(route('finance.deposit.show', $where))
            ->assertOk()
            ->assertSee($dps->document_no);

        $this->post(route('finance.deposit.movement', $where), [
            'kind' => DepositMovement::INSTALMENT,
            'amount' => '5000',
            'moved_on' => now()->toDateString(),
            'money_account_id' => $this->cash()->id,
        ])->assertRedirect();

        $this->assertSame(0, bccomp((string) $dps->fresh()->principal, '10000', 4),
            'পর্দা থেকে দেওয়া কিস্তিটা মূলধনে বসেনি।');
    }

    /**
     * খোলার পর ব্যবহারকারী তার নিজের পাতায় পৌঁছায়, তালিকায় নয়।
     */
    public function test_opening_one_lands_on_its_own_page(): void
    {
        $response = $this->post(route('finance.deposit.store', ['issuer' => DepositKind::BANK]), [
            'kind_id' => $this->kind('FDR')->id,
            'institution' => 'অগ্রণী ব্যাংক',
            'held_by' => Deposit::BUSINESS,
            'principal' => '250000',
            'return_word' => 'interest',
            'opened_on' => now()->toDateString(),
            'funded_from_account_id' => $this->cash()->id,
        ]);

        $made = Deposit::query()->latest('id')->firstOrFail();

        $response->assertRedirect(route('finance.deposit.show', [
            'issuer' => DepositKind::BANK,
            'deposit' => $made->id,
        ]));
    }
}
