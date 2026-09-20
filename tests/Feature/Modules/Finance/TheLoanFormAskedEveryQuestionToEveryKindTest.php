<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\BankFacility;
use App\Modules\Finance\Models\Institution;
use App\Modules\Finance\Services\BankFacilityService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ফর্মটা প্রতিটা ধরনকে সব প্রশ্নই করত।
 *
 * ── ⓘ মালিকের কথা, ২০ সেপ্টেম্বর ২০২৬ ────────────────────────────────
 * *"Tarm loan e নবায়নের তারিখ thake na tahole tumi dila keno"* — মেয়াদি
 * ঋণ নবায়ন হয় না, সে শেষ হয়। ⛔ আর যে ঘর দেখানো হয় অথচ সংরক্ষণ হয় না,
 * সেটা না থাকার চেয়েও খারাপ: কেউ ভরেন আর ভাবেন লেখা হয়েছে।
 *
 * ── ⭐ আর সবচেয়ে জরুরি প্রশ্নটা ───────────────────────────────────────
 * *"এই ঋণ নতুন, নাকি আগে থেকেই চলছে?"* ⚠️ পুরনো ঋণের টাকা বছর আগেই
 * এসেছিল — আজ আবার বসালে **ব্যাংকের জেরটাই মিথ্যা হয়ে যেত**।
 */
final class TheLoanFormAskedEveryQuestionToEveryKindTest extends TestCase
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
    }

    /**
     * ⭐ ফর্মটা ধরন ধরে ঘর দেখায় — যুক্তিটা পর্দাতেই আছে।
     *
     * ⓘ কোন ঘর কখন, সেটা ঠিক করে `bankFacilityForm`, আর তার অঙ্কের
     * পরীক্ষা জাভাস্ক্রিপ্টে (`components.test.js`)। এখানে দেখা হয়
     * পর্দাটা ঐ কম্পোনেন্টই ডাকছে কি না, আর ঘরগুলো সত্যিই আছে কি না।
     */
    public function test_the_form_switches_its_fields_by_kind(): void
    {
        $page = $this->get(route('finance.bank_facility.create'))->assertOk();

        $page->assertSee('bankFacilityForm(', escape: false)
            ->assertSee('hasInstalments', escape: false)
            ->assertSee('hasRenewal', escape: false)
            ->assertSee('hasExpiry', escape: false)
            ->assertSee('hasInterest', escape: false);

        // ⭐ গ্যারান্টির তারিখটা নবায়ন নয়, মেয়াদ শেষ — নামটা পর্দায় আছে
        $page->assertSee(__('finance::field.expires_on'));
    }

    /**
     * ⭐ ব্যাংক বাছলে শাখা বসানোর জন্য নামগুলো পাতার সাথেই যায়।
     */
    public function test_the_branch_of_each_bank_reaches_the_page(): void
    {
        Institution::query()->create([
            'company_id' => CompanyContext::id(),
            'kind' => Institution::BANK,
            'name_en' => 'Islami Bank',
            'branch_name' => 'Gulshan',
            'is_active' => true,
        ]);

        $this->get(route('finance.bank_facility.create'))
            ->assertOk()
            ->assertSee('Gulshan');
    }

    /**
     * ⭐ আগে থেকেই চলা ঋণ — বকেয়া খাতায় ওঠে, ব্যাংক অক্ষত।
     *
     * ⚠️ এটাই এই দফার সবচেয়ে দামি দাবি: টাকাটা বছর আগেই এসেছিল, তাই
     * আজ কোনো টাকার খাত নড়ার কথা নয়।
     */
    public function test_an_old_loan_leaves_every_money_account_untouched(): void
    {
        $before = $this->moneyTotal();

        $facility = $this->openRunningLoan();

        $this->assertSame($before, $this->moneyTotal(),
            'পুরনো ঋণ তুলতে গিয়ে টাকার খাত নড়েছে — ব্যাংকের জেরটাই মিথ্যা হয়ে গেল।');

        // ⭐ দায়টা খাতায় উঠেছে, আর বিপরীতে সঞ্চিত মুনাফা
        $liability = (string) LedgerEntry::query()
            ->where('source_type', BankFacilityService::OPENING_SOURCE)
            ->where('source_id', $facility->id)
            ->where('account_id', $facility->liability_account_id)
            ->sum(DB::raw('credit - debit'));

        $this->assertSame(0, bccomp($liability, '600000', 4));

        $equity = StandardChart::find(StandardChart::RETAINED_EARNINGS);

        $this->assertSame(0, bccomp((string) LedgerEntry::query()
            ->where('source_type', BankFacilityService::OPENING_SOURCE)
            ->where('account_id', $equity->id)
            ->sum(DB::raw('debit - credit')), '600000', 4));
    }

    /**
     * ⛔ নতুন ঋণে কোনো খোলা দাখিলা বসে না — টাকা আসে রসিদ ভাউচারে।
     */
    public function test_a_new_loan_posts_no_opening_at_all(): void
    {
        $this->post(route('finance.bank_facility.store'), $this->terms())
            ->assertSessionHasNoErrors();

        $this->assertSame(0, LedgerEntry::query()
            ->where('source_type', BankFacilityService::OPENING_SOURCE)
            ->count(), 'নতুন ঋণেও খোলা ব্যালেন্স বসেছে — টাকাটা তাহলে দুইবার আসত।');
    }

    /**
     * ⭐ কিস্তি গোনা হয় খাতা থেকে — কোনো সংরক্ষিত গুনতি নয়।
     *
     * ⓘ শুরুর দিনের দুইটা কিস্তি যোগ হয়, আর তার পরে খাতায় যত শোধ।
     */
    public function test_instalments_are_counted_from_the_ledger(): void
    {
        $facility = $this->openRunningLoan();

        $standing = app(BankFacilityService::class)->instalmentStanding($facility);

        $this->assertSame(2, $standing['paid'], 'শুরুর দিনের গুনতিটাই আসেনি।');
        $this->assertSame(10, $standing['left']);

        // ⭐ একটা সত্যিকারের কিস্তি শোধ — দায় ডেবিট, নগদ ক্রেডিট
        $till = Account::query()->postable()->where('code', StandardChart::CASH_IN_HAND)->first()
            ?? Account::query()->postable()->whereIn('code', ['1102', '1103'])->firstOrFail();

        app(PostingEngine::class)->post(
            sourceType: 'test_repayment',
            sourceId: 1,
            trxDate: now()->toDateString(),
            lines: [
                /* ⓘ পুরো একটা কিস্তি — অর্ধেক শোধে কিস্তি গোনা বাড়ার
                   কথা নয়, আর সেটাই ঠিক। */
                ['account_id' => (int) $facility->liability_account_id, 'debit' => '110000'],
                ['account_id' => (int) $till->id, 'credit' => '110000'],
            ],
        );

        $after = app(BankFacilityService::class)->instalmentStanding($facility->fresh());

        $this->assertSame(3, $after['paid'], 'খাতার শোধটা গোনায় আসেনি।');
        $this->assertSame(9, $after['left']);
    }

    /** টাকার সব খাতের যোগফল — একটাও নড়লে এটা বদলায়। */
    private function moneyTotal(): string
    {
        return (string) LedgerEntry::query()
            ->join('accounts', 'accounts.id', '=', 'ledger_entries.account_id')
            ->whereIn('accounts.code', ['1101', '1102', '1103', '1104', '1105'])
            ->sum(DB::raw('ledger_entries.debit - ledger_entries.credit'));
    }

    /** আগে থেকেই চলা একটা মেয়াদি ঋণ — ছয় লাখ বকেয়া, দুইটা কিস্তি দেওয়া। */
    private function openRunningLoan(): BankFacility
    {
        $this->post(route('finance.bank_facility.store'), $this->terms([
            'already_running' => '1',
            'opening_drawn' => '600000',
            'instalments_paid' => 2,
        ]))->assertSessionHasNoErrors();

        return BankFacility::query()->latest('id')->firstOrFail();
    }

    /**
     * একটা মেয়াদি ঋণের ঘরগুলো।
     *
     * @param  array<string, mixed>  $with
     * @return array<string, mixed>
     */
    private function terms(array $with = []): array
    {
        $liability = Account::query()->postable()
            ->where('code', StandardChart::PAYABLE)->first()
            ?? Account::query()->postable()->where('nature', Account::CREDIT)->firstOrFail();

        return array_merge([
            'kind' => BankFacility::TERM,
            'institution_new' => 'Sonali Bank',
            'sanctioned_on' => now()->toDateString(),
            'limit_amount' => '1200000',
            'interest_rate' => '10',
            'instalments' => 12,
            'instalment_amount' => '110000',
            'liability_account_id' => $liability->id,
        ], $with);
    }
}
