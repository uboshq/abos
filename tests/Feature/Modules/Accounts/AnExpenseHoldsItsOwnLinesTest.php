<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Models\VoucherExpenseLine;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ⭐ খরচের ভাউচার ১০০%, ভাগ B — তথ্যের নকশা (fe অনুমোদিত §১, ২৪ অক্টোবর ২০২৬; expense-100-schema-section-1.md)।
 *
 * দাবি:
 *   ১. একটা খরচে কয়েকটা ব্যবসার লাইন — নিজের কোম্পানিতে, নিজের public_id-সহ, `sort` ধরে ফেরে; লাইনের মোট bcmath-এ
 *   ২. মাথার নতুন ঘর — তারিখ তারিখ হয়ে, হার ছয় দশমিকে, বাজেটের ছবি আর সতর্কতা অ্যারে হয়ে ফেরে
 *   ৩. খাত 1139 "সমন্বয়যোগ্য ক্রয়-ভ্যাট" মান ছকে বসে, আর নিজের মানে ধরে (সম্পদ, টাকার খাত নয়)
 *   ৪. খাতের `capex_hint` — ডিফল্ট না, বসানো যায়
 *   ৫. পাঁচটা সেটিংয়ের ডিফল্ট fe-র অনুমোদিত মান
 */
final class AnExpenseHoldsItsOwnLinesTest extends TestCase
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

    /** ১ — কয়েকটা লাইন */
    public function test_an_expense_keeps_several_lines_in_their_order(): void
    {
        $voucher = $this->expense();
        $fuel = StandardChart::find(StandardChart::FUEL);
        $rent = StandardChart::find(StandardChart::RENT);

        // ⓘ উল্টো ক্রমে লেখা — ফেরার ক্রম `sort` ধরে কি না সেটাই মাপা
        VoucherExpenseLine::query()->create(['voucher_id' => $voucher->id, 'sort' => 2, 'expense_account_id' => $rent->id, 'amount' => '30000', 'vat_amount' => '4500']);
        VoucherExpenseLine::query()->create(['voucher_id' => $voucher->id, 'sort' => 1, 'expense_account_id' => $fuel->id, 'amount' => '1200.50', 'vat_amount' => '0.25', 'vat_recoverable' => true]);

        $lines = $voucher->fresh()->expenseLines;

        $this->assertSame([(int) $fuel->id, (int) $rent->id], $lines->pluck('expense_account_id')->map(fn ($id) => (int) $id)->all(),
            '⛔ খরচের লাইন নিজের ক্রমে ফেরে না — পর্দা আর ছাপায় সারি উল্টে যেত');
        $this->assertSame('1200.7500', $lines[0]->total(), '⛔ লাইনের মোট (মূল্য + ভ্যাট) পয়সায় মেলে না');
        $this->assertTrue($lines[0]->vat_recoverable, '⛔ "ভ্যাট সমন্বয়যোগ্য" চিহ্ন হারাল — ভ্যাট 1139-এ যেত না');
        $this->assertSame((int) $this->company->id, (int) $lines[0]->company_id, '⛔ লাইন নিজের কোম্পানিতে বসে না');
        $this->assertNotEmpty($lines[0]->public_id, '⛔ লাইনের public_id নেই — বাইরে id দেখাতে হত');
    }

    /** ২ — মাথার নতুন ঘর */
    public function test_the_new_head_fields_come_back_in_their_own_shape(): void
    {
        $voucher = $this->expense();
        // ⓘ সংরক্ষণটাই ভাঙলে (বাজেটের ঘর অ্যারে না হলে PHP "Array to string") সেটাও দাবির নিজের কথায় — ভুলের বার্তায় নয়
        //   (fe, ৩১ অক্টোবর ২০২৬: cb-র exb_budget_not_array কেবল ErrorException-এ মরছিল)
        try {
            $voucher->forceFill([
                'bill_date' => '2026-10-20', 'due_date' => '2026-11-19',
                'fx_rate' => '121.123456', 'fx_date' => '2026-10-24', 'fx_rate_reason' => 'ব্যাংকের হার',
                'budget_reason' => 'জরুরি মেরামত', 'budget_check' => [['account' => 5216, 'pct' => 112.5]], 'warnings' => ['capex'],
            ])->save();
        } catch (\ErrorException|\Illuminate\Database\QueryException $e) {
            $this->fail('⛔ বাজেটের ছবি অ্যারে হয়ে ফেরে না — নিরীক্ষা পড়তে পারত না (সংরক্ষণই ভাঙল: '.$e->getMessage().')');
        }

        $fresh = $voucher->fresh();

        $this->assertSame('2026-11-19', $fresh->due_date?->toDateString(), '⛔ শেষ দিন তারিখ হয়ে ফেরে না — বাকির বয়স গোনা যেত না');
        $this->assertSame('121.123456', (string) $fresh->fx_rate, '⛔ হার ছয় দশমিকে ফেরে না — ৳-এর অঙ্ক পয়সায় সরত');
        // ⓘ চাবির ক্রম মাপা হয় না — MySQL-এর JSON কলাম চাবি নিজের ক্রমে রাখে (main-এ ধরা: 'pct' আগে); মান আর ধরন হুবহু
        $budget = array_map(function (array $row): array {
            ksort($row);

            return $row;
        }, (array) $fresh->budget_check);
        $this->assertSame([['account' => 5216, 'pct' => 112.5]], $budget, '⛔ বাজেটের ছবি অ্যারে হয়ে ফেরে না — নিরীক্ষা পড়তে পারত না');
        $this->assertSame(['capex'], $fresh->warnings, '⛔ সতর্কতা অ্যারে হয়ে ফেরে না');
    }

    /** ৩ — খাত 1139 */
    public function test_input_vat_recoverable_is_in_the_standard_chart_and_holds_its_meaning(): void
    {
        $account = StandardChart::find(StandardChart::INPUT_VAT_RECOVERABLE);

        $this->assertNotNull($account, '⛔ মান ছকে 1139 "সমন্বয়যোগ্য ক্রয়-ভ্যাট" বসে না — দাবি-করা-যায় ভ্যাট আবার দায়ের খাতে যেত');
        $this->assertSame(Account::ASSET, $account->type, '⛔ 1139 সম্পদ নয় — দাবি-করা-যায় ভ্যাট সম্পদ (ফেরত পাওয়ার অধিকার)');
        $this->assertTrue(app(StandardChart::class)->holdsItsMeaning($account), '⛔ 1139 নিজের মানে ধরে না');
    }

    /** ৪ — capex_hint */
    public function test_an_account_can_say_it_may_buy_assets(): void
    {
        $fuel = StandardChart::find(StandardChart::FUEL);
        $this->assertFalse((bool) $fuel->capex_hint, '⛔ নতুন খাত আপনা থেকে "সম্পদ হতে পারে" বলে');

        // ⓘ ফর্মের পথ (mass assignment) বন্ধ থাকলে সেটাও দাবির নিজের কথায় (fe, ৩১ অক্টোবর ২০২৬: exb_capex_not_fillable ভুলের বার্তায় মরছিল)
        try {
            $fuel->update(['capex_hint' => true]);
        } catch (\Illuminate\Database\Eloquent\MassAssignmentException $e) {
            $this->fail('⛔ খাতে "সম্পদ হতে পারে" চিহ্ন বসানো যায় না — ফর্ম থেকে ঘরটা নেওয়াই হয় না: '.$e->getMessage());
        }
        $this->assertTrue($fuel->fresh()->capex_hint, '⛔ খাতে "সম্পদ হতে পারে" চিহ্ন বসানো যায় না');
    }

    /** ৫ — সেটিং */
    public function test_the_expense_settings_start_at_the_approved_values(): void
    {
        $settings = app(SettingsService::class);

        foreach ([
            'accounts.expense.attachment_over' => 5000,
            'accounts.expense.budget_sign_over_pct' => 10,
            'accounts.expense.budget_reason_over' => true,
            'accounts.expense.employee_refund' => 'separate_voucher',
            'accounts.expense.capitalise_warn_over' => 50000,
        ] as $key => $expected) {
            $this->assertEquals($expected, $settings->get($key), "⛔ {$key}-এর শুরুর মান fe-র অনুমোদিত মান নয়");
        }
    }

    private function expense(): Voucher
    {
        $cash = Account::query()->where('money_kind', Account::CASH)->postable()->active()->orderBy('code')->firstOrFail();

        return app(VoucherService::class)->create(
            ['type' => Voucher::EXPENSE, 'trx_date' => now()->toDateString(), 'narration' => 'কয়েক লাইনের খরচ'],
            [['account_id' => StandardChart::find(StandardChart::FUEL)->id, 'debit' => '100'], ['account_id' => $cash->id, 'credit' => '100']],
        );
    }
}
