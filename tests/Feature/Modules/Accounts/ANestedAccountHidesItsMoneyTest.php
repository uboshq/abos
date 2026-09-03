<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\AccountsFacts;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Services\HeadTotals;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ছকে একটা ধাপ নামলে টাকাটা লুকিয়ে যেত।
 *
 * ── কী ভাঙার ঝুঁকি ছিল ───────────────────────────────────────────────
 * ড্যাশবোর্ডের সংখ্যাগুলো প্রথমে **এক ধাপ** দেখত (`parent_id = X`)।
 * আজকের ছকে ওটা কাজ করে, কারণ স্থায়ী সম্পদ ও পরিচালন ব্যয়ের নিচে
 * সবগুলোই পাতা।
 *
 * ⚠️ কিন্তু এই পণ্যে **ছক বাড়ানো উৎসাহিত** — মালিকের স্থায়ী নিয়ম:
 * প্রতিটা তালিকা ক্রেতা সেটিংস থেকে বাড়াতে পারবেন। আর ছকে তিন ধাপ
 * **আজই আছে** (১১০১-CASH → ১১০১ → ১১০০), তাই নেস্টিং কল্পনা নয়।
 *
 * যেদিন কোনো ক্রেতা "যানবাহন → ট্রাক ১" বানাতেন, সেদিন দাখিলা বসত
 * নাতির ঘরে আর সম্পদের মূল্য **নীরবে কমে যেত** — কোনো ত্রুটি নয়,
 * কোনো লাল টেস্ট নয়, কেবল মালিকের পাতায় একটা কম সংখ্যা।
 *
 * ── কেন এটা আমাদের কারো ডাটাবেসে ধরা পড়ত না ─────────────────────────
 * আমরা ছক বাড়াই না — ডেমো ডেটা যেমন বসানো তেমনই থাকে। **ভুলটা দেখা
 * দিত প্রথম ক্রেতার কাছে**, যিনি নিজের গাড়িগুলো আলাদা করতে চাইতেন।
 */
final class ANestedAccountHidesItsMoneyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    /**
     * নাতির ঘরে বসানো সম্পদও গোনা হয়।
     */
    public function test_an_asset_two_levels_down_still_counts(): void
    {
        $facts = app(AccountsFacts::class);

        $this->buy(StandardChart::find('1202'), '500000');
        $before = $facts->assetValue();

        $truck = $this->childOf(StandardChart::find('1202'), '120201', 'Truck 1', Account::ASSET);
        $this->buy($truck, '200000', source: 'nested-asset');

        $this->assertSame(
            bcadd($before, '200000', 4),
            $facts->assetValue(),
            'নাতির ঘরে বসানো সম্পদটা মোট থেকে হারিয়ে গেছে।',
        );
    }

    /**
     * ঋণের ক্ষেত্রেও একই — এক ধাপ নিচে বসালে বকেয়া কমে যেত।
     */
    public function test_a_loan_two_levels_down_still_counts(): void
    {
        $facts = app(AccountsFacts::class);

        $bankLoan = Account::query()->where('code', '2210')->firstOrFail();
        $specific = $this->childOf($bankLoan, '221001', 'Sonali Bank', Account::LIABILITY);

        $before = $facts->outstandingLoan();
        $this->borrow($specific, '300000');

        $this->assertSame(
            bcadd($before, '300000', 4),
            $facts->outstandingLoan(),
            'নাতির ঘরে বসানো ঋণটা বকেয়া থেকে হারিয়ে গেছে।',
        );
    }

    /**
     * নাতির খরচ তার **খাতে** ওঠে — নিজের নামে আলাদা সারি হয় না।
     *
     * ⭐ দুইটা দাবি একসাথে: টাকাটা হারায় না, **আর** তালিকাটা স্থির থাকে।
     * নাতির নামে সারি দেখালে ক্রেতা ছক বাড়ানোর সাথে সাথে "খরচের খাত"
     * তালিকাটা ভেঙে টুকরো হয়ে যেত, আর শীর্ষ ছয়টার মানেই থাকত না।
     */
    public function test_a_nested_expense_rolls_up_into_its_head(): void
    {
        $heads = app(HeadTotals::class);
        $hire = StandardChart::find(StandardChart::VEHICLE_HIRE);

        $this->spend($hire, '5000');

        $pickup = $this->childOf($hire, '521701', 'Pickup hire', Account::EXPENSE);
        $this->spend($pickup, '1200', source: 'nested-expense');

        $rows = $heads->topUnder(
            StandardChart::OPERATING_EXPENSES,
            now()->startOfMonth()->toDateString(),
            now()->toDateString(),
        );

        $labels = array_column($rows, 'label');

        $this->assertNotContains('Pickup hire', $labels, 'নাতিটা নিজের নামে সারি হয়ে গেছে।');

        $head = collect($rows)->firstWhere('label', $hire->name());

        $this->assertNotNull($head, 'খাতটাই তালিকায় নেই।');
        $this->assertSame('6200.0000', $head['amount'], 'নাতির টাকাটা খাতে ওঠেনি।');
    }

    // ── সহায়ক ────────────────────────────────────────────────────────

    private function childOf(Account $parent, string $code, string $name, string $type): Account
    {
        return Account::query()->create([
            'code' => $code,
            'name_en' => $name,
            'name_bn' => $name,
            'type' => $type,
            'parent_id' => $parent->id,
            'is_group' => false,
            'nature' => Account::defaultNatureFor($type),
        ]);
    }

    private function buy(Account $asset, string $amount, string $source = 'asset'): void
    {
        $this->pair($asset, StandardChart::find(StandardChart::OWNER_CAPITAL), $amount, $source);
    }

    private function spend(Account $expense, string $amount, string $source = 'expense'): void
    {
        $this->pair($expense, StandardChart::find(StandardChart::CASH_IN_HAND), $amount, $source);
    }

    private function borrow(Account $loan, string $amount): void
    {
        // ঋণ নেওয়া: নগদ বাড়ে (ডেবিট), দায় বাড়ে (ক্রেডিট)
        $this->pair(StandardChart::find(StandardChart::CASH_IN_HAND), $loan, $amount, 'loan');
    }

    private function pair(Account $debit, Account $credit, string $amount, string $source): void
    {
        app(PostingEngine::class)->post(
            sourceType: 'test:'.$source,
            sourceId: random_int(1, 999999),
            trxDate: now(),
            lines: [
                ['account_id' => $debit->id, 'debit' => $amount],
                ['account_id' => $credit->id, 'credit' => $amount],
            ],
        );
    }
}
