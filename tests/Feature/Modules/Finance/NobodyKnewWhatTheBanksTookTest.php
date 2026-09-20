<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\AccountService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Finance\Services\BankCharges;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ব্যাংকগুলো কত কাটল, কেউ জানত না — মানচিত্র §৯ "ব্যাংক চার্জ"।
 *
 * ⭐ পাতাটা খতিয়ানের ৫২১০/৫২১১ পড়ে, আর প্রতিটা চার্জ একই কাগজের
 * ব্যাংক/MFS সারি দেখে ব্যাংকের নামে বসায়। এই ফাইল দেখে: ব্যাংক ধরে
 * মোট ঠিক, সময়ের বাইরের চার্জ বাদ, চার্জ ফেরত এলে কমে, আর বাতিল
 * কাগজের চার্জ আর গোনা হয় না।
 */
final class NobodyKnewWhatTheBanksTookTest extends TestCase
{
    use RefreshDatabase;

    private Account $bank;

    private Account $bkash;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();

        $this->bank = $this->money('Charge Test Bank CD', StandardChart::BANK);
        $this->bkash = $this->money('Charge Test bKash', StandardChart::MOBILE_MONEY);
    }

    public function test_each_charge_lands_on_its_own_bank_and_the_page_shows_the_totals(): void
    {
        $this->charged($this->bank, StandardChart::BANK_CHARGES, '25', now()->toDateString());
        $this->charged($this->bank, StandardChart::BANK_CHARGES, '15', now()->toDateString());
        $this->charged($this->bkash, StandardChart::MFS_CHARGES, '18.50', now()->toDateString());

        $charges = app(BankCharges::class);
        $byBank = $charges->byBank(now()->startOfMonth()->toDateString(), now()->toDateString())
            ->keyBy(fn ($b) => $b['bank']?->id);

        $this->assertSame(0, bccomp($byBank[$this->bank->id]['amount'], '40', 2), 'ব্যাংকের দুইটা চার্জ এক জায়গায় জমেনি।');
        $this->assertSame(2, $byBank[$this->bank->id]['count']);
        $this->assertSame(0, bccomp($byBank[$this->bkash->id]['amount'], '18.50', 2), 'বিকাশের চার্জ বিকাশে বসেনি।');

        $page = $this->get(route('finance.bank_charge.index'))->assertOk();
        $page->assertSee('Charge Test Bank CD');
        $page->assertSee('Charge Test bKash');
        $page->assertSee(route('finance.bank_charge.index', ['period' => 'last_month']), escape: false);
    }

    public function test_a_charge_outside_the_period_or_on_a_cancelled_voucher_is_not_counted(): void
    {
        $this->charged($this->bank, StandardChart::BANK_CHARGES, '30', now()->subMonthNoOverflow()->startOfMonth()->toDateString());
        $gone = $this->charged($this->bank, StandardChart::BANK_CHARGES, '12', now()->toDateString());
        $this->charged($this->bank, StandardChart::BANK_CHARGES, '7', now()->toDateString());

        app(VoucherService::class)->cancel($gone->fresh(), 'ভুল চার্জ');

        /*
         * ⓘ নিজের ব্যাংকের ঘরটাই মাপা, সব ব্যাংকের মোট নয় — ডেমো ডেটাতেও
         * এই মাসে চার্জ থাকতে পারে, আর তখন সংখ্যাটা ডেমোর উপর নির্ভর করত।
         */
        $mine = app(BankCharges::class)
            ->byBank(now()->startOfMonth()->toDateString(), now()->toDateString())
            ->firstWhere(fn ($b) => $b['bank']?->id === $this->bank->id);

        $this->assertSame(0, bccomp($mine['amount'], '7', 2),
            "মোট {$mine['amount']} — পুরনো বা বাতিল কাগজের চার্জও গোনা হচ্ছে।");
    }

    public function test_a_custom_date_range_is_honoured(): void
    {
        $day = now()->subMonthNoOverflow()->startOfMonth()->addDays(4)->toDateString();
        $this->charged($this->bank, StandardChart::BANK_CHARGES, '55', $day);

        $this->get(route('finance.bank_charge.index', ['period' => 'custom', 'from' => $day, 'to' => $day]))
            ->assertOk()
            ->assertSee('Charge Test Bank CD');

        $this->get(route('finance.bank_charge.index'))->assertOk()->assertDontSee('Charge Test Bank CD');
    }

    /** টাকা এল ১০০০, ব্যাংক কাটল $charge — রসিদের "চার্জ" ঘরের মতোই তিন সারি */
    private function charged(Account $into, string $chargeCode, string $charge, string $date): Voucher
    {
        $vouchers = app(VoucherService::class);
        $income = Account::query()->where('code', StandardChart::OWNER_CAPITAL)->firstOrFail();
        $chargeHead = Account::query()->where('code', $chargeCode)->firstOrFail();

        /*
         * ⓘ ব্যাংক বা MFS-এর খাত ছুঁলে লেনদেন নম্বর লাগে
         * ([[VoucherService::assertBankReferenceIsFree]]) — একই লেনদেন
         * দুইবার খাতায় ওঠা ঠেকায়। তাই প্রতিটা সারিকে নিজের নম্বর।
         */
        $voucher = $vouchers->create([
            'type' => Voucher::JOURNAL,
            'trx_date' => $date,
            'narration' => 'charge test',
            'instrument_no' => 'CHG-'.fake()->unique()->numberBetween(100000, 999999),
        ], [
            ['account_id' => $into->id, 'debit' => bcsub('1000', $charge, 2), 'credit' => '0'],
            ['account_id' => $chargeHead->id, 'debit' => $charge, 'credit' => '0'],
            ['account_id' => $income->id, 'debit' => '0', 'credit' => '1000'],
        ]);

        return $vouchers->post($voucher);
    }

    private function money(string $name, string $parentCode): Account
    {
        return app(AccountService::class)->create([
            'code' => $parentCode.'-'.fake()->unique()->numberBetween(10, 99),
            'name_en' => $name,
            'parent_id' => Account::query()->where('code', $parentCode)->value('id'),
        ]);
    }
}
