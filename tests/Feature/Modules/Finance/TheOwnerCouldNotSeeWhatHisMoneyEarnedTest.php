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
use App\Modules\Finance\Models\CapitalEntry;
use App\Modules\Finance\Services\CapitalService;
use App\Modules\Finance\Services\InvestmentReturns;
use App\Modules\MasterData\Models\Person;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ কার টাকা কত আয় করল — অর্থের মানচিত্র §১২, ২০ সেপ্টেম্বর ২০২৬।
 *
 * ── ⛔ কী ছিল না ───────────────────────────────────────────────────────
 * মূলধনের পাতা বলত কে কত দিয়েছেন আর কার অংশ কত, কিন্তু *"আমার টাকা এই
 * বছর কত আনল"* — এই প্রশ্নের উত্তর কোথাও ছিল না। ⓘ অংশীদার তিনজন হলে
 * প্রশ্নটা আরও জরুরি: ৩০% অংশ নিয়ে ৪০% মূলধন দিলে রিটার্ন কম, আর সেটা
 * কেবল ভাগের টাকা দেখে বোঝা যায় না।
 *
 * ⚠️ এই পাতা কিছুই পোস্ট করে না, কেবল পড়ে — তাই পরীক্ষাও মাপে সংখ্যাটা
 * ঠিক কি না, খাতা নড়ল কি না নয়।
 */
final class TheOwnerCouldNotSeeWhatHisMoneyEarnedTest extends TestCase
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
    }

    /**
     * ⭐ অংশ অনুযায়ী ভাগ, আর যোগফল হুবহু লাভ।
     */
    public function test_the_profit_is_split_by_share_and_adds_up_to_the_whole(): void
    {
        $this->contribute('Rahim', '600000');
        $this->contribute('Karim', '400000');

        $this->earn('100000');
        $this->spend('40000');

        $report = $this->report();

        $this->assertSame(0, bccomp($report['profit'], '60000', 4), "লাভ {$report['profit']}, ৬০,০০০ হওয়ার কথা।");

        $rows = collect($report['rows'])->keyBy('name');

        $this->assertSame(0, bccomp($rows['Rahim']['earned'], '36000', 4), '৬০% অংশে ৩৬,০০০ পাওয়ার কথা।');
        $this->assertSame(0, bccomp($rows['Karim']['earned'], '24000', 4), '৪০% অংশে ২৪,০০০ পাওয়ার কথা।');

        $total = collect($report['rows'])->reduce(fn (string $s, array $r) => bcadd($s, $r['earned'], 4), '0');

        $this->assertSame(0, bccomp($total, $report['profit'], 4), "ভাগের যোগ {$total}, লাভ {$report['profit']} — মেলেনি।");
        $this->assertSame(0, bccomp($report['unallocated'], '0', 4), 'সবার অংশ লেখা থাকলে কিছু বাকি থাকার কথা নয়।');

        // রিটার্ন % = ভাগের টাকা ÷ নিজের বাকি মূলধন
        $this->assertSame(0, bccomp($rows['Rahim']['return_pct'], '6', 2), '৬,০০,০০০ টাকায় ৩৬,০০০ মানে ৬%।');
        $this->assertSame(0, bccomp($rows['Karim']['return_pct'], '6', 2), '৪,০০,০০০ টাকায় ২৪,০০০ মানেও ৬%।');
    }

    /**
     * ⭐ তিনজনে সমান ভাগেও এক পয়সা হারায় না — পর্দায় যা যোগ হয়, তাই লাভ।
     */
    public function test_three_equal_partners_lose_nothing_to_rounding(): void
    {
        $this->contribute('Rahim', '100000');
        $this->contribute('Karim', '100000');
        $this->contribute('Salam', '100000');

        $this->earn('100');

        $report = $this->report();
        $total = collect($report['rows'])->reduce(fn (string $s, array $r) => bcadd($s, $r['earned'], 4), '0');

        $this->assertSame(
            0,
            bccomp(bcadd($total, $report['unallocated'], 4), $report['profit'], 4),
            "ভাগ {$total} + কারও নয় {$report['unallocated']} ≠ লাভ {$report['profit']} — একটা পয়সা হারিয়েছে।",
        );
    }

    /**
     * ⛔ লোকসানে কারও ভাগ নেই, আর পর্দা সেটা কথায় বলে।
     */
    public function test_a_loss_gives_nobody_a_share(): void
    {
        $this->contribute('Rahim', '500000');

        $this->earn('10000');
        $this->spend('30000');

        $report = $this->report();

        $this->assertSame(-1, bccomp($report['profit'], '0', 4), 'খরচ বেশি হলেও লাভ ঋণাত্মক দেখায়নি।');
        $this->assertSame(0, bccomp($report['rows'][0]['earned'], '0', 4), 'লোকসানেও কাউকে ভাগ দেওয়া হয়েছে।');

        $html = $this->page();

        $this->assertStringContainsString(__('finance::investment.in_loss'), $html, 'লোকসানের কথাটা পর্দায় নেই।');
    }

    /**
     * ⭐ মূলধনের পাতা আর এই পাতা একই সংখ্যা বলে — এক পয়সাও তফাত নয়।
     *
     * ⚠️ দুই পাতায় দুই অঙ্ক থাকলে একদিন কেউ জিজ্ঞেস করতেন কোনটা সত্যি,
     * আর তখন উত্তর দেওয়ার কেউ থাকত না।
     */
    public function test_the_capital_page_says_the_same_number(): void
    {
        $this->contribute('Rahim', '100000');
        $this->contribute('Karim', '100000');
        $this->contribute('Salam', '100000');

        /*
         * ⚠️ সংখ্যাটা ইচ্ছে করে "নোংরা" — ৯৯৯.৯৯ তিনভাগে ভাগ করলে পয়সা
         * বাকি থাকে। ⓘ ১০০০ দিলে দুই অঙ্কই একই ফল দিত, আর তখন পরীক্ষাটা
         * কিছুই প্রমাণ করত না।
         */
        $this->earn('999.99');

        $report = $this->report();
        $capital = collect(app(CapitalService::class)->positions($report['profit']))->keyBy('person_id');

        $total = collect($report['rows'])->reduce(fn (string $s, array $r) => bcadd($s, $r['earned'], 4), '0');

        $this->assertSame(
            0,
            bccomp(bcadd($total, $report['unallocated'], 4), $report['profit'], 4),
            "ভাগের যোগ {$total} — লাভ {$report['profit']}-এর সাথে মেলেনি।",
        );

        foreach ($report['rows'] as $row) {
            $this->assertSame(
                0,
                bccomp($row['earned'], (string) $capital[$row['person_id']]['profit_share'], 4),
                "{$row['name']}: রিটার্নের পাতা {$row['earned']}, মূলধনের পাতা {$capital[$row['person_id']]['profit_share']}।",
            );
        }
    }

    /**
     * ⭐ পর্দাটা সত্যিই খোলে, আর নাম-সংখ্যা দুটোই দেখায়।
     */
    public function test_the_page_opens_and_names_who_earned_what(): void
    {
        $this->contribute('Rahim', '600000');
        $this->contribute('Karim', '400000');
        $this->earn('100000');

        $html = $this->page();

        $this->assertStringContainsString(__('finance::investment.title'), $html);
        $this->assertStringContainsString('Rahim', $html);
        $this->assertStringContainsString('Karim', $html);

        // ⓘ কাঁচা চাবি পর্দায় এলে ভাষার ফাইলে কিছু নেই
        $this->assertStringNotContainsString('finance::investment.', $html, 'অনুবাদ না পেয়ে চাবিটাই ছাপা হয়েছে।');
    }

    /**
     * ⛔ যাঁর মূলধন দেখার অনুমতি নেই, তাঁর জন্য দরজা বন্ধ।
     */
    public function test_a_salesman_cannot_open_it(): void
    {
        $salesman = User::query()->where('email', 'sales@abos.test')->firstOrFail();

        $this->actingAs($salesman)
            ->get(route('finance.investment.returns'))
            ->assertForbidden();
    }

    private function report(): array
    {
        return app(InvestmentReturns::class)
            ->forPeriod(now()->startOfYear(), now());
    }

    private function page(): string
    {
        return $this->get(route('finance.investment.returns'))->assertOk()->getContent();
    }

    private function contribute(string $who, string $amount): void
    {
        $person = Person::query()->firstOrCreate(
            ['company_id' => CompanyContext::id(), 'name_en' => $who],
            ['code' => 'P-'.mb_strtoupper(mb_substr($who, 0, 5))],
        );

        $entry = app(CapitalService::class)->record([
            'person_id' => $person->id,
            'contributor_type' => CapitalEntry::PARTNER,
            'entry_type' => CapitalEntry::CONTRIBUTION,
            'trx_date' => now()->toDateString(),
            'amount' => $amount,
            'share_percent' => '',
        ]);

        app(CapitalService::class)->post($entry, $this->account(StandardChart::CASH_IN_HAND));
    }

    /** নগদে আয় — খাতায় বসে, তাই পর্দার হিসাবে আসে। */
    private function earn(string $amount): void
    {
        $this->journal($this->account(StandardChart::CASH_IN_HAND), $this->postable(Account::INCOME), $amount);
    }

    private function spend(string $amount): void
    {
        $this->journal($this->postable(Account::EXPENSE), $this->account(StandardChart::CASH_IN_HAND), $amount);
    }

    private function journal(Account $debit, Account $credit, string $amount): void
    {
        $voucher = app(VoucherService::class)->create(
            ['type' => Voucher::JOURNAL, 'trx_date' => now()->toDateString(), 'narration' => 'test'],
            [
                ['account_id' => $debit->id, 'debit' => $amount],
                ['account_id' => $credit->id, 'credit' => $amount],
            ],
        );

        app(VoucherService::class)->post($voucher);
    }

    private function account(string $code): Account
    {
        $account = Account::query()->where('code', $code)->firstOrFail();

        return $account->is_group ? $this->childOf($account) : $account;
    }

    private function childOf(Account $group): Account
    {
        return Account::query()->postable()->where('parent_id', $group->id)->orderBy('code')->firstOrFail();
    }

    private function postable(string $type): Account
    {
        return Account::query()->postable()->where('type', $type)->orderBy('code')->firstOrFail();
    }
}
