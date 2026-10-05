<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\AccountsFacts;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Finance\Models\HandLoanAccount;
use App\Modules\Finance\Models\HandLoanMovement;
use App\Modules\Finance\Reports\LoanLedgerReports;
use App\Modules\Finance\Services\HandLoanService;
use App\Modules\MasterData\Models\Person;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * ⭐ হাতধারের ব্যক্তির তালিকা আর একজনের খাতা — মালিকের সরাসরি আদেশ, ৫ অক্টোবর ২০২৬ (সমন্বয়কের মারফত)।
 *
 * ⭐ দাবি:
 *   তালিকার "হাতধারে বাকি", হিসাবের বকেয়া ([[HandLoanService::balanceOf()]]) আর খাতার শেষ জের — তিনটাই এক সংখ্যা;
 *   সইয়ের অপেক্ষার ভাউচারের টাকা তিন জায়গাতেই বাদ (গোনার নিয়ম একটাই);
 *   তারিখ দিলে আগের সব খোলা জেরে, আর জের তবু শেষে একই;
 *   Sujon Sumon-এর ঘটনা: জাবেদায় প্রাপ্য খাতে তাঁর নামে ১০০ — "মোট পাওনা" (Dr) ১০০, [[AccountsFacts::dueFrom()]]-এর সমান,
 *   "হাতধারে বাকি" ০, আর পাশে "মেলে না";
 *   তালিকায় সব ব্যক্তি, পাতা খুললেই; নাম চাপলে খাতা; একই মানুষ চাবি ছাড়া খাতায় ৪০৩।
 */
final class TheHandLoanBookEndsWhereTheListSaysTest extends TestCase
{
    use PutsMoneyInTheTill;
    use RefreshDatabase;

    private Company $company;

    private int $till;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();
        $this->till = (int) app(CashTillService::class)->ensurePrimaryTill()->account_id;
        $this->putMoneyIn(Account::query()->findOrFail($this->till), '50000', now()->subDays(20)->toDateString());
    }

    public function test_the_list_the_account_and_the_book_end_on_one_number_and_a_pending_voucher_counts_nowhere(): void
    {
        $karim = $this->person('Karim Lender');
        $account = app(HandLoanService::class)->open(['person_id' => $karim->id]);
        $this->move($account, HandLoanMovement::OUT, '5000', now()->subDays(10));
        $this->move($account, HandLoanMovement::IN, '2000', now()->subDays(5));
        $pending = $this->move($account, HandLoanMovement::OUT, '700', now()->subDays(2));
        // ⓘ সইয়ের অপেক্ষা — ভাউচার খসড়ায় ফেরানো: টাকা এখনো নড়েনি
        Voucher::query()->whereKey($pending->voucher_id)->update(['status' => DocumentStatus::DRAFT]);

        $row = $this->rowOf($karim);
        $this->assertSame('3000.0000', $row['balance'], '⛔ সইয়ের অপেক্ষার ৭০০-ও তালিকায় গোনা হলো।');
        $this->assertSame(0, bccomp($row['balance'], app(HandLoanService::class)->balanceOf($account), 4), 'তালিকা আর হিসাব এক নয়।');

        $book = $this->book($karim, ReportEngine::ALL_TIME, now()->toDateString());
        $this->assertSame(0, bccomp($row['balance'], $book['closing'], 4), '⛔ খাতার শেষ জের আর তালিকার বাকি আলাদা।');
        $this->assertCount(2, $book['lines'], 'খাতায় দুইটা চলাচল — সইয়ের অপেক্ষারটা নয়।');

        // ⓘ তারিখ দিলে আগের সব খোলা জেরে — শেষ জের তবু একই
        $later = $this->book($karim, now()->subDays(6)->toDateString(), now()->toDateString());
        $this->assertSame(0, bccomp('5000', $later['opening'], 4), 'খোলা জেরে আগের ৫০০০ নেই।');
        $this->assertSame(0, bccomp($row['balance'], $later['closing'], 4), '⛔ তারিখ বদলালে শেষ জের বদলে গেল।');
    }

    public function test_sujon_sumons_hundred_on_the_receivable_shows_in_the_total_not_in_the_hand_loan(): void
    {
        $sujon = $this->person('Sujon Sumon');
        $receivable = Account::query()->where('code', '1110')->firstOrFail();
        $sales = Account::query()->where('code', '4100')->firstOrFail();

        $vouchers = app(VoucherService::class);
        $journal = $vouchers->create(['type' => Voucher::JOURNAL, 'trx_date' => now()->toDateString(), 'narration' => 'Rahim Store-এর ১০০ Sujon Sumon-এর নামে'], [
            ['account_id' => $receivable->id, 'debit' => '100', 'credit' => '0', 'party_type' => 'person', 'party_id' => $sujon->id],
            ['account_id' => $sales->id, 'debit' => '0', 'credit' => '100'],
        ]);
        $vouchers->post($journal);

        $row = $this->rowOf($sujon);
        $this->assertSame(0, bccomp('100', $row['books'], 4), '⛔ খতিয়ানে তাঁর নামের ১০০ "মোট পাওনা"-য় নেই — মালিকের অভিযোগটাই।');
        $this->assertSame(0, bccomp($row['books'], app(AccountsFacts::class)->dueFrom('person', (int) $sujon->id), 4),
            '"মোট পাওনা" আর AccountsFacts::dueFrom() দুই নিয়মে গোনা।');
        $this->assertSame(0, bccomp('0', $row['balance'], 4), 'হাতধারে তাঁর কোনো চলাচল নেই।');
        $this->assertTrue($row['differs']);

        $this->assertSame(0, bccomp('100', $row['elsewhere'], 4), '"অন্য খাতে" = মোট − হাতধার');

        $page = $this->get(route('finance.hand_loan.index'))->assertOk();
        // ⭐ "⚠ মেলে না" নয় — তথ্যের লেখা, মালিকের কথায় (৫ অক্টোবর ২০২৬)
        $page->assertSee('data-books-elsewhere', false)
            ->assertSee(__('finance::loan_ledger.elsewhere', ['amount' => '(Dr) 100.00']))
            ->assertDontSee('মেলে না');
    }

    public function test_an_address_saved_on_the_person_form_or_the_quick_add_shows_in_the_list(): void
    {
        // ⓘ মাস্টার ডেটার ব্যক্তির ফর্ম — ঘরটা আঁকা আছে, আর সংরক্ষণে বসে
        $this->get(route('master_data.person.create'))->assertOk()->assertSee('name="address"', false);
        $this->post(route('master_data.person.store'), ['name_en' => 'Form Person', 'mobile' => '01700000009', 'address' => 'কেন্দুয়া বাজার'])
            ->assertSessionHasNoErrors();
        $this->assertSame('কেন্দুয়া বাজার', Person::query()->where('name_en', 'Form Person')->value('address'));

        // ⓘ হাতধারের পাতার দ্রুত-যোগ — নাম, মোবাইল আর ঠিকানা
        $this->get(route('finance.hand_loan.index'))->assertOk()->assertSee('name="address"', false);
        $this->post(route('finance.hand_loan.person.store'), ['name_bn' => 'Quick Person', 'mobile' => '01700000010', 'address' => 'মোহনগঞ্জ'])
            ->assertSessionHasNoErrors();
        $this->assertSame('মোহনগঞ্জ', Person::query()->where('name_en', 'Quick Person')->value('address'));

        $this->get(route('finance.hand_loan.index'))->assertOk()->assertSee('কেন্দুয়া বাজার')->assertSee('মোহনগঞ্জ');
    }

    public function test_every_person_is_listed_on_opening_the_page_and_a_name_opens_the_book_behind_the_key(): void
    {
        $nobody = $this->person('No Loan Person');
        $page = $this->get(route('finance.hand_loan.index'))->assertOk();
        $page->assertSee('No Loan Person')
            ->assertSee(route('finance.report.show', ['slug' => 'hand-loan-book', 'person_id' => $nobody->id, 'from' => ReportEngine::ALL_TIME]));
        $this->assertSame(Person::query()->active()->count(), count($page->viewData('people')['rows']),
            'তালিকায় সব ব্যক্তি নেই — কেবল যাঁদের হাতধার আছে তাঁরা।');

        $this->get(route('finance.report.show', ['slug' => 'hand-loan-book', 'person_id' => $nobody->id]))->assertOk()
            ->assertSee(__('finance::loan_ledger.hand_loan_title'))
            ->assertSee('data-book-new', false);

        $clerk = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id]);
        $clerk->companies()->attach($this->company->id, ['is_active' => true]);
        $this->actingAs($clerk->fresh())->get(route('finance.report.show', ['slug' => 'hand-loan-book', 'person_id' => $nobody->id]))->assertForbidden();
        CompanyContext::forCompany($this->company->id, fn () => $clerk->givePermissionTo(Permission::findOrCreate('finance.hand_loan.view', 'web')));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($clerk->fresh())->get(route('finance.report.show', ['slug' => 'hand-loan-book', 'person_id' => $nobody->id]))->assertOk();
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function rowOf(Person $person): array
    {
        $row = collect(app(HandLoanService::class)->people()['rows'])->first(fn (array $r) => (int) $r['person']->id === (int) $person->id);
        $this->assertNotNull($row, 'মানুষটা তালিকায় নেই।');

        return $row;
    }

    /** @return array{opening: string, closing: string, lines: list<object>} */
    private function book(Person $person, string $from, string $to): array
    {
        $result = app(ReportEngine::class)->run(LoanLedgerReports::HAND_LOAN, ['person_id' => $person->id, 'from' => $from, 'to' => $to], perPage: 500);
        $rows = collect($result->rows);
        $opening = $rows->first(fn ($r) => (int) ((array) $r)['sort'] === 0);
        $closing = '0';
        foreach ($rows as $r) {
            $r = (array) $r;
            $closing = bcadd($closing, bcsub((string) $r['debit'], (string) $r['credit'], 4), 4);
        }

        return [
            'opening' => $opening === null ? '0' : bcsub((string) ((array) $opening)['debit'], (string) ((array) $opening)['credit'], 4),
            'closing' => $closing,
            'lines' => $rows->filter(fn ($r) => (int) ((array) $r)['sort'] === 1)->values()->all(),
        ];
    }

    private function move(HandLoanAccount $account, string $direction, string $amount, Carbon $on): HandLoanMovement
    {
        return app(HandLoanService::class)->move($account, [
            'direction' => $direction, 'amount' => $amount, 'money_account_id' => $this->till, 'moved_on' => $on->toDateString(),
        ]);
    }

    private function person(string $name): Person
    {
        return Person::query()->create([
            'company_id' => CompanyContext::id(), 'code' => 'P-'.substr(md5($name), 0, 6), 'name_en' => $name, 'is_active' => true,
        ]);
    }
}
