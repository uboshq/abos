<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\CashTill;
use App\Modules\Accounts\Models\MoneyTransfer;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\MoneyTransferService;
use App\Modules\Accounts\Services\OpeningBalanceService;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * গ৫ — নগদ স্থানান্তর গ্রহণ করেন কেবল গ্রহীতা বাক্সের মালিক; গ্রহণের পরে পাঠানো ব্যক্তি আর বাতিল করতে পারেন না
 * (Accounts-Finance অডিট, ৪ অক্টোবর ২০২৬)।
 *
 * ⛔ আগে: ক্যাশিয়ার নিজের বাক্স থেকে অন্যের বাক্সে ৫০,০০০ পাঠিয়ে নিজেই "পেয়েছি" দিতে পারতেন; আর অন্যজন গ্রহণ
 * করার পরেও কেবল "তৈরি" চাবি দিয়ে বাতিল করলে খাতায় টাকা ফিরত, অথচ নগদ অন্যের হাতে।
 *
 * ⓘ মালিক (super admin) আগের মতোই সব পারেন — তাঁর ক্ষমতা কোনো সংশোধনে কমে না।
 */
class OnlyTheReceivingBoxHolderAcceptsATransferTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $cashier;

    private User $holder;

    private User $bystander;

    private CashTill $from;

    private CashTill $to;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();

        // ⓘ তিনজনেরই দুটো চাবি — দরজা চাবিতে নয়, বাক্সের মালিকানায় আটকাতে হবে
        $this->cashier = $this->staff();
        $this->holder = $this->staff();
        $this->bystander = $this->staff();

        $tills = app(CashTillService::class);
        $this->from = $tills->ensurePrimaryTill();
        // ⓘ পাঠানোর বাক্স পাঠানো মানুষের হেফাজতে — নগদ কেবল নিজের বাক্স থেকে (অডিট হিসাব ⚠️৫, ৬ অক্টোবর ২০২৬; [[ATransferLeavesOnlyYourOwnTillTest]])
        $this->from->forceFill(['holder_id' => $this->cashier->id])->save();
        $this->to = $tills->create([
            'code' => 'RIDER-G5',
            'name_en' => 'Rider G5',
            'name_bn' => 'রাইডার গ৫',
            'holder_id' => $this->holder->id,
        ]);

        Account::query()->whereKey($this->from->account_id)->update(['opening_balance' => '90000', 'opening_date' => '2026-07-01']);
        app(OpeningBalanceService::class)->forAccount(Account::query()->findOrFail($this->from->account_id));
    }

    public function test_the_sender_cannot_accept_his_own_transfer_into_someone_elses_box(): void
    {
        $transfer = $this->sendAs($this->cashier, ['to_till_id' => $this->to->id]);

        $this->assertRefused(fn () => $this->service()->confirm($transfer, (int) $this->cashier->id), $this->cashier);
        $this->assertTrue($transfer->fresh()->isPending(), '⛔ পাঠানো ব্যক্তি নিজেই অন্যের বাক্সে টাকা "পেয়েছি" দিলেন।');
    }

    public function test_someone_else_with_the_key_cannot_accept_into_a_box_he_does_not_hold(): void
    {
        $transfer = $this->sendAs($this->cashier, ['to_till_id' => $this->to->id]);

        $this->assertRefused(fn () => $this->service()->confirm($transfer, (int) $this->bystander->id), $this->bystander);
        $this->assertTrue($transfer->fresh()->isPending(), '⛔ বাক্সের মালিক নন এমন কেউ গ্রহণ দিলেন।');

        // ⓘ মালিকই পারেন
        $this->actingAs($this->holder);
        $this->service()->confirm($transfer->fresh(), (int) $this->holder->id);
        $this->assertTrue($transfer->fresh()->isConfirmed());
    }

    public function test_once_accepted_the_sender_cannot_cancel_but_the_holder_can_send_it_back(): void
    {
        $transfer = $this->sendAs($this->cashier, ['to_till_id' => $this->to->id]);
        $this->actingAs($this->holder);
        $this->service()->confirm($transfer, (int) $this->holder->id);

        $this->assertRefused(fn () => $this->service()->cancel($transfer->fresh(), 'ভুল বাক্সে গেছে'), $this->cashier);
        $this->assertTrue($transfer->fresh()->isConfirmed(), '⛔ গ্রহণের পরে পাঠানো ব্যক্তি বাতিল করলেন — নগদ অন্যের হাতে, খাতায় ফিরল।');

        // ⓘ নগদ যাঁর হাতে, ফেরত দেওয়ার কাজটা তাঁর
        $this->actingAs($this->holder);
        $this->service()->cancel($transfer->fresh(), 'ভুল বাক্সে গেছে, ফেরত দিলাম');
        $this->assertTrue($transfer->fresh()->isCancelled());
    }

    public function test_before_acceptance_the_sender_may_still_call_it_back(): void
    {
        $transfer = $this->sendAs($this->cashier, ['to_till_id' => $this->to->id]);

        $this->actingAs($this->cashier);
        $this->service()->cancel($transfer, 'আর দরকার নেই');

        $this->assertTrue($transfer->fresh()->isCancelled());
    }

    public function test_a_bank_deposit_is_accepted_by_someone_other_than_the_sender(): void
    {
        $bank = Account::query()->create([
            'company_id' => $this->company->id,
            'code' => '1102-G5',
            'name_en' => 'G5 Bank',
            'name_bn' => 'গ৫ ব্যাংক',
            'parent_id' => StandardChart::find(StandardChart::BANK)->id,
            'type' => Account::ASSET,
            'nature' => Account::DEBIT,
            'money_kind' => Account::BANK,
            'is_active' => true,
            'status' => DocumentStatus::CONFIRMED,
        ]);

        $transfer = $this->sendAs($this->cashier, ['to_account_id' => $bank->id]);

        $this->assertRefused(fn () => $this->service()->confirm($transfer, (int) $this->cashier->id), $this->cashier);
        $this->assertTrue($transfer->fresh()->isPending(), '⛔ ব্যাংকে জমার "পেয়েছি" দিলেন পাঠানো ব্যক্তি নিজেই।');

        $this->actingAs($this->bystander);
        $this->service()->confirm($transfer->fresh(), (int) $this->bystander->id);
        $this->assertTrue($transfer->fresh()->isConfirmed());
    }

    public function test_the_screen_refuses_the_sender_too(): void
    {
        $transfer = $this->sendAs($this->cashier, ['to_till_id' => $this->to->id]);

        $this->actingAs($this->cashier)
            ->from(route('accounts.transfer.show', $transfer))
            ->post(route('accounts.transfer.confirm', $transfer))
            ->assertSessionHasErrors('status');

        $this->assertTrue($transfer->fresh()->isPending());
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    private function service(): MoneyTransferService
    {
        return app(MoneyTransferService::class);
    }

    /** @param  array<string, mixed>  $to */
    private function sendAs(User $sender, array $to): MoneyTransfer
    {
        $this->actingAs($sender);

        return $this->service()->initiate([
            'trx_date' => now()->subDays(2)->toDateString(),
            'from_till_id' => $this->from->id,
            'given_by' => $sender->id,
            'amount' => '50000.00',
            ...$to,
        ]);
    }

    private function assertRefused(\Closure $act, User $as): void
    {
        $this->actingAs($as);

        try {
            $act();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());

            return;
        }

        $this->fail('⛔ দরজা খোলা — কাজটা হয়ে গেল।');
    }

    private function staff(): User
    {
        $user = User::factory()->create();
        $user->companies()->attach($this->company, ['is_active' => true]);
        $user->forceFill(['current_company_id' => $this->company->id])->save();

        foreach (['accounts.transfer.create', 'accounts.transfer.confirm'] as $key) {
            $user->givePermissionTo(Permission::findOrCreate($key, 'web'));
        }

        return $user;
    }
}
