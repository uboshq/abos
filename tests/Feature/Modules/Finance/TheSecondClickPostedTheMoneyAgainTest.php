<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\CapitalEntry;
use App\Modules\Finance\Models\Deposit;
use App\Modules\Finance\Models\DepositKind;
use App\Modules\Finance\Models\DepositMovement;
use App\Modules\Finance\Models\RentalContract;
use App\Modules\Finance\Models\Withdrawal;
use App\Modules\Finance\Services\CapitalService;
use App\Modules\Finance\Services\DepositKindInstaller;
use App\Modules\Finance\Services\DepositService;
use App\Modules\Finance\Services\RentalContractService;
use App\Modules\Finance\Services\WithdrawalService;
use App\Modules\MasterData\Models\Person;
use Database\Seeders\DemoSeeder;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * দ্বিতীয় ক্লিক টাকাটা আবার বসাত — চূড়ান্ত অডিট ⛔১১, ৩০ সেপ্টেম্বর ২০২৬।
 *
 * ── ⛔ কী ছিল ─────────────────────────────────────────────────────────
 * Finance-এর টাকার কাজগুলো (মূলধন পোস্ট, জমা বন্ধ ও মুনাফা তোলা, ভাড়ার চুক্তি বন্ধ ও মাসের
 * সমন্বয়, উত্তোলন পোস্ট) কাগজের অবস্থা দেখত **হাতের কপি** থেকে, লেনদেনের বাইরে, তালা
 * ছাড়া। একই পাতা দুই ট্যাবে খোলা থাকলে, বা বোতামে দুইবার চাপ পড়লে, দ্বিতীয় অনুরোধের
 * কপিতে তখনো "খোলা" লেখা — আর টাকা দ্বিতীয়বার খাতায় বসত (বা খাতার দরজায় ভাঙত)।
 *
 * ── ⭐ এখন ─────────────────────────────────────────────────────────────
 * লেনদেনের ভিতরে কাগজের সারিতে তালা দিয়ে তাজা অবস্থা আবার দেখা
 * ([[DepositClaimService::lockPending()]]-এর ছাঁচ)। প্রতিটা দাবিতে একই কাগজের দুইটা কপি:
 * প্রথমটায় কাজ হয়, পুরনো কপিতে দ্বিতীয়টা পরিষ্কার কথায় ফেরে, আর খাতা একবারই নড়ে।
 * ⓘ মালিক (সুপার অ্যাডমিন) নিজেই করেন — প্রথমবারে তাঁকে কিছু আটকায় না।
 */
final class TheSecondClickPostedTheMoneyAgainTest extends TestCase
{
    use PutsMoneyInTheTill;
    use RefreshDatabase;

    private Company $company;

    private Account $till;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();
        app(DepositKindInstaller::class)->install();
        $this->till = app(CashTillService::class)->ensurePrimaryTill()->account;
        $this->putMoneyIn($this->till, '2000000', now()->startOfMonth()->toDateString());
    }

    public function test_capital_is_posted_once(): void
    {
        $entry = app(CapitalService::class)->record([
            'person_id' => $this->owner()->id,
            'contributor_type' => CapitalEntry::OWNER,
            'entry_type' => CapitalEntry::CONTRIBUTION,
            'trx_date' => now()->toDateString(),
            'amount' => '250000',
        ]);
        $stale = CapitalEntry::query()->findOrFail($entry->id);

        $this->onceOnly(
            fn () => app(CapitalService::class)->post($entry, $this->till),
            fn () => app(CapitalService::class)->post($stale, $this->till),
        );
    }

    public function test_a_deposit_is_closed_once_and_pays_nothing_after(): void
    {
        $deposit = $this->deposit();
        $stale = Deposit::query()->findOrFail($deposit->id);
        $close = ['money_account_id' => $this->till->id, 'amount' => '100000', 'moved_on' => now()->toDateString()];

        $this->onceOnly(
            fn () => app(DepositService::class)->close($deposit, $close),
            fn () => app(DepositService::class)->close($stale, $close),
        );

        $this->assertRefused(fn () => app(DepositService::class)->payout($stale, [
            'money_account_id' => $this->till->id, 'amount' => '500', 'moved_on' => now()->toDateString(),
        ]), 'বন্ধ জমা থেকে পুরনো কপিতে মুনাফা তোলা');
    }

    /**
     * ⭐ একই payout দুইবার পৌঁছাল (দুইবার চাপ, ব্রাউজারের আবার-পাঠানো) — মুনাফা একবারই বেরোয়।
     *
     * ⓘ payout জমার অবস্থা বদলায় না (মাসে মাসে বৈধ), তাই তালা আর অবস্থা এটা আটকাতে পারে না
     * (abos-2c, ৩০ সেপ্টেম্বর ২০২৬)। আটকায় ফর্মের একবারের টোকেন ([[OneSubmitPerForm]]) — আর
     * পর্দা ছাড়া payout-এর কোনো দরজা নেই। এই দাবি সেটাই বাঁধে: একই টোকেনে একটা, নতুন টোকেনে
     * পরেরটা (পরের মাসের বৈধ তোলা) — চলে।
     */
    public function test_one_payout_form_sent_twice_pays_once_and_a_new_form_pays_again(): void
    {
        $deposit = $this->deposit();
        $where = ['issuer' => DepositKind::BANK, 'deposit' => $deposit->id];
        $form = fn (string $once) => [
            '_once' => $once,
            'kind' => DepositMovement::PAYOUT,
            'amount' => '500',
            'moved_on' => now()->toDateString(),
            'money_account_id' => $this->till->id,
        ];

        $this->post(route('finance.deposit.movement', $where), $form('payout-form-1'))->assertRedirect();
        $this->post(route('finance.deposit.movement', $where), $form('payout-form-1'))->assertRedirect();

        $this->assertSame(1, $this->payouts($deposit), '⛔ একই ফর্ম দুইবার পৌঁছে মুনাফা দুইবার বেরিয়েছে।');

        $this->post(route('finance.deposit.movement', $where), $form('payout-form-2'))->assertRedirect();

        $this->assertSame(2, $this->payouts($deposit), '⛔ নতুন ফর্মের বৈধ দ্বিতীয় তোলাও আটকে গেছে — পাহারা বেশি চেপেছে।');
    }

    public function test_a_rental_contract_is_closed_once_and_takes_no_month_after(): void
    {
        $contract = $this->rental();
        $stale = RentalContract::query()->findOrFail($contract->id);

        $this->onceOnly(
            fn () => app(RentalContractService::class)->close($contract, ['money_account_id' => $this->till->id]),
            fn () => app(RentalContractService::class)->close($stale, ['money_account_id' => $this->till->id]),
        );

        $this->assertRefused(fn () => app(RentalContractService::class)->adjustMonth($stale, [
            'for_month' => now()->startOfMonth()->toDateString(),
            'money_account_id' => $this->till->id,
        ]), 'বন্ধ চুক্তিতে পুরনো কপিতে মাসের সমন্বয়');
    }

    /**
     * ⛔ একই মাসের দুই ক্লিক একসাথে — দুটোই লেনদেনের বাইরের যাচাই পার হত, তাই মাসটা দুইবার বসত,
     * ভাড়া দুইবার খরচে আর জামানত দুইবার কাটা। মঞ্চ: আমাদের লেনদেন শুরু হতেই অন্যটা একই মাস বসিয়ে
     * ফেলে; তালার পরে আবার দেখা না হলে আমাদেরটাও বসে।
     */
    public function test_the_same_month_sent_twice_at_once_is_taken_once(): void
    {
        $contract = $this->rental();
        $month = ['for_month' => now()->startOfMonth()->toDateString(), 'money_account_id' => $this->till->id];
        $staged = false;

        Event::listen(TransactionBeginning::class, function () use (&$staged, $contract, $month): void {
            if ($staged) {
                return;
            }

            $staged = true;
            app(RentalContractService::class)->adjustMonth(RentalContract::query()->findOrFail($contract->id), $month);
        });

        $said = null;

        try {
            app(RentalContractService::class)->adjustMonth($contract, $month);
        } catch (ValidationException $e) {
            $said = array_key_first($e->errors());
        }

        $this->assertTrue($staged, 'প্রস্তুতিটাই ভুল — অন্য ক্লিকটা মঞ্চে আসেনি।');
        $this->assertSame('for_month', $said, '⛔ একই মাস একসাথে দুইবার পাঠানো হলো, দ্বিতীয়টা থামেনি।');
        // ⓘ মঞ্চের অন্য ক্লিক আমাদের লেনদেনের ভিতরেই চলে, তাই আমাদেরটা থামলে সেটাও ফেরে (০) — আসল দুই
        // অনুরোধে সেটা ১; দাবিটা কেবল "দুইবার নয়"
        $this->assertLessThan(2, $contract->adjustments()->count(), '⛔ একই মাস দুইবার বসেছে — ভাড়া দুইবার খরচে, জামানত দুইবার কাটা।');
    }

    public function test_a_withdrawal_is_posted_once(): void
    {
        $withdrawal = app(WithdrawalService::class)->request([
            'person_id' => $this->owner()->id,
            'amount' => '5000',
            'trx_date' => now()->toDateString(),
        ]);
        $stale = Withdrawal::query()->findOrFail($withdrawal->id);

        $this->onceOnly(
            fn () => app(WithdrawalService::class)->post($withdrawal, $this->till),
            fn () => app(WithdrawalService::class)->post($stale, $this->till),
        );
    }

    // ── সহায়ক ───────────────────────────────────────────────────────────

    /** প্রথমটায় কাজ হয়; পুরনো কপিতে দ্বিতীয়টা পরিষ্কার কথায় ফেরে, আর খাতা একবারই নড়ে। */
    private function onceOnly(callable $first, callable $again): void
    {
        $before = LedgerEntry::query()->count();
        $first();
        $once = LedgerEntry::query()->count();

        $this->assertGreaterThan($before, $once, 'প্রস্তুতিটাই ভুল — প্রথম কাজে খাতা নড়েনি।');

        $this->assertRefused($again, 'পুরনো কপিতে দ্বিতীয় ক্লিক');
        $this->assertSame($once, LedgerEntry::query()->count(), '⛔ দ্বিতীয় ক্লিকে খাতায় আবার দাখিলা বসেছে।');
    }

    private function assertRefused(callable $act, string $what): void
    {
        $said = null;

        try {
            $act();
        } catch (ValidationException $e) {
            $said = array_key_first($e->errors());
        } catch (\Throwable $e) {
            $said = class_basename($e).': '.$e->getMessage();
        }

        $this->assertSame('status', $said, "⛔ {$what} পরিষ্কার কথায় ফেরেনি: ".var_export($said, true));
    }

    private function payouts(Deposit $deposit): int
    {
        return DepositMovement::query()->where('deposit_id', $deposit->id)->where('kind', DepositMovement::PAYOUT)->count();
    }

    private function rental(): RentalContract
    {
        return app(RentalContractService::class)->open([
            'counterparty' => 'দোকানের মালিক',
            'subject' => 'দোকান',
            'deposit_amount' => '240000',
            'monthly_rent' => '30000',
            'monthly_adjustment' => '10000',
            'starts_on' => now()->startOfMonth()->toDateString(),
            'term_months' => 24,
            'money_account_id' => $this->till->id,
        ]);
    }

    private function deposit(): Deposit
    {
        return app(DepositService::class)->open([
            'kind_id' => DepositKind::query()->where('code', 'FDR')->firstOrFail()->id,
            'institution' => 'সোনালী ব্যাংক',
            'held_by' => Deposit::BUSINESS,
            'principal' => '100000',
            'return_word' => 'interest',
            'opened_on' => now()->toDateString(),
            'funded_from_account_id' => $this->till->id,
        ]);
    }

    private function owner(): Person
    {
        return Person::query()->firstOrCreate(
            ['company_id' => $this->company->id, 'name_en' => 'মালিক'],
            ['code' => 'P-OWNER'],
        );
    }
}
