<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
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
 * ⛔ সহকর্মীর বাক্স থেকে, তাঁর নামে টাকা পাঠানো যেত — পুরো-ERP অডিট, ৬ অক্টোবর ২০২৬ (হিসাব ⚠️৫)।
 *
 * ⓘ ভাউচারে নগদ কেবল নিজের বাক্সে (মালিকের নিয়ম, ২১ সেপ্টেম্বর ২০২৬), অথচ [[MoneyTransferService::initiate()]] পাঠানোর বাক্সের
 * হেফাজত দেখত না, আর "দিলেন" ঘরে যেকোনো নাম বসত। এখন বাক্সটা লেখকের হতে হয় ([[CashTill::mayUse()]]), আর "দিলেন" লেখক নিজে বা
 * বাক্সের ধারক।
 */
final class ATransferLeavesOnlyYourOwnTillTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $holder;

    private User $colleague;

    private User $bystander;

    private CashTill $box;

    private CashTill $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $this->holder = $this->staff();
        $this->colleague = $this->staff();
        $this->bystander = $this->staff();

        $tills = app(CashTillService::class);
        $this->box = $tills->create(['code' => 'BOX-A5', 'name_en' => 'Box A5', 'name_bn' => 'বাক্স ক৫', 'holder_id' => $this->holder->id]);
        $this->other = $tills->create(['code' => 'BOX-A5B', 'name_en' => 'Box A5B', 'name_bn' => 'বাক্স ক৫খ', 'holder_id' => $this->colleague->id]);

        Account::query()->whereKey($this->box->account_id)->update(['opening_balance' => '90000', 'opening_date' => '2026-07-01']);
        app(OpeningBalanceService::class)->forAccount(Account::query()->findOrFail($this->box->account_id));
    }

    public function test_only_the_holder_sends_from_the_box(): void
    {
        $said = $this->refused(fn () => $this->send($this->colleague, $this->colleague->id));
        $this->assertSame(__('accounts::validation.transfer_not_your_till', ['till' => $this->box->name()]), $said['from_till_id'][0] ?? null,
            '⛔ সহকর্মী অন্যের বাক্স থেকে টাকা পাঠালেন');
        $this->assertSame(0, MoneyTransfer::query()->count(), '⛔ থেমে যাওয়া পাঠানোর কাগজ বসে গেল');

        $sent = $this->send($this->holder, $this->holder->id);
        $this->assertSame((int) $this->holder->id, (int) $sent->given_by);
    }

    public function test_given_by_is_the_writer_or_the_holder(): void
    {
        $said = $this->refused(fn () => $this->send($this->holder, $this->bystander->id));
        $this->assertSame(__('accounts::validation.giver_not_the_holder', ['till' => $this->box->name()]), $said['given_by'][0] ?? null,
            '⛔ "দিলেন" ঘরে বাইরের একজনের নাম বসে গেল');

        // ⓘ ঘর ফাঁকা — লেখক নিজেই
        $this->assertSame((int) $this->holder->id, (int) $this->send($this->holder, null)->given_by);
    }

    public function test_with_nobody_holding_a_box_the_rule_sleeps(): void
    {
        CashTill::query()->withoutGlobalScopes()->update(['holder_id' => null]);
        Account::query()->where('money_kind', Account::CASH)->update(['held_by' => null]);

        $this->assertSame((int) $this->bystander->id, (int) $this->send($this->bystander, $this->bystander->id)->given_by,
            'কারও নামে বাক্স নেই — যে কেউ পাঠাতে পারার কথা');
    }

    public function test_the_console_with_no_user_is_not_held_to_a_box(): void
    {
        // ⓘ কনসোল, সিডার আর ইমপোর্টে কেউ লগইন নেই — ভাউচারের নিয়মের মতোই তখন বাক্সের প্রশ্ন ওঠে না
        auth()->logout();

        $sent = app(MoneyTransferService::class)->initiate([
            'trx_date' => now()->subDays(2)->toDateString(), 'from_till_id' => $this->box->id,
            'to_till_id' => $this->other->id, 'given_by' => $this->holder->id, 'amount' => '5000.00',
        ]);

        $this->assertSame((int) $this->holder->id, (int) $sent->given_by);
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    private function send(User $as, int|string|null $givenBy): MoneyTransfer
    {
        $this->actingAs($as);

        return app(MoneyTransferService::class)->initiate(array_filter([
            'trx_date' => now()->subDays(2)->toDateString(),
            'from_till_id' => $this->box->id,
            'to_till_id' => $this->other->id,
            'given_by' => $givenBy,
            'amount' => '5000.00',
        ], fn ($v) => $v !== null));
    }

    /** @return array<string, list<string>> */
    private function refused(\Closure $act): array
    {
        try {
            $act();
        } catch (ValidationException $e) {
            return $e->errors();
        }

        $this->fail('⛔ দরজা খোলা — টাকা পাঠানো হয়ে গেল');
    }

    private function staff(): User
    {
        $user = User::factory()->create();
        $user->companies()->attach($this->company, ['is_active' => true]);
        $user->forceFill(['current_company_id' => $this->company->id])->save();
        $user->givePermissionTo(Permission::findOrCreate('accounts.transfer.create', 'web'));

        return $user;
    }
}
