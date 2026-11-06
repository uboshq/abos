<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\AmountInWords;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\AccountService;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ খরচ, কন্ট্রা আর জাবেদায়ও হিসাবের সারাংশ — মালিক, ৬ নভেম্বর ২০২৬: "ভাউচারের কাজ korar somoy হিসাবের সারাংশ ase na eta deko"
 * (রসিদ আর পরিশোধে ছিল, এই তিনটায় একদমই ছিল না); ৭ নভেম্বর: "baki kaj gulo kore rako"।
 *
 * দাবি, আসল দরজা দিয়ে:
 *  - তিন পর্দাতেই সারাংশের কার্ড, খাতার আগাম ছবির ঠিকানাসহ ([[partials/preview-summary]]).
 *  - কন্ট্রা আর জাবেদায় আগাম ছবি আর ৪০৪ নয় — খাতার সারি, ডেবিট = ক্রেডিট, আর টাকা কথায় (জাবেদায় মোট ডেবিটের).
 *  - খরচে আগের মতোই সারি আর কথা।
 */
final class EveryVoucherFormShowsItsSummaryTest extends TestCase
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

    public function test_the_expense_contra_and_journal_screens_carry_the_summary_card(): void
    {
        foreach ([Voucher::EXPENSE, Voucher::CONTRA, Voucher::JOURNAL] as $type) {
            $html = (string) $this->get(route('accounts.voucher.create', ['type' => $type]))->assertOk()->getContent();

            $this->assertStringContainsString('data-voucher-preview-summary', $html, "⛔ {$type}: হিসাবের সারাংশের কার্ড নেই — মালিকের প্রশ্ন ফিরে আসবে।");
            $this->assertMatchesRegularExpression('/data-voucher-preview-summary\s+x-data="voucherPreview\(\{ url: /', $html,
                "⛔ {$type}: সারাংশ খাতার আগাম ছবি চায় না — পর্দা নিজে গুনলে খাতার সাথে আলাদা হতে পারে।");
            $this->assertStringContainsString('data-preview-words', $html, "⛔ {$type}: টাকা কথায় লেখার জায়গা নেই।");
        }
    }

    public function test_a_contra_preview_shows_both_accounts_balanced_and_in_words(): void
    {
        [$cash, $bank] = [$this->cash(), $this->bank()];

        $answer = $this->postJson(route('accounts.voucher.simulate'), [
            'type' => Voucher::CONTRA, 'trx_date' => now()->toDateString(), 'amount' => '2500', 'narration' => 'সারাংশের দাবি',
            'from_account_id' => $cash->id, 'to_account_id' => $bank->id, 'instrument' => 'deposit', 'instrument_no' => 'SUM-C-1',
        ])->assertOk()->json();

        $this->assertNotEmpty($answer['rows'] ?? [], '⛔ কন্ট্রার আগাম ছবিতে খাতার সারি নেই: '.json_encode($answer, JSON_UNESCAPED_UNICODE));
        $this->assertTrue((bool) ($answer['balanced'] ?? false), '⛔ কন্ট্রার আগাম ছবিতে ডেবিট ≠ ক্রেডিট।');
        $this->assertSame(AmountInWords::of('2500', app()->getLocale()), $answer['summary']['words'] ?? null, '⛔ কন্ট্রার সারাংশে টাকা কথায় নেই, বা ভুল।');
    }

    public function test_a_journal_preview_shows_its_lines_and_the_debit_total_in_words(): void
    {
        [$first, $second] = Account::query()->postable()->active()->whereNull('money_kind')->orderBy('code')->limit(2)->get()->all();

        $answer = $this->postJson(route('accounts.voucher.simulate'), [
            'type' => Voucher::JOURNAL, 'trx_date' => now()->toDateString(), 'narration' => 'সারাংশের দাবি',
            'lines' => [
                ['account_id' => $first->id, 'debit' => '1200', 'credit' => '0'],
                ['account_id' => $second->id, 'debit' => '0', 'credit' => '1200'],
            ],
        ])->assertOk()->json();

        $this->assertCount(2, $answer['rows'] ?? [], '⛔ জাবেদার আগাম ছবিতে দুই সারি নেই: '.json_encode($answer, JSON_UNESCAPED_UNICODE));
        $this->assertTrue((bool) ($answer['balanced'] ?? false), '⛔ জাবেদার আগাম ছবিতে ডেবিট ≠ ক্রেডিট।');
        $this->assertSame(AmountInWords::of('1200', app()->getLocale()), $answer['summary']['words'] ?? null, '⛔ জাবেদার সারাংশে মোট ডেবিট কথায় নেই।');
    }

    public function test_an_expense_preview_still_shows_its_lines_and_words(): void
    {
        // ⓘ মানুষের খরচের খাত — বিক্রিত মালের খরচ (৫১০০) বা অবচয়ের মতো কেবল-সফটওয়্যারের খাত নয় ([[StandardChart::isEngineOnlyExpense()]]; Mac, ৭ নভেম্বর ২০২৬)
        $head = Account::query()->postable()->active()->where('code', 'like', '5%')->whereNull('money_kind')->orderBy('code')->get()
            ->first(fn (Account $a) => ! app(StandardChart::class)->isEngineOnlyExpense($a));
        $this->assertNotNull($head, 'প্রস্তুতিটাই ভুল — ডেমোতে মানুষের কোনো খরচের খাত নেই।');

        $answer = $this->postJson(route('accounts.voucher.simulate'), [
            'type' => Voucher::EXPENSE, 'trx_date' => now()->toDateString(), 'amount' => '1500', 'narration' => 'সারাংশের দাবি',
            'from_account_id' => $this->cash()->id, 'to_account_id' => $head->id, 'expense_account_id' => $head->id, 'instrument' => 'cash',
        ])->assertOk()->json();

        $this->assertNotEmpty($answer['rows'] ?? [], '⛔ খরচের আগাম ছবিতে খাতার সারি নেই: '.json_encode($answer, JSON_UNESCAPED_UNICODE));
        $this->assertSame(AmountInWords::of('1500', app()->getLocale()), $answer['summary']['words'] ?? null, '⛔ খরচের সারাংশে টাকা কথায় নেই।');
    }

    private function cash(): Account
    {
        return Account::query()->where('money_kind', Account::CASH)->postable()->active()->orderBy('code')->firstOrFail();
    }

    private function bank(): Account
    {
        return Account::query()->where('money_kind', Account::BANK)->postable()->active()->orderBy('code')->first()
            ?? app(AccountService::class)->create([
                'code' => StandardChart::BANK.'-01',
                'name_en' => 'Test bank account',
                'parent_id' => Account::query()->where('code', StandardChart::BANK)->value('id'),
            ]);
    }
}
