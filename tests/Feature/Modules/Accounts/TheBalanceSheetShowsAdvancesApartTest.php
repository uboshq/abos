<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\BalanceSheetService;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * স্থিতিপত্রে অগ্রিম আলাদা লাইনে — উপস্থাপনে, খাতায় নয় (মালিকের পরিকল্পনা সংস্করণ ২, ৪ অক্টোবর ২০২৬; [[BalanceSheetService]])।
 *
 * গ্রাহক ক ১,০০০ দেনদার, খ ৩০০ অগ্রিম দিয়েছেন; সরবরাহকারী গ-কে ২০০ অগ্রিম, ঘ-এর কাছে ৫০০ দেনা।
 * ⓘ আগে পাওনা দেখাত নিট ৭০০, দেনা নিট ৩০০। এখন পাওনা ১,০০০ আর দায়ে "গ্রাহকের অগ্রিম" ৩০০; দেনা ৫০০ আর সম্পদে
 * "সরবরাহকারীর অগ্রিম" ২০০ — আর স্থিতিপত্র মেলে।
 */
final class TheBalanceSheetShowsAdvancesApartTest extends TestCase
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
    }

    public function test_receivable_and_payable_show_gross_and_advances_sit_on_the_other_side(): void
    {
        $before = app(BalanceSheetService::class)->build();
        $base = [
            'receivable' => $this->lineOf($before['assets'], StandardChart::RECEIVABLE),
            'payable' => $this->lineOf($before['liabilities'], StandardChart::PAYABLE_GROUP),
        ];

        $cash = (int) DB::table('accounts')->where('company_id', $this->company->id)->where('money_kind', 'cash')->where('is_group', false)->orderBy('id')->value('id');
        $income = (int) DB::table('accounts')->where('company_id', $this->company->id)->where('is_group', false)->where('type', 'income')->orderBy('id')->value('id');
        $stock = (int) StandardChart::find(StandardChart::INVENTORY)->id;
        $rec = (int) StandardChart::find(StandardChart::RECEIVABLE)->id;
        $pay = (int) StandardChart::find(StandardChart::PAYABLE)->id;

        $this->book(1, [['account_id' => $rec, 'debit' => '1000', 'party_type' => 'customer', 'party_id' => 900001], ['account_id' => $income, 'credit' => '1000']]);
        $this->book(2, [['account_id' => $cash, 'debit' => '300'], ['account_id' => $rec, 'credit' => '300', 'party_type' => 'customer', 'party_id' => 900002]]);
        $this->book(3, [['account_id' => $pay, 'debit' => '200', 'party_type' => 'supplier', 'party_id' => 900003], ['account_id' => $cash, 'credit' => '200']]);
        $this->book(4, [['account_id' => $stock, 'debit' => '500'], ['account_id' => $pay, 'credit' => '500', 'party_type' => 'supplier', 'party_id' => 900004]]);

        $sheet = app(BalanceSheetService::class)->build();

        $this->assertSame(0, bccomp(bcsub($this->lineOf($sheet['assets'], StandardChart::RECEIVABLE), $base['receivable'], 4), '1000', 4), '⛔ পাওনা নিট দেখাচ্ছে, মোট নয়।');
        $this->assertSame(0, bccomp(bcsub($this->lineOf($sheet['liabilities'], StandardChart::PAYABLE_GROUP), $base['payable'], 4), '500', 4), '⛔ দেনা নিট দেখাচ্ছে, মোট নয়: আগে '.$base['payable'].', এখন '.$this->lineOf($sheet['liabilities'], StandardChart::PAYABLE_GROUP).', অগ্রিম '.$this->advance($sheet['assets'], __('accounts::field.supplier_advance')));
        $this->assertSame(0, bccomp($this->advance($sheet['liabilities'], __('accounts::field.customer_advance')), '300', 4), '⛔ দায়ে গ্রাহকের অগ্রিম নেই।');
        $this->assertSame(0, bccomp($this->advance($sheet['assets'], __('accounts::field.supplier_advance')), '200', 4), '⛔ সম্পদে সরবরাহকারীর অগ্রিম নেই।');
        $this->assertSame($before['agrees'], $sheet['agrees'], '⛔ অগ্রিম আলাদা করায় স্থিতিপত্র আর মেলে না।');

        $this->get(route('accounts.balance_sheet'))->assertOk()->assertSee(__('accounts::field.customer_advance'))->assertSee(__('accounts::field.supplier_advance'));
    }

    /**
     * ⛔ আগের বছর বন্ধ না হলে তার লাভ হারাত — অডিট গ১১, ৪ অক্টোবর ২০২৬।
     * আগে চলতি ফল গোনা হত কেবল এই বছরের শুরু থেকে; আগের খোলা বছরের ৭০০ টাকার লাভ কোথাও আসত না, আর
     * স্থিতিপত্র ঠিক ৭০০-তে "মেলে না" দেখাত।
     */
    public function test_an_unclosed_earlier_years_profit_is_not_lost(): void
    {
        $current = \App\Models\FinancialYear::query()->where('company_id', $this->company->id)
            ->where('starts_on', '<=', now()->toDateString())->where('ends_on', '>=', now()->toDateString())->firstOrFail();
        $lastDay = $current->starts_on->copy()->subDay();

        \App\Models\FinancialYear::query()->firstOrCreate(
            ['company_id' => $this->company->id, 'starts_on' => $lastDay->copy()->subYear()->addDay()->toDateString()],
            ['name' => 'আগের বছর', 'ends_on' => $lastDay->toDateString(), 'is_closed' => false, 'is_current' => false],
        );

        $before = app(BalanceSheetService::class)->build();
        $this->assertTrue($before['agrees'], 'প্রস্তুতি ভুল — শুরুতেই স্থিতিপত্র মেলে না: '.$before['difference']);

        $cash = (int) DB::table('accounts')->where('company_id', $this->company->id)->where('money_kind', 'cash')->where('is_group', false)->orderBy('id')->value('id');
        $income = (int) DB::table('accounts')->where('company_id', $this->company->id)->where('is_group', false)->where('type', 'income')->orderBy('id')->value('id');

        app(PostingEngine::class)->post(sourceType: 'test_last_year', sourceId: 7801, trxDate: $lastDay->toDateString(),
            lines: [['account_id' => $cash, 'debit' => '700'], ['account_id' => $income, 'credit' => '700']],
            branchId: $this->company->defaultBranch()?->id);

        $sheet = app(BalanceSheetService::class)->build();

        $this->assertTrue($sheet['agrees'], '⛔ আগের খোলা বছরের লাভ হারিয়েছে — তফাত '.$sheet['difference']);
        $this->assertSame(0, bccomp(bcsub((string) $sheet['profit'], (string) $before['profit'], 4), '700', 4),
            '⛔ চলতি ফলে আগের বছরের ৭০০ আসেনি।');
    }

    // ── সহায়ক ───────────────────────────────────────────────────────────

    private function book(int $n, array $lines): void
    {
        app(PostingEngine::class)->post(sourceType: 'test_advance', sourceId: 7700 + $n, trxDate: now()->toDateString(), lines: $lines,
            branchId: $this->company->defaultBranch()?->id);
    }

    /** @param  list<array<string, mixed>>  $side */
    private function lineOf(array $side, string $code): string
    {
        foreach ($side as $group) {
            foreach ($group['lines'] as $line) {
                if (! isset($line['label']) && $line['account']->code === $code) {
                    return (string) $line['amount'];
                }
            }
        }

        return '0';
    }

    /** @param  list<array<string, mixed>>  $side */
    private function advance(array $side, string $label): string
    {
        foreach ($side as $group) {
            foreach ($group['lines'] as $line) {
                if (($line['label'] ?? null) === $label) {
                    return (string) $line['amount'];
                }
            }
        }

        return '0';
    }
}
