<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ রেওয়ামিল আর খাতার জের আন্তর্জাতিক উপস্থাপনে নয় — পুরো-ERP অডিট, ৬ অক্টোবর ২০২৬ (ⓘ১৬; সমন্বয়কের আদেশ ৭ অক্টোবর ২০২৬)।
 *
 * ⓘ রেওয়ামিলে প্রতিটা খাতের মোট ডেবিট আর মোট ক্রেডিট বসত — লেনদেনের যোগফল, জের নয়। এখন খাতপ্রতি নিট জের একটাই কলামে, শূন্য বাদ,
 * দুই কলামের মোট সমান। খাতা, প্রকল্পের খাতা, নগদ/ব্যাংক বই আর খাত ও বাক্সের পাতার চলমান জের "(Dr) 250.79" / "(Cr) 22,958.21" —
 * খালি বিয়োগ নয় (মালিকের নিয়ম, ৩ অক্টোবর ২০২৬)।
 */
final class TheTrialBalanceShowsNetBalancesTest extends TestCase
{
    use RefreshDatabase;

    private Account $box;

    private Account $owed;

    private Account $quiet;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, null);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $make = fn (string $code, string $type, string $nature) => Account::query()->create([
            'company_id' => $company->id, 'code' => $code, 'name_en' => 'TB '.$code, 'name_bn' => 'রে '.$code, 'parent_id' => null,
            'type' => $type, 'nature' => $nature, 'is_group' => false, 'is_active' => true, 'status' => DocumentStatus::CONFIRMED,
        ]);
        $this->box = $make('1981', Account::ASSET, Account::DEBIT);
        $this->owed = $make('2981', Account::LIABILITY, Account::CREDIT);
        $this->quiet = $make('1982', Account::ASSET, Account::DEBIT);

        // ⓘ বাক্সে ১০,০০০ ঢুকল, ৩,০০০ বেরোল → (Dr) ৭,০০০; দেনা (Cr) ৭,০০০; চুপ খাতে ৫০০ ঢুকে বেরোল → শূন্য
        $this->book($this->box->id, $this->owed->id, '10000', 1);
        $this->book($this->owed->id, $this->box->id, '3000', 2);
        $this->book($this->quiet->id, $this->owed->id, '500', 3);
        $this->book($this->owed->id, $this->quiet->id, '500', 4);
    }

    public function test_each_account_shows_its_net_balance_on_one_side(): void
    {
        $result = app(ReportEngine::class)->run('accounts.trial_balance', ['from' => now()->startOfMonth()->toDateString(), 'to' => now()->toDateString()]);
        $rows = collect($result->rows)->keyBy('account_id');

        $this->assertSame(['7000.00', '0.00'], $this->sides($rows[$this->box->id]), '⛔ বাক্সের মোট লেনদেন বসল, নিট জের নয়');
        $this->assertSame(['0.00', '7000.00'], $this->sides($rows[$this->owed->id]), '⛔ দেনার জের ক্রেডিট কলামে নয়');
        $this->assertFalse($rows->has($this->quiet->id), '⛔ শূন্য-জেরের খাত রেওয়ামিলে রইল');
        $this->assertSame(0, bccomp((string) $result->totals['debit'], (string) $result->totals['credit'], 2), '⛔ দুই কলামের মোট সমান নয়');
    }

    public function test_running_balances_say_dr_or_cr_and_never_a_bare_minus(): void
    {
        foreach (['accounts.ledger', 'accounts.project_ledger', 'accounts.cash_book', 'accounts.bank_book'] as $key) {
            $balance = collect(app(ReportEngine::class)->get($key)->columns)->first(fn ($c) => $c->key === 'balance');
            $this->assertSame(ReportColumn::DR_CR, $balance?->type, "⛔ {$key}-এর জের (Dr)/(Cr) নয়");
        }

        $ledger = app(ReportEngine::class)->run('accounts.ledger', ['account_id' => $this->owed->id, 'from' => now()->subDays(10)->toDateString(), 'to' => now()->toDateString()]);
        $column = collect($ledger->report->columns)->first(fn ($c) => $c->key === 'balance');
        $this->assertSame('(Cr) 7,000.00', $column->signed(collect($ledger->rows)->last()['balance']), '⛔ দেনার শেষ জের (Cr)-এ নয়');

        // ⓘ খাতের পাতা আর বাক্সের পাতা
        $this->get(route('accounts.coa.show', $this->owed))->assertOk()->assertSee('(Cr) 7,000.00')->assertDontSee('-7,000.00');
        $till = app(CashTillService::class)->ensurePrimaryTill();
        $this->book((int) $till->account_id, $this->owed->id, '1234', 5);
        $this->get(route('accounts.till.show', $till))->assertOk()->assertSee('(Dr) ');
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    /** @return array{0: string, 1: string} */
    private function sides(array $row): array
    {
        return [bcadd((string) $row['debit'], '0', 2), bcadd((string) $row['credit'], '0', 2)];
    }

    private function book(int $debit, int $credit, string $amount, int $n): void
    {
        app(PostingEngine::class)->post(sourceType: 'journal_voucher', sourceId: 880000 + $n, trxDate: now()->subDays(6 - $n)->toDateString(), lines: [
            ['account_id' => $debit, 'debit' => $amount],
            ['account_id' => $credit, 'credit' => $amount],
        ]);
    }
}
