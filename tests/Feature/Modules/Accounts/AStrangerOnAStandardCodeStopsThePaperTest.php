<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Engines\Coding\CodeSuggester;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Integrity\AccountsChecks;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\AccountService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\Deposit;
use App\Modules\Finance\Models\DepositKind;
use App\Modules\Finance\Services\DepositKindInstaller;
use App\Modules\Finance\Services\DepositService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * ⛔ মান ছকের কোডে অন্য খাত বসে থাকলে কাগজ থামে, ভুল খাতে বসে না — fe-র P1, P2, P4 (২৩ অক্টোবর ২০২৬)।
 *
 * <p>লাইভে UB-তে ছক আসার আগেই ১১৩৫ ("Depot Bank A/C") আর ১১৫১ ("Star Line Accounts")-এ ব্যাংক খোলা হয়েছিল। ছক থাকা কোড ছোঁয়
 * না, তাই কোড ধরে খোঁজা প্রতিটা পথ ব্যাংকটাই পেত: ড্যামেজ দাবির চারটা নোট ব্যাংকে Dr হয়েছে, আর ডিপোজিটের উৎসে কর একই পথে
 * Depot Bank-এ Dr হত। এখন: [[StandardChart::find()]] এমন খাত দেয় না, [[StandardChart::accountFor()]] বাংলা বার্তায় থামে,
 * পর্দা বা কোড-সাজানো মান ছকের কোড অন্য খাতকে দেয় না ([[AccountService]], [[CodeSuggester::isFree()]]), আর খাতার পরখ জানায়
 * ([[AccountsChecks::everyStandardCodeHoldsItsAccount()]])।
 */
final class AStrangerOnAStandardCodeStopsThePaperTest extends TestCase
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
        app(DepositKindInstaller::class)->install();
    }

    /** ⭐ UB-র ১১৩৫ হুবহু: ব্যাংকটা "অগ্রিম আয়কর" হিসেবে আর পাওয়া যায় না; আসল খাত আর কোম্পানির নিজের কোড আগের মতো */
    public function test_find_hides_a_bank_on_the_tax_code_and_keeps_every_true_account(): void
    {
        $receivable = StandardChart::find(StandardChart::RECEIVABLE);
        $this->assertNotNull($receivable, 'প্রস্তুতিটাই ভুল — ডেমোতে প্রাপ্য নেই');
        $own = Account::query()->get()->first(fn (Account $a) => ! StandardChart::isStandardCode((string) $a->code));
        $this->assertNotNull($own, 'প্রস্তুতিটাই ভুল — ডেমোতে ছকের বাইরের কোনো খাত নেই');

        $bank = $this->bankOn(StandardChart::ADVANCE_INCOME_TAX);

        $this->assertNull(StandardChart::find(StandardChart::ADVANCE_INCOME_TAX),
            '⛔ অগ্রিম আয়করের কোডে বসা ব্যাংক কোড ধরে খোঁজায় "অগ্রিম আয়কর" হয়ে এল (লাইভের UB 1135)');
        $this->assertSame((int) $receivable->id, (int) StandardChart::find(StandardChart::RECEIVABLE)?->id, '⛔ আসল প্রাপ্যও লুকাল');
        $this->assertSame((int) $own->id, (int) StandardChart::find((string) $own->code)?->id, '⛔ কোম্পানির নিজের কোডের খাত লুকাল');
        $this->assertNotNull(Account::query()->find($bank), 'প্রস্তুতিটাই ভুল — ব্যাংকটা নিজেই হারাল');
    }

    /** ⛔ লেখার পথের খাত: অন্য খাত বা দল পেলে বাংলায় থামা, কোড আর ছকের নাম সহ */
    public function test_account_for_stops_with_the_reason_in_bengali(): void
    {
        $this->bankOn(StandardChart::ADVANCE_INCOME_TAX);
        app()->setLocale('bn');

        $message = $this->refusal(fn () => StandardChart::accountFor(StandardChart::ADVANCE_INCOME_TAX));
        $this->assertNotNull($message, '⛔ অগ্রিম আয়করের কোডে বসা ব্যাংক লেখার পথের খাত হয়ে গেল');
        $this->assertStringContainsString('অগ্রিম আয়কর', (string) $message, '⛔ বার্তায় ছকের খাতের নাম নেই');
        $this->assertStringContainsString('(1135)', (string) $message, '⛔ বার্তায় কোড নেই');

        $this->assertNotNull($this->refusal(fn () => StandardChart::accountFor(StandardChart::BANK)), '⛔ ব্যাংকের দলটাই লেখার খাত হয়ে গেল');
        $this->assertSame((int) StandardChart::find(StandardChart::RECEIVABLE)?->id, (int) StandardChart::accountFor(StandardChart::RECEIVABLE)->id,
            'প্রস্তুতিটাই ভুল — আসল প্রাপ্যও থামল');
    }

    /** ⭐ লাইভের খোলা দরজা: ডিপোজিট ভাঙলে উৎসে কর আর Depot Bank-এ Dr হয় না — কাগজটাই থামে, কিছুই বসে না */
    public function test_the_deposit_tax_never_lands_on_the_bank(): void
    {
        $this->putMoneyIn($this->cash(), '1000000', '2026-07-01');
        $fdr = $this->open('100000');
        $plain = $this->open('50000');
        $bank = $this->bankOn(StandardChart::ADVANCE_INCOME_TAX);

        $this->assertNotNull($this->refusal(fn () => app(DepositService::class)->close($fdr, $this->money('104000', '500'))),
            '⛔ উৎসে কর অগ্রিম আয়করের কোডে বসা ব্যাংকে Dr হল (লাইভের UB 1135)');
        $this->assertSame(Deposit::ACTIVE, $fdr->fresh()->status, '⛔ থামার পরও জমাটা বন্ধ হয়ে গেল');
        $this->assertSame(0, LedgerEntry::query()->where('account_id', $bank)->count(), '⛔ ব্যাংকের খাতায় কিছু বসে গেল');

        // ⓘ কর না থাকলে খাতটা লাগেই না — কাগজ আগের মতো চলে
        app(DepositService::class)->close($plain, $this->money('52000'));
        $this->assertSame(Deposit::CLOSED, $plain->fresh()->status, '⛔ কর ছাড়া জমাও থেমে গেল — যে খাত লাগে না তার জন্য থামা');
    }

    /** ⛔ পর্দা আর কোড-সাজানো: মান ছকের কোড কোনো নতুন খাত পায় না; ভুল খাতকে নতুন কোডে সরানো যায় */
    public function test_no_new_account_takes_a_standard_code_and_a_stranger_can_move_away(): void
    {
        $accounts = app(AccountService::class);
        $bankGroup = StandardChart::find(StandardChart::BANK);
        $tax = StandardChart::find(StandardChart::ADVANCE_INCOME_TAX);
        DB::table((new Account)->getTable())->where('id', $tax->id)->delete();

        $this->assertNotNull($this->refusal(fn () => $accounts->create([
            'code' => StandardChart::ADVANCE_INCOME_TAX, 'name_en' => 'Depot Bank A/C', 'name_bn' => 'ডিপো ব্যাংক', 'parent_id' => $bankGroup->id,
        ]), 'code'), '⛔ ছকে না-বসা অগ্রিম আয়করের কোডে নতুন ব্যাংক খোলা গেল (লাইভের UB 1135)');
        $this->assertNotNull($this->refusal(fn () => $accounts->create([
            'code' => StandardChart::ADVANCE_INCOME_TAX, 'name_en' => 'Office deposit', 'name_bn' => 'অফিসের জামানত', 'parent_id' => StandardChart::find('1100')->id,
        ]), 'code'), '⛔ একই ধরনের অন্য খাত অগ্রিম আয়করের কোড নিল');
        $this->assertFalse(app(CodeSuggester::class)->isFree(Account::class, StandardChart::ADVANCE_INCOME_TAX),
            '⛔ কোড সাজানো ছকে না-বসা অগ্রিম আয়করের কোডকে খালি বলল');

        $bank = $accounts->create(['name_en' => 'Depot Bank A/C', 'name_bn' => 'ডিপো ব্যাংক', 'parent_id' => $bankGroup->id]);
        $this->assertFalse(StandardChart::isStandardCode((string) $bank->code), '⛔ নতুন ব্যাংক মান ছকের কোড পেল: '.$bank->code);
        // ⓘ ১১৩৫ এখানে কোম্পানিতে নেই (উপরে মোছা), তাই "কোড নেওয়া" বলে থামার উপায় নেই — থামলে মান ছকের কারণেই
        $this->assertNotNull($this->refusal(fn () => $accounts->update($bank, ['code' => StandardChart::ADVANCE_INCOME_TAX]), 'code'),
            '⛔ ব্যাংককে ছকে না-বসা অগ্রিম আয়করের কোডে সরানো গেল');

        // ⓘ লাইভের সারাইয়ের পথ: ভুল খাতটা নতুন কোডে — id একই, খাতার সারি নড়ে না
        $stranger = $this->bankOn(StandardChart::DAMAGE_CLAIM);
        $free = $this->freeCode();
        $accounts->update(Account::query()->findOrFail($stranger), ['code' => $free]);
        $this->assertSame($free, (string) Account::query()->findOrFail($stranger)->code, '⛔ ভুল খাতটাকে নতুন কোডে সরানো গেল না');
    }

    /** ⭐ খাতার পরখ: পরিষ্কার ছকে কিছু নেই; ব্যাংক বসলে কোড আর খাতটা দেখায় */
    public function test_the_books_check_names_the_stranger(): void
    {
        $this->assertSame([], AccountsChecks::everyStandardCodeHoldsItsAccount()->run(), 'প্রস্তুতিটাই ভুল — ডেমোর ছকেই অমিল');

        $this->bankOn(StandardChart::DAMAGE_CLAIM);
        $found = AccountsChecks::everyStandardCodeHoldsItsAccount()->run();

        $this->assertCount(1, $found, '⛔ খাতার পরখ ড্যামেজ দাবির কোডে বসা ব্যাংক দেখল না');
        $this->assertStringContainsString('1151', $found[0]->what, '⛔ পরখ কোডটা বলল না');
    }

    /** ⛔ ঋণের ফর্মে মূল টাকার খাতের তালিকা আর ফাঁকা নয় — দীর্ঘমেয়াদি দায়ের দলটা এখন দল হিসেবেই খোঁজা */
    public function test_the_loan_form_lists_the_long_term_liabilities(): void
    {
        $termLoan = Account::query()->where('code', '2210')->firstOrFail();

        $page = $this->get(route('accounts.loan.create'))->assertOk();
        $this->assertStringContainsString('value="'.$termLoan->id.'"', (string) $page->getContent(),
            '⛔ ঋণের ফর্মে মূল টাকার খাতের তালিকা ফাঁকা — দীর্ঘমেয়াদি দায়ের দলটা দল বলে খোঁজায় পাওয়া যায়নি');
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    /** কোডের খাতটাকে UB-র মতো ব্যাংক বানানো — ব্যাংকের দলের নিচে, টাকার ধরন ব্যাংক */
    private function bankOn(string $code): int
    {
        $account = StandardChart::find($code);
        $this->assertNotNull($account, 'প্রস্তুতিটাই ভুল — ডেমোতে '.$code.' নেই');

        DB::table((new Account)->getTable())->where('id', $account->id)->update([
            // ⓘ লাইভের খাতটা পর্দা থেকে খোলা, ছকের নয় — তাই সিস্টেমের খাতও নয়
            'name_en' => 'Depot Bank A/C', 'money_kind' => Account::BANK, 'is_system' => false,
            'parent_id' => (int) StandardChart::find(StandardChart::BANK)->id,
        ]);

        return (int) $account->id;
    }

    /** থামলে বার্তাটা, না থামলে null */
    private function refusal(callable $act, string $key = 'account'): ?string
    {
        try {
            $act();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($key, $e->errors(), 'প্রস্তুতিটাই ভুল — অন্য কারণে থামল: '.json_encode($e->errors(), JSON_UNESCAPED_UNICODE));

            return (string) $e->errors()[$key][0];
        }

        return null;
    }

    private function freeCode(): string
    {
        for ($n = 1190; $n < 1200; $n++) {
            if (! StandardChart::isStandardCode((string) $n) && ! Account::query()->withTrashed()->where('code', (string) $n)->exists()) {
                return (string) $n;
            }
        }

        $this->fail('প্রস্তুতিটাই ভুল — খালি কোড নেই');
    }

    private function cash(): Account
    {
        return Account::query()->money()->postable()->active()->where('money_kind', Account::CASH)->orderBy('id')->firstOrFail();
    }

    private function open(string $amount): Deposit
    {
        return app(DepositService::class)->open([
            'kind_id' => DepositKind::query()->where('code', 'FDR')->value('id'),
            'institution' => 'সোনালী ব্যাংক', 'held_by' => Deposit::BUSINESS, 'principal' => $amount, 'profit_rate' => '8',
            'return_word' => 'interest', 'opened_on' => '2026-07-02',
            'matures_on' => now()->addDays(65)->toDateString(), 'funded_from_account_id' => $this->cash()->id,
            'payout_account_id' => $this->cash()->id,
        ]);
    }

    /** @return array<string, string> */
    private function money(string $received, ?string $sourceTax = null): array
    {
        return array_filter([
            'amount' => $received, 'moved_on' => now()->toDateString(), 'money_account_id' => $this->cash()->id,
            'source_tax' => $sourceTax,
            /*
             * ⓘ করের সারি ব্যাংকে পড়লে লেনদেন নম্বর লাগে — দিয়ে রাখা, যাতে কাগজ থামলে থামে কেবল মান ছকের নিয়মে। লাইভে কেউ নম্বর
             * দিলে টাকা ব্যাংকেই বসত; নম্বর না দিলে অন্য পাহারা থামাত আর এই দাবি কিছুই মাপত না (Mac, CH3, ২৫ অক্টোবর ২০২৬)।
             */
            'instrument_no' => $sourceTax === null ? null : 'TX-STRANGER-1',
        ], fn ($v) => $v !== null);
    }
}
