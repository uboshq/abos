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
 * ⛔ নগদ যায় কেবল এই কোম্পানির চালু বাক্সে, আর মালিকহীন বাক্সে পাঠানো ব্যক্তি নিজে গ্রহণ দেন না — অডিট স১৬/ছ১৬, ১০ অক্টোবর ২০২৬।
 *
 * ⓘ [[MoneyTransferService]]-এ দুই ফাঁক ছিল:
 * - গ্রহীতা বাক্সের নম্বর যেমন এল তেমনি বসত — বন্ধ বা অন্য কোম্পানির বাক্সে টাকা "পথে" ঝুলে থাকত;
 * - মালিকহীন বাক্সে গ্রহীতার নামটাই একমাত্র পাহারা, আর নামটা লেখেন পাঠানো ব্যক্তিই — নিজের নাম লিখে নিজেই গ্রহণ দিতেন।
 */
final class ATransferGoesOnlyToAnOpenTillOfThisCompanyTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $cashier;

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

        $this->cashier = User::factory()->create();
        $this->cashier->companies()->attach($this->company, ['is_active' => true]);
        $this->cashier->forceFill(['current_company_id' => $this->company->id])->save();
        foreach (['accounts.transfer.create', 'accounts.transfer.confirm'] as $key) {
            $this->cashier->givePermissionTo(Permission::findOrCreate($key, 'web'));
        }

        $tills = app(CashTillService::class);
        $this->from = $tills->ensurePrimaryTill();
        $this->from->forceFill(['holder_id' => $this->cashier->id])->save();
        // ⓘ মালিকহীন বাক্স — গ্রহীতার নামই তখন একমাত্র পাহারা
        $this->to = $tills->create(['code' => 'S16-BOX', 'name_en' => 'S16 Box', 'name_bn' => 'স১৬ বাক্স']);
        $this->assertNull($this->to->holder_id, 'দৃশ্যটাই বানানো যায়নি — বাক্সের মালিক আছে');

        Account::query()->whereKey($this->from->account_id)->update(['opening_balance' => '90000', 'opening_date' => '2026-07-01']);
        app(OpeningBalanceService::class)->forAccount(Account::query()->findOrFail($this->from->account_id));
    }

    public function test_the_sender_who_named_himself_receiver_cannot_accept(): void
    {
        $transfer = $this->send(['to_till_id' => $this->to->id, 'received_by' => $this->cashier->id]);

        try {
            app(MoneyTransferService::class)->confirm($transfer, (int) $this->cashier->id);
            $this->fail('⛔ পাঠানো ব্যক্তি নিজের নাম গ্রহীতা লিখে নিজেই গ্রহণ দিলেন');
        } catch (ValidationException $e) {
            $this->assertSame(__('accounts::validation.transfer_sender_cannot_receive'), $e->errors()['status'][0] ?? null);
        }

        $this->assertTrue($transfer->fresh()->isPending());
    }

    public function test_a_closed_till_takes_no_transfer(): void
    {
        $this->to->forceFill(['is_active' => false])->save();

        $this->assertSendRefused(__('accounts::validation.till_inactive', ['name' => $this->to->name()]), '⛔ বন্ধ বাক্সে টাকা পাঠানো গেল');
    }

    public function test_another_companys_till_takes_no_transfer(): void
    {
        $other = Company::query()->whereKeyNot($this->company->id)->firstOrFail();
        CashTill::query()->withoutGlobalScopes()->whereKey($this->to->id)->update(['company_id' => $other->id]);

        $this->assertSendRefused(__('accounts::validation.till_not_found'), '⛔ অন্য কোম্পানির বাক্সে টাকা পাঠানো গেল');
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    /** @param array<string, mixed> $to */
    private function send(array $to): MoneyTransfer
    {
        $this->actingAs($this->cashier);

        return app(MoneyTransferService::class)->initiate([
            'trx_date' => now()->subDays(2)->toDateString(),
            'from_till_id' => $this->from->id,
            'given_by' => $this->cashier->id,
            'amount' => '5000.00',
            ...$to,
        ]);
    }

    private function assertSendRefused(string $message, string $why): void
    {
        try {
            $this->send(['to_till_id' => $this->to->id]);
            $this->fail($why);
        } catch (ValidationException $e) {
            $this->assertSame($message, $e->errors()['to_till_id'][0] ?? null);
        }

        $this->assertSame(0, MoneyTransfer::query()->where('to_till_id', $this->to->id)->count());
    }
}
