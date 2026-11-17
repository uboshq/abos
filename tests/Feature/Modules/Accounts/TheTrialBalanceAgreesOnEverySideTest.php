<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Support\CompanyContext;
use App\Modules\Accounts\Models\Account;
use Carbon\Carbon;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ রেওয়ামিল — ৬ কলাম, সব দিক দিয়ে মেলে (মালিক, ১৬ নভেম্বর ২০২৬: *"ব্যালেন্স শিট আরেকটা কি রিপোর্ট আছে … যেটা হচ্ছে যে সব দিক দিয়ে মিলে"* —
 *   "জি, ট্রায়াল ব্যালেন্সের কথা বলছিলাম")।
 *
 *   - শুরুর জের ("থেকে"-র আগের সব), এই সময়ের লেনদেন আর শেষ জের — প্রতিটা ডেবিট/ক্রেডিট
 *   - ডেমো খাতা: ১ জুলাই শুরুর মজুদ ৮,৪০,০০০ (মজুদ ↔ সঞ্চিত মুনাফা), আর পরীক্ষা নিজে বসায় ১০ নভেম্বরের একটা চেক ১,২৫,০০০ (সরবরাহকারী ↔ ভাঙানো-হয়নি চেক)
 *   - নভেম্বরের রেওয়ামিলে মজুদ শুরুর জেরে, চেক লেনদেনে; তিন জোড়া মোটই সমান
 *   - "শুরু থেকে" বাছলে শুরুর জের শূন্য, সব লেনদেনে
 */
final class TheTrialBalanceAgreesOnEverySideTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // ⓘ তারিখ বাঁধা — চেকটা ১০ নভেম্বরের; অন্য দিনে চালালে পেছনের সীমা বা মাসের তালা ওটা আটকাত
        $this->travelTo(Carbon::parse('2026-11-16 12:00'));
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        // ⓘ সব শাখা — রেওয়ামিল গোটা কোম্পানির; একটা শাখা বাছলে অন্য শাখার সরবরাহকারী বাদ পড়ত
        CompanyContext::set($company->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        // ⓘ ডেমো খাতায় নভেম্বরের লেনদেন নেই — চেকটা পরীক্ষা নিজে বসায় (সরবরাহকারী ↔ দেওয়া চেক, ভাঙানো হয়নি)
        $code = fn (string $c) => (int) Account::query()->where('company_id', $company->id)->where('code', $c)->value('id');
        app(PostingEngine::class)->post(sourceType: 'test.cheque_issued', sourceId: 1, trxDate: '2026-11-10', lines: [
            ['account_id' => $code('2111'), 'debit' => '125000'],
            ['account_id' => $code('2115'), 'credit' => '125000'],
        ]);
    }

    public function test_november_shows_the_opening_stock_as_opening_and_the_cheque_as_movement(): void
    {
        [$head, $rows, $total] = $this->trialBalance(['from' => '2026-11-01', 'to' => '2026-11-16']);

        $stock = $this->row($rows, '1120');
        $this->assertSame(['840000.00', '0.00', '0.00', '0.00', '840000.00', '0.00'], $this->money($head, $stock),
            '⛔ মজুদ: শুরুর জের ডেবিট ৮,৪০,০০০, লেনদেন নেই, শেষ জের ডেবিট ৮,৪০,০০০');

        $supplier = $this->row($rows, '2111');
        $this->assertSame(['0.00', '0.00', '125000.00', '0.00', '125000.00', '0.00'], $this->money($head, $supplier),
            '⛔ সরবরাহকারী: শুরুর জের নেই, নভেম্বরের চেক লেনদেনে ডেবিট ১,২৫,০০০');

        $sums = $this->money($head, $total);
        $this->assertSame($sums[0], $sums[1], '⛔ শুরুর জের — ডেবিট আর ক্রেডিট মেলেনি');
        $this->assertSame($sums[2], $sums[3], '⛔ এই সময়ের লেনদেন — ডেবিট আর ক্রেডিট মেলেনি');
        $this->assertSame($sums[4], $sums[5], '⛔ শেষ জের — ডেবিট আর ক্রেডিট মেলেনি');
        $this->assertSame(['840000.00', '840000.00', '125000.00', '125000.00', '965000.00', '965000.00'], $sums);
    }

    /** ⭐ অঙ্কে চাপলে ঐ হিসাবের খতিয়ান — একই সময়ে, রেওয়ামিলের নিজের চাবির দরজায় (অঙ্ক কোথা থেকে এল) */
    public function test_a_figure_opens_that_accounts_ledger_for_the_same_period(): void
    {
        $html = (string) $this->get(route('accounts.report.show', ['slug' => 'trial-balance', 'from' => '2026-11-01', 'to' => '2026-11-16']))
            ->assertOk()->getContent();
        $account = (int) Account::query()->where('code', '2111')->value('id');
        $ledger = route('accounts.report.tb_ledger', ['account_id' => $account, 'from' => '2026-11-01', 'to' => '2026-11-16']);

        $this->assertStringContainsString(e($ledger), $html, '⛔ মালের সরবরাহকারীর অঙ্কে চাপলে নভেম্বরের খতিয়ান খোলে না');

        $page = (string) $this->get($ledger)->assertOk()->getContent();
        $this->assertStringContainsString('1,25,000.00', $page, '⛔ খতিয়ানে নভেম্বরের চেকটা নেই');
    }

    /** ⛔ দরজা রেওয়ামিলের চাবি চায় — কেবল `accounts.report` থাকলে নয়, কেবল `.final` থাকলে হ্যাঁ */
    public function test_the_ledger_door_asks_for_the_trial_balances_key(): void
    {
        // ⓘ একই মানুষ: প্রথমে কেবল রোজকার খতিয়ানের চাবি → বন্ধ; রেওয়ামিলের চাবি দিলে খোলে; রোজকারটা তুলে নিলেও খোলা
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $clerk = User::factory()->create(['current_company_id' => $company->id]);
        $clerk->companies()->attach($company->id, ['is_active' => true]);
        $clerk->givePermissionTo('accounts.report');
        $account = (int) Account::query()->where('code', '2111')->value('id');
        $url = route('accounts.report.tb_ledger', ['account_id' => $account, 'from' => '2026-11-01', 'to' => '2026-11-16']);

        $this->actingAs($clerk)->get($url)->assertForbidden();

        $clerk->givePermissionTo('accounts.report.final');
        $this->actingAs($clerk->fresh())->get($url)->assertOk();

        $clerk->revokePermissionTo('accounts.report');
        $this->actingAs($clerk->fresh())->get($url)->assertOk();
    }

    public function test_from_the_start_everything_is_movement(): void
    {
        [$head, $rows, $total] = $this->trialBalance(['from' => 'all', 'to' => '2026-11-16']);

        $sums = $this->money($head, $total);
        $this->assertSame(['0.00', '0.00'], [$sums[0], $sums[1]], '⛔ "শুরু থেকে" বাছলে শুরুর জের শূন্য হওয়ার কথা');
        $this->assertSame($sums[2], $sums[3]);
        $this->assertSame($sums[4], $sums[5]);
    }

    /**
     * @param  array<string, string>  $query
     * @return array{0: list<string>, 1: list<list<string>>, 2: list<string>}
     */
    private function trialBalance(array $query): array
    {
        $csv = (string) $this->get(route('accounts.report.show', ['slug' => 'trial-balance', 'export' => 'csv', ...$query]))->assertOk()->getContent();
        $lines = array_values(array_filter(preg_split('/\r?\n/', ltrim($csv, "\u{FEFF}"))));
        $all = array_map('str_getcsv', $lines);

        $this->assertCount(7, $all[0], '⛔ রেওয়ামিলে ৬টা টাকার কলাম থাকার কথা (শুরু, লেনদেন, শেষ — ডেবিট/ক্রেডিট)');

        return [$all[0], array_slice($all, 1, -1), end($all)];
    }

    /** @param  list<list<string>>  $rows */
    private function row(array $rows, string $code): array
    {
        foreach ($rows as $row) {
            if (str_starts_with($row[0], $code)) {
                return $row;
            }
        }

        $this->fail("রেওয়ামিলে {$code} হিসাব নেই — আছে: ".implode(' | ', array_map(fn (array $r) => implode(',', $r), $rows)));
    }

    /** @return list<string> */
    private function money(array $head, array $row): array
    {
        return array_map(fn (string $v) => number_format((float) ($v === '' ? 0 : $v), 2, '.', ''), array_slice($row, 1, 6));
    }
}
