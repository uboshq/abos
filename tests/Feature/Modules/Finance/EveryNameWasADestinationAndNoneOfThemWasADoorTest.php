<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Finance\Models\BankFacility;
use App\Modules\Finance\Models\Budget;
use App\Modules\Finance\Models\Deposit;
use App\Modules\Finance\Models\DepositKind;
use App\Modules\Finance\Models\Institution;
use App\Modules\Finance\Models\Withdrawal;
use App\Modules\Finance\Services\DepositKindInstaller;
use App\Modules\Finance\Services\DepositService;
use App\Modules\Finance\Services\InstitutionService;
use App\Modules\Finance\Services\WithdrawalService;
use App\Modules\MasterData\Models\Person;
use App\Modules\MasterData\Services\PersonResolver;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * প্রতিটা নামের একটা গন্তব্য ছিল, অথচ একটাও দরজা ছিল না।
 *
 * ── ⓘ মালিকের কথা, ২০ সেপ্টেম্বর ২০২৬ ────────────────────────────────
 * *"sob jaygay hyper link dewar kotha but notun kaje kotaw hyperlink
 * dicche na"* — নিরীক্ষার চ১ ঐ কথাটারই তালিকা: আঠারোটা ঘর, যেখানে
 * একটা নাম লেখা আছে আর সেই নামের নিজের পাতাও আছে, কিন্তু দুইটার মাঝে
 * কোনো পথ নেই।
 *
 * ── ⚠️ কেন পরীক্ষাটা রুট ধরে দেখে, লেখা ধরে নয় ──────────────────────
 * `assertSee($url)` লিংকটা **সত্যিই** ঐ পাতায় নামে কি না দেখে। ⛔ লেখা
 * ("দোকান ভাড়া") খুঁজলে পরীক্ষা সবুজ থাকত নামটা নিছক লেখা হলেও — আর
 * সেটাই তো সারানো হচ্ছে।
 *
 * ⓘ [[TheNumbersWereDeadTextTest]] সংখ্যাগুলো দেখে; এটা নামগুলো।
 */
final class EveryNameWasADestinationAndNoneOfThemWasADoorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();
        app(DepositKindInstaller::class)->install();
    }

    /**
     * ⭐ বাজেটের খাতের নাম হিসাবের তালিকায় খাতটাই খোলে।
     */
    public function test_a_budget_head_opens_the_account_itself(): void
    {
        $account = $this->anExpenseAccount();

        Budget::query()->create([
            'company_id' => CompanyContext::id(),
            'year' => (int) now()->year,
            'month' => (int) now()->month,
            'account_id' => $account->id,
            'amount' => '5000.0000',
            'created_by' => auth()->id(),
        ]);

        $where = route('accounts.coa.show', $account->id);

        $this->get(route('finance.budget.index', ['year' => now()->year]))
            ->assertOk()->assertSee($where, escape: false);

        $this->get(route('finance.budget.actual', ['year' => now()->year]))
            ->assertOk()->assertSee($where, escape: false);
    }

    /**
     * ⭐ খরচ আর আয়ের তালিকায় **নামটাও** খাতটা খোলে, কেবল অঙ্কটা নয়।
     */
    public function test_the_expense_and_income_heads_are_doors_too(): void
    {
        /*
         * ⓘ তালিকায় খাতটা আসে কেবল তাতে কিছু বসলে ([[HeadTotals::forParent()]]) —
         * খালি খাতে পর্দা ভরলে যেটা সত্যি বেড়েছে সেটাই হারাত। ⚠️ তাই একটা
         * সত্যিকারের খরচ আর একটা আয় বসানো হয় — নাহলে পরীক্ষাটা
         * খালি পাতা দেখে সবুজ হত, আর কিছুই পাহারা দিত না।
         */
        $this->anExpenseAndAnIncome();

        foreach (['finance.expense.index', 'finance.income.index'] as $page) {
            $html = $this->get(route($page))->assertOk()->getContent();

            /*
             * ⚠️ অঙ্কের ঘরটা আগে থেকেই খাতে নামত — কিন্তু `#transactions`-এ,
             * খাতের লেনদেনের অংশে। নামের লিংকটা নামে খাতের **মাথায়**, তাই
             * ঠিকানার শেষে কোনো `#` থাকে না।
             *
             * ⛔ এই তফাতটা না দেখলে পরীক্ষাটা পুরনো পাতাতেও সবুজ থাকত —
             * আর তখন সে কিছুই পাহারা দিত না।
             */
            $this->assertMatchesRegularExpression(
                '~href="[^"#]*/chart-of-accounts/\d+"~',
                (string) $html,
                'খাতের নামটা এখনো নিছক লেখা — কেবল অঙ্কটাই দরজা।',
            );
        }
    }

    /**
     * ⭐ জমার ধরনের কোড আর নাম দুইটাই ধরনটার পাতা খোলে।
     */
    public function test_a_deposit_kind_opens_from_its_code_and_its_name(): void
    {
        $kind = DepositKind::query()->firstOrFail();

        $this->get(route('finance.deposit_kind.index'))
            ->assertOk()
            ->assertSee(route('finance.deposit_kind.edit', $kind->id), escape: false);
    }

    /**
     * ⭐ জমার সারিতে প্রতিষ্ঠান তার পাতায়, আর ধরন তার তালিকায় নামে।
     */
    public function test_a_deposit_row_opens_the_institution_and_the_kind(): void
    {
        $deposit = $this->openADeposit();

        foreach ([
            route('finance.deposit.index', ['issuer' => 'bank']),
            route('finance.deposit.all'),
        ] as $page) {
            $this->get($page)
                ->assertOk()
                ->assertSee(route('finance.institution.show', $deposit->institution_id), escape: false)
                ->assertSee(route('finance.deposit.all', ['kind' => $deposit->kind_id]), escape: false);
        }
    }

    /**
     * ⭐ ব্যাংকের সুবিধার তালিকায় প্রতিষ্ঠানের নিজের ঘর — আগে ছিলই না।
     */
    public function test_the_facility_list_names_the_institution(): void
    {
        $institution = $this->anInstitution();

        /*
         * ℹ সারিটা প্রতিষ্ঠানে বাঁধা আছে কি না, ডেমোর উপর ছেড়ে দেওয়া হয় না
         */
        BankFacility::query()->create([
            'company_id' => CompanyContext::id(),
            'branch_id' => CompanyContext::branchId(),
            'document_no' => 'BF-LINK-1',
            'kind' => BankFacility::CC,
            'institution_id' => $institution->id,
            'bank' => $institution->name(),
            'limit_amount' => '500000',
            'interest_rate' => '9',
            // ⚠️ মঞ্জুরির তারিখ বাধ্যতামূলক — সুবিধাটা কবে দেওয়া হলো, সেটা ছাড়া সারিটা অসম্পূর্ণ
            'sanctioned_on' => now()->subMonths(2)->toDateString(),
            'status' => DocumentStatus::CONFIRMED,
        ]);

        $this->get(route('finance.bank_facility.index'))
            ->assertOk()
            ->assertSee(route('finance.institution.show', $institution->id), escape: false);
    }

    /**
     * ⭐ উত্তোলনের তালিকায় মানুষের নাম তাঁর মূলধনের পাতায় নামে।
     *
     * ⓘ দুইটা ট্যাবেই — সারির তালিকায়, আর "কে কোথায় দাঁড়িয়ে"তেও।
     */
    public function test_a_withdrawal_names_a_person_who_can_be_opened(): void
    {
        $personId = $this->aWithdrawal()->person_id;

        $where = route('finance.capital.index', ['person' => $personId]);

        $this->get(route('finance.withdrawal.index'))
            ->assertOk()->assertSee($where, escape: false);

        $this->get(route('finance.withdrawal.index', ['tab' => 'standing']))
            ->assertOk()->assertSee($where, escape: false);
    }

    /**
     * একটা প্রতিষ্ঠান — ডেমোর খাতায় কোনোটা নেই।
     */
    private function anInstitution(): Institution
    {
        return Institution::query()->first() ?? app(InstitutionService::class)->create([
            'kind' => Institution::BANK,
            'name_en' => 'Sonali Bank',
            'name_bn' => 'সোনালী ব্যাংক',
        ]);
    }

    /**
     * একটা খরচ আর একটা আয় — দুই তালিকার একটা করে সারি।
     *
     * ⓘ একটাই জার্নাল: খরচ ডেবিট, আয় ক্রেডিট — আর তাতেই দুই পাতায়
     * একটা করে খাত বসে যায়।
     */
    private function anExpenseAndAnIncome(): void
    {
        $vouchers = app(VoucherService::class);

        $voucher = $vouchers->create([
            'type' => Voucher::JOURNAL,
            'trx_date' => now()->toDateString(),
            'narration' => 'link test',
        ], [
            ['account_id' => $this->aChildOf(StandardChart::OPERATING_EXPENSES)->id,
                'debit' => '500', 'credit' => '0'],
            ['account_id' => $this->aChildOf(StandardChart::INCOME)->id,
                'debit' => '0', 'credit' => '500'],
        ]);

        $vouchers->post($voucher);
    }

    private function aWithdrawal(): Withdrawal
    {
        return app(WithdrawalService::class)->request([
            'person_id' => $this->aPerson()->id,
            'amount' => '1200',
            'trx_date' => now()->toDateString(),
            'reason' => 'link test',
        ]);
    }

    /**
     * ⓘ পাতা দুইটা একটা বাবা-খাতের **সরাসরি সন্তানদেরই** দেখায়
     * ([[HeadTotals::forParent()]]) — নাতি-খাত তালিকায় আসে না।
     * ⚠️ যেকোনো খরচের খাত নিলে সেটা পাতায় না-ও থাকতে পারে, আর তখন
     * পরীক্ষাটা লাল হত এমন একটা কারণে যার সাথে লিংকের কোনো সম্পর্কই নেই।
     */
    /**
     * ⓘ নামটা [[PersonResolver]] ধরে বসে, সরাসরি `create()` দিয়ে নয় —
     * কোডটা সেই সেবাই কাটে, আর পর্দায় নাম যোগ করলেও এই পথই।
     */
    private function aPerson(): Person
    {
        $existing = Person::query()->first();

        if ($existing !== null) {
            return $existing;
        }

        $data = ['person_new' => 'লিংক পরীক্ষা'];

        return Person::query()->findOrFail(app(PersonResolver::class)->resolve($data));
    }

    private function aChildOf(string $parentCode): Account
    {
        $parent = Account::query()->where('code', $parentCode)->firstOrFail();

        return Account::query()->postable()
            ->where('parent_id', $parent->id)
            ->orderBy('code')
            ->firstOrFail();
    }

    private function anExpenseAccount(): Account
    {
        return $this->aChildOf(StandardChart::OPERATING_EXPENSES);
    }

    private function openADeposit(): Deposit
    {
        $kind = DepositKind::query()->where('issuer', 'bank')->firstOrFail();
        $institution = $this->anInstitution();

        return app(DepositService::class)->open([
            'kind_id' => $kind->id,
            'institution_id' => $institution->id,
            'institution' => $institution->name(),
            'held_by' => Deposit::BUSINESS,
            'principal' => '100000',
            'return_word' => 'interest',
            'opened_on' => now()->toDateString(),
            'matures_on' => now()->addDays(20)->toDateString(),
            'funded_from_account_id' => app(CashTillService::class)->ensurePrimaryTill()->account_id,
        ]);
    }
}
