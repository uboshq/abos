<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Engines\Report\ReportResult;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\CashTill;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * আটটা রিপোর্ট — সংখ্যাগুলো সত্যি কি না।
 *
 * রিপোর্ট চলল কি না সেটা যথেষ্ট নয়: ভুল কোয়েরিও চলে, আর ভুল সংখ্যা
 * দেখতে সঠিক সংখ্যার মতোই। তাই প্রতিটা রিপোর্টের জন্য জানা ডাটা বসিয়ে
 * প্রত্যাশিত সংখ্যাটাই মেলানো হয়।
 */
class AccountReportsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    private CashTill $till;

    private int $bank;

    private int $rent;

    private int $sales;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->user = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs($this->user);

        app(StandardChart::class)->install();

        $this->till = app(CashTillService::class)->ensurePrimaryTill();
        $this->rent = (int) Account::query()->where('code', '5202')->firstOrFail()->id;
        $this->sales = (int) StandardChart::find(StandardChart::SALES)->id;

        $this->bank = (int) Account::query()->create([
            'company_id' => $this->company->id,
            'code' => '1102-RPT',
            'name_en' => 'Report Bank',
            'name_bn' => 'রিপোর্ট ব্যাংক',
            'parent_id' => StandardChart::find(StandardChart::BANK_AND_MFS)->id,
            'type' => Account::ASSET,
            'nature' => Account::DEBIT,
            'is_bank' => true,
            'is_active' => true,
            'status' => DocumentStatus::CONFIRMED,
        ])->id;

        $this->seedTransactions();
    }

    /**
     * জানা ডাটা — প্রতিটা প্রত্যাশিত সংখ্যা এখান থেকেই আসে।
     *
     *   ১০ আগস্ট: বিক্রয় ২০,০০০ নগদে   → নগদ +২০,০০০, বিক্রয় +২০,০০০
     *   ১১ আগস্ট: ভাড়া ৬,০০০ নগদে       → নগদ −৬,০০০, ভাড়া +৬,০০০
     *   ১২ আগস্ট: ব্যাংকে জমা ৫,০০০      → নগদ −৫,০০০, ব্যাংক +৫,০০০
     *
     *   শেষে: নগদ ৯,০০০ · ব্যাংক ৫,০০০ · বিক্রয় ২০,০০০ · ভাড়া ৬,০০০
     */
    private function seedTransactions(): void
    {
        $svc = app(VoucherService::class);
        $cash = (int) $this->till->account_id;

        foreach ([
            ['receipt', $this->sales, $cash, '20000.00', '2026-08-10'],
            ['expense', $cash, $this->rent, '6000.00', '2026-08-11'],
            ['contra', $cash, $this->bank, '5000.00', '2026-08-12'],
        ] as [$type, $from, $to, $amount, $date]) {
            $svc->post($svc->create(
                ['type' => $type, 'trx_date' => $date, 'narration' => 'seed'],
                $svc->twoLineEntry($type, $from, $to, $amount, 'seed'),
            ));
        }
    }

    /**
     * PHPUnit-এর TestCase-এ run(), count() ও post() তিনটাই final, তাই
     * ব্যক্তিগত হেল্পারের নাম ওগুলোর সাথে মেলানো যায় না। তিনবারই একই
     * ভুল হয়েছে; নামটা আলাদা রাখাই সহজ।
     */
    private function report(string $key, array $filters = []): ReportResult
    {
        return app(ReportEngine::class)->run($key, [
            'from' => '2026-08-01',
            'to' => '2026-08-31',
            ...$filters,
        ]);
    }

    // ── প্রতিটা রিপোর্ট চলে ─────────────────────────────────────────────

    #[DataProvider('reportKeys')]
    public function test_every_report_runs_and_balances(string $key): void
    {
        $result = $this->report($key);

        $this->assertGreaterThan(0, $result->totalRows, "{$key} returned nothing.");

        // যেগুলোতে ডেবিট ও ক্রেডিট দুইটাই যোগ হয় সেগুলো মিলতে হবে —
        // না মিললে কোয়েরিটা লেজারের একটা অংশ বাদ দিচ্ছে
        if (isset($result->totals['debit'], $result->totals['credit'])
            && in_array($key, ['accounts.day_book', 'accounts.ledger', 'accounts.trial_balance'], true)) {
            $this->assertSame(
                $result->totals['debit'],
                $result->totals['credit'],
                "{$key}: debit and credit do not match.",
            );
        }
    }

    public static function reportKeys(): array
    {
        return [
            'day book' => ['accounts.day_book'],
            'cash book' => ['accounts.cash_book'],
            'bank book' => ['accounts.bank_book'],
            'ledger' => ['accounts.ledger'],
            'trial balance' => ['accounts.trial_balance'],
            'profit and loss' => ['accounts.profit_loss'],
            'balance sheet' => ['accounts.balance_sheet'],
            'cash flow' => ['accounts.cash_flow'],
        ];
    }

    // ── সংখ্যাগুলো সত্যি কি না ──────────────────────────────────────────

    public function test_the_day_book_holds_every_line_of_every_voucher(): void
    {
        // তিনটা ভাউচার, প্রতিটার দুইটা সারি
        $this->assertSame(6, $this->report('accounts.day_book')->totalRows);
    }

    public function test_the_cash_book_shows_only_cash_accounts(): void
    {
        $result = $this->report('accounts.cash_book');

        // নগদের তিনটা চলাচল: +২০,০০০, −৬,০০০, −৫,০০০
        $this->assertSame(3, $result->totalRows);
        $this->assertSame('20000.00', $result->totals['debit']);
        $this->assertSame('11000.00', $result->totals['credit']);

        // ব্যাংকের সারিটা এখানে নেই — থাকলে ক্যাশ বইটা ক্যাশ বই নয়
        foreach ($result->rows as $row) {
            $this->assertStringNotContainsString('1102-RPT', $row['account_name']);
        }
    }

    public function test_the_bank_book_shows_only_bank_accounts(): void
    {
        $result = $this->report('accounts.bank_book');

        $this->assertSame(1, $result->totalRows);
        $this->assertSame('5000.00', $result->totals['debit']);
    }

    public function test_the_trial_balance_balances(): void
    {
        $result = $this->report('accounts.trial_balance');

        // প্রতিটা ভাউচার সমান, তাই যোগফলও সমান — না মিললে কোথাও
        // একটা সারি হারিয়েছে
        $this->assertSame($result->totals['debit'], $result->totals['credit']);
        $this->assertSame('31000.00', $result->totals['debit']);
    }

    public function test_the_profit_and_loss_holds_only_income_and_expense(): void
    {
        $result = $this->report('accounts.profit_loss');

        $names = collect($result->rows)->pluck('account_name')->implode(' ');

        $this->assertStringContainsString('4100', $names, 'Sales is missing.');
        $this->assertStringContainsString('5202', $names, 'Rent is missing.');

        // নগদ ও ব্যাংক সম্পদ — লাভ-লোকসানে থাকার কথা নয়
        $this->assertStringNotContainsString('1102-RPT', $names);

        foreach ($result->rows as $row) {
            $this->assertContains($row['type'], [Account::INCOME, Account::EXPENSE]);
        }
    }

    public function test_the_balance_sheet_holds_no_income_or_expense(): void
    {
        $result = $this->report('accounts.balance_sheet');

        foreach ($result->rows as $row) {
            $this->assertContains($row['type'], Account::BALANCE_SHEET_TYPES, $row['account_name']);
        }
    }

    /**
     * ব্যালেন্স শিট একটা মুহূর্তের ছবি, একটা পরিসরের নয়।
     *
     * শুরুর তারিখ যাই দেওয়া হোক, সংখ্যাগুলো একই থাকতে হবে — নাহলে
     * "৩১ আগস্টে সম্পদ কত" প্রশ্নের উত্তর নির্ভর করত কেউ কোন তারিখ
     * থেকে দেখছে তার উপর, যা অর্থহীন।
     */
    public function test_the_balance_sheet_ignores_the_start_date(): void
    {
        $wide = $this->report('accounts.balance_sheet', ['from' => '2026-07-01']);
        $narrow = $this->report('accounts.balance_sheet', ['from' => '2026-08-12']);

        $this->assertSame($wide->totals, $narrow->totals);
    }

    /** লাভ-লোকসান পরিসরের — শুরুর তারিখ বদলালে সংখ্যাও বদলায়। */
    public function test_the_profit_and_loss_does_respect_the_start_date(): void
    {
        $wide = $this->report('accounts.profit_loss');
        $narrow = $this->report('accounts.profit_loss', ['from' => '2026-08-12']);

        $this->assertNotSame($wide->totals['credit'], $narrow->totals['credit']);
    }

    public function test_the_cash_flow_groups_by_day(): void
    {
        $result = $this->report('accounts.cash_flow');

        // তিনটা আলাদা দিন
        $this->assertSame(3, $result->totalRows);
        $this->assertSame('25000.00', $result->totals['debit']);   // ২০,০০০ নগদ + ৫,০০০ ব্যাংক
        $this->assertSame('11000.00', $result->totals['credit']);
    }

    /**
     * খাতের ঘরে নাম, id নয়।
     *
     * "১৪" দেখে কেউ বলতে পারে না কোন খাত, আর রিপোর্ট ছাপিয়ে গ্রাহককে
     * দেখানোর সময় সেটা আরও অর্থহীন।
     */
    public function test_reports_show_account_names_not_numbers(): void
    {
        $row = $this->report('accounts.trial_balance')->rows[0];

        $this->assertArrayHasKey('account_name', $row);
        $this->assertMatchesRegularExpression('/^\d+.* — .+/u', $row['account_name']);
    }

    /**
     * খাত হারিয়ে গেলেও টাকাটা রিপোর্টে থাকে।
     *
     * প্রথমে join ছিল INNER, আর তাতে যে সারির খাত নেই সেটা রিপোর্ট থেকে
     * নীরবে উধাও হয়ে যেত — টাকা হারিয়ে যাওয়াই সবচেয়ে খারাপ ব্যর্থতা,
     * কারণ যোগফল তখনো মেলে, শুধু কম মেলে।
     *
     * নামের ঘরে "#৯৯৯" বসে, ফাঁকা নয়: ফাঁকা হলে সারিটা থাকত কিন্তু
     * কীসের টাকা তা বলার কিছু থাকত না।
     */
    public function test_a_ledger_row_whose_account_is_gone_still_appears(): void
    {
        LedgerEntry::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->company->defaultBranch()?->id,
            'financial_year_id' => $this->company->currentFinancialYear()?->id,
            'account_id' => 999999,
            'trx_date' => '2026-08-20',
            'debit' => '777.00',
            'credit' => '0',
            'source_type' => 'journal_voucher',
            'source_id' => 1,
            'document_no' => 'ORPHAN-1',
        ]);

        $result = $this->report('accounts.day_book');

        $names = collect($result->rows)->pluck('account_name');

        $this->assertTrue($names->contains('#999999'), 'The orphan row vanished from the day book.');
        $this->assertStringContainsString('777', collect($result->rows)
            ->firstWhere('document_no', 'ORPHAN-1')['debit']);
    }

    // ── স্ক্রিন ও অনুমতি ───────────────────────────────────────────────

    public function test_every_report_screen_opens(): void
    {
        foreach ([
            'day-book', 'cash-book', 'bank-book', 'ledger',
            'trial-balance', 'profit-loss', 'balance-sheet', 'cash-flow',
        ] as $slug) {
            $this->get(route('accounts.report.show', ['slug' => $slug, 'from' => '2026-08-01', 'to' => '2026-08-31']))
                ->assertOk();
        }
    }

    public function test_an_unknown_report_is_a_404(): void
    {
        $this->get(route('accounts.report.show', 'nonsense'))->assertNotFound();
    }

    /**
     * চূড়ান্ত হিসাব আলাদা অনুমতি চায়।
     *
     * হিসাবরক্ষককে ডে বুক ও লেজার রোজ দেখতে হয়, কিন্তু প্রতিষ্ঠানের
     * মুনাফা কত সেটা সবার জানার কথা নয়।
     */
    public function test_final_accounts_need_their_own_permission(): void
    {
        $clerk = User::factory()->create();
        $clerk->companies()->attach($this->company, ['is_active' => true]);
        $clerk->forceFill(['current_company_id' => $this->company->id])->save();
        $clerk->givePermissionTo(Permission::findOrCreate('accounts.report', 'web'));

        $this->actingAs($clerk);

        $this->get(route('accounts.report.show', 'day-book'))->assertOk();
        $this->get(route('accounts.report.show', 'ledger'))->assertOk();

        foreach (['profit-loss', 'balance-sheet', 'cash-flow'] as $slug) {
            $this->get(route('accounts.report.show', $slug))->assertForbidden();
        }
    }

    public function test_a_document_number_in_a_report_links_back_to_its_voucher(): void
    {
        $voucher = Voucher::query()->where('type', 'expense')->firstOrFail();

        // নিয়ম ১ — রিপোর্টের সংখ্যা থেকে তার উৎসে
        $this->get(route('accounts.report.show', ['slug' => 'day-book', 'from' => '2026-08-01', 'to' => '2026-08-31']))
            ->assertOk()
            ->assertSee($voucher->document_no)
            ->assertSee(route('accounts.voucher.show', $voucher), false);
    }

    public function test_one_company_never_sees_another_companys_numbers(): void
    {
        $other = Company::query()->where('code', 'FMART')->firstOrFail();
        CompanyContext::set($other->id, $other->defaultBranch()?->id);

        $this->assertSame(0, $this->report('accounts.day_book')->totalRows);
    }
}
