<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\CapitalEntry;
use App\Modules\Finance\Models\ProfitShare;
use App\Modules\Finance\Models\Withdrawal;
use App\Modules\Finance\Services\CapitalService;
use App\Modules\Finance\Services\ProfitDistribution;
use App\Modules\Finance\Services\WithdrawalService;
use App\Modules\MasterData\Models\Person;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * লাভ বণ্টনে চাওয়ার চেয়ে বেশি বেরোত, আর অংশীদারের হিসাব ভুল মাপে — অডিট গ১৩, ম২৬ (৪ অক্টোবর ২০২৬)।
 *
 * ── ⛔ কী ছিল ─────────────────────────────────────────────────────────
 *   • চুক্তির অংশ ৬০ + ৬০ হলে ১০ লাখের ঘোষণায় ১২ লাখ খাতায় যেত — সীমা মাপা হত চাওয়া অঙ্কে, খাতায় যেত যোগফল।
 *   • একজন দুই পরিচয়ে মূলধন দিলে দুই সারি — লাভের ভাগ দুইবার।
 *   • চুক্তির অংশ ছিল সবচেয়ে বড়টা (`MAX`), সবশেষটা নয়।
 *   • বেতন আর লাভের ভাগ তোলাও "মূলধন তোলা" গোনা হত — অংশীদারের নিট মূলধন আর অংশ কমত।
 *   • "সঞ্চিত মুনাফা" মানে ৩৩০০-এর কাঁচা জের — খোলা মজুদের সমতার অঙ্কও "লাভ"।
 */
final class TheProfitWasPaidBeyondWhatWasDeclaredTest extends TestCase
{
    use PutsMoneyInTheTill;
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
        app(CashTillService::class)->ensurePrimaryTill();
        $this->putMoneyIn($this->cash(), '1000000', now()->subDays(5)->toDateString());

        // ⓘ ডেমোর আগের মূলধনদাতারা অংশ টানত — এই ফাইল কেবল নিজের মানুষদের মাপে
        CapitalEntry::query()->delete();
    }

    /** ⛔ ৬০ + ৬০ — ঘোষণাই হয় না; আগে চাওয়ার ১২০% খাতায় যেত। */
    public function test_agreed_shares_over_a_hundred_are_refused(): void
    {
        $this->contribute('OVER-A', CapitalEntry::PARTNER, '500000', '60');
        $this->contribute('OVER-B', CapitalEntry::PARTNER, '500000', '60');
        $this->earn('200000');

        $this->assertRefused(fn () => $this->declare('100000'), 'profit',
            '⛔ চুক্তির অংশ মিলে ১২০%, অথচ ঘোষণা হয়ে গেল।');

        $this->assertSame(0, ProfitShare::query()->count(), '⛔ বাতিল ঘোষণার ভাগ খাতায় বসেছে।');
    }

    /** ⛔ একজন দুই পরিচয়ে — একবারই ভাগ পান, আর মোট ঘোষণার বেশি নয়। */
    public function test_one_person_in_two_roles_is_paid_once(): void
    {
        $both = $this->contribute('TWO-ROLES', CapitalEntry::OWNER, '300000', '40');
        $this->contribute('TWO-ROLES', CapitalEntry::PARTNER, '300000', '40', '-B');
        $this->contribute('OTHER', CapitalEntry::PARTNER, '300000', '40');
        $this->earn('200000');

        $this->declare('100000');

        $this->assertSame(1, ProfitShare::query()->where('person_id', $both->id)->count(),
            '⛔ একজন মানুষ দুই পরিচয়ে দুইবার লাভের ভাগ পেলেন।');
        $this->assertLessThanOrEqual(0, bccomp((string) ProfitShare::query()->sum('amount'), '100000', 4),
            '⛔ ১,০০,০০০-এর ঘোষণায় খাতায় গেল '.ProfitShare::query()->sum('amount').'।');
    }

    /** ⛔ চুক্তির অংশ সবশেষটা — ৪০% থেকে ২০%-এ নামলে ২০%। */
    public function test_the_agreed_share_is_the_latest_not_the_highest(): void
    {
        $person = $this->contribute('LATEST', CapitalEntry::PARTNER, '100000', '40', '', now()->subMonths(2)->toDateString());
        $this->contribute('LATEST', CapitalEntry::PARTNER, '100000', '20', '-NEW', now()->subMonth()->toDateString());

        $row = collect(app(CapitalService::class)->positions())->firstWhere('person_id', $person->id);

        $this->assertSame(0, bccomp((string) $row['share'], '20', 4), '⛔ চুক্তির অংশ '.$row['share'].' — নতুন চুক্তি ২০%, পুরনো ৪০% খাটছে।');
    }

    /** ⛔ বেতন খরচ, মূলধন তোলা নয় — নিট মূলধনে কেবল উত্তোলন। */
    public function test_a_salary_is_not_counted_as_capital_taken(): void
    {
        $person = $this->contribute('SALARIED', CapitalEntry::PARTNER, '100000', null);

        $this->withdraw($person, '10000', Withdrawal::SALARY);
        $this->withdraw($person, '5000', Withdrawal::DRAWING);

        $row = collect(app(CapitalService::class)->positions())->firstWhere('person_id', $person->id);

        $this->assertSame(0, bccomp((string) $row['withdrawn'], '5000', 4),
            '⛔ মূলধন তোলা দেখাচ্ছে '.$row['withdrawn'].' — ১০,০০০ বেতনও মূলধন কমিয়েছে।');
    }

    /**
     * ⭐ ফল থেকে বণ্টনযোগ্য মুনাফা — খোলা জেরের সমতার অঙ্ক "লাভ" নয়।
     *
     * ⓘ খোলা মজুদ ৩৩০০-এ বসে (সমতার অঙ্ক) — এই মাপ নড়ে না; আয় হলে নড়ে; ঘোষণা হলে কমে।
     */
    public function test_distributable_profit_comes_from_results_not_from_opening_balances(): void
    {
        $service = app(ProfitDistribution::class);
        $start = $service->distributableFromResults();

        $this->postLines('opening', [
            ['account_id' => StandardChart::find(StandardChart::DEPOSITS_AND_INVESTMENTS)->id, 'debit' => '5000000'],
            ['account_id' => StandardChart::find(StandardChart::RETAINED_EARNINGS)->id, 'credit' => '5000000'],
        ]);

        $this->assertSame(0, bccomp($service->distributableFromResults(), $start, 4),
            '⛔ ৫০ লাখের খোলা সম্পদ "বণ্টনযোগ্য লাভ" হয়ে গেল।');

        $this->postLines('test:sale', [
            ['account_id' => $this->cash()->id, 'debit' => '80000'],
            ['account_id' => StandardChart::find(StandardChart::INTEREST_INCOME)->id, 'credit' => '80000'],
        ]);

        $this->assertSame(0, bccomp(bcsub($service->distributableFromResults(), $start, 4), '80000', 4),
            'আয় হলো ৮০,০০০, অথচ বণ্টনযোগ্য লাভ বাড়েনি।');
    }

    // ── সহায়ক ───────────────────────────────────────────────────────────

    private function declare(string $profit): void
    {
        app(ProfitDistribution::class)->declare(['profit' => $profit, 'trx_date' => now()->toDateString()]);
    }

    private function withdraw(Person $person, string $amount, string $kind): void
    {
        $service = app(WithdrawalService::class);

        $service->post($service->request([
            'person_id' => $person->id,
            'amount' => $amount,
            'kind' => $kind,
            'trx_date' => now()->toDateString(),
        ]), $this->cash());
    }

    private function assertRefused(callable $what, string $field, string $why): void
    {
        try {
            $what();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors(), $why);

            return;
        }

        $this->fail($why);
    }

    private function contribute(string $code, string $type, string $amount, ?string $share, string $suffix = '', ?string $on = null): Person
    {
        $person = Person::query()->firstOrCreate(
            ['company_id' => $this->company->id, 'code' => $code],
            ['name_en' => $code, 'name_bn' => $code, 'is_active' => true],
        );

        CapitalEntry::query()->create([
            'branch_id' => Branch::query()->firstOrFail()->id,
            'document_no' => 'CAP-'.$code.$suffix,
            'person_id' => $person->id,
            'contributor_type' => $type,
            'entry_type' => CapitalEntry::CONTRIBUTION,
            'in_kind' => CapitalEntry::CASH,
            'trx_date' => $on ?? now()->subMonth()->toDateString(),
            'amount' => $amount,
            'share_percent' => $share,
            'status' => CapitalEntry::POSTED,
        ]);

        return $person;
    }

    /** সঞ্চিত মুনাফায় ঢোকা — আজকের সীমা যেটা মাপে ([[AProfitWasSharedWithNobodyToSignTest]]-এর একই উপায়) */
    private function earn(string $amount): void
    {
        $this->postLines('test:earned', [
            ['account_id' => $this->cash()->id, 'debit' => $amount],
            ['account_id' => StandardChart::find(StandardChart::RETAINED_EARNINGS)->id, 'credit' => $amount],
        ]);
    }

    /** @param  list<array<string, mixed>>  $lines */
    private function postLines(string $source, array $lines): void
    {
        app(PostingEngine::class)->post(
            sourceType: $source,
            sourceId: random_int(1, 999999),
            trxDate: now()->subDays(2)->toDateString(),
            lines: $lines,
        );
    }

    private function cash(): Account
    {
        return Account::query()->money()->postable()->active()->orderBy('code')->firstOrFail();
    }
}
