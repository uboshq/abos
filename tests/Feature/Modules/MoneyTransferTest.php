<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\CashTill;
use App\Modules\Accounts\Models\MoneyTransfer;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\MoneyTransferService;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * টাকা হস্তান্তর — দুই ধাপ, দুইজন মানুষ।
 *
 * এই মডিউলটার পুরো কারণই একটা প্রশ্ন: পথে টাকা হারালে কার দায়। এক ধাপে
 * করলে দাতার "দিয়েছি" বলাই যথেষ্ট হত, আর সিস্টেম গ্রহীতার পক্ষে
 * সাক্ষ্য দিত। তাই এখানকার সবচেয়ে গুরুত্বপূর্ণ পরীক্ষাটা হল: গ্রহণের
 * আগে টাকাটা কার হিসাবে থাকে।
 */
class MoneyTransferTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $giver;

    private User $receiver;

    private CashTill $from;

    private CashTill $to;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->giver = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->receiver = User::query()->where('email', 'accounts@abos.test')->firstOrFail();

        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs($this->giver);

        app(StandardChart::class)->install();

        $tills = app(CashTillService::class);

        $this->from = $tills->ensurePrimaryTill();
        $this->to = $tills->create([
            'code' => 'RIDER-A',
            'name_en' => 'Rider A',
            'name_bn' => 'রাইডার এ',
            'holder_id' => $this->receiver->id,
        ]);

        $this->fund($this->from, '20000');
    }

    private function service(): MoneyTransferService
    {
        return app(MoneyTransferService::class);
    }

    private function start(string $amount = '5000.00', array $overrides = []): MoneyTransfer
    {
        return $this->service()->initiate([
            'trx_date' => '2026-08-10',
            'from_till_id' => $this->from->id,
            'to_till_id' => $this->to->id,
            'given_by' => $this->giver->id,
            'received_by' => $this->receiver->id,
            'amount' => $amount,
            ...$overrides,
        ]);
    }

    // ── দুই ধাপ — সবচেয়ে জরুরি অংশ ────────────────────────────────────

    /**
     * দেওয়ার সাথে সাথেই টাকা টিল থেকে বেরোয়, কিন্তু গ্রহীতার কাছে যায় না।
     *
     * ── এই পরীক্ষাটা আগে উল্টো দাবি করত ─────────────────────────────
     * লেখা ছিল "দাতার ব্যালেন্স বদলায় না, খতিয়ানে একটা সারিও বসে না",
     * আর যুক্তি ছিল "গ্রহণ নিশ্চিত না হওয়া পর্যন্ত টাকাটা দাতার"।
     * দায়িত্বের দিক থেকে ঠিক, ব্যালেন্সের দিক থেকে মিথ্যা: টাকাটা
     * ড্রয়ার থেকে বেরিয়ে গেছে। ওই দিন গণনা করলে ঘাটতি দেখাত, আর
     * হেফাজতকারী দায়ী হতেন এমন টাকার জন্য যেটা তিনি দিয়ে দিয়েছেন।
     *
     * দায়িত্বটা হারায়নি — টাকাটা গ্রহীতার খাতেও যায়নি, গেছে "পথের
     * টাকা" খাতে, যেটা কারও হাতে নেই। পুরো গল্পটা MoneyOnTheRoadTest-এ।
     */
    public function test_handing_over_takes_it_out_of_the_till_but_not_into_the_receivers(): void
    {
        $before = $this->from->fresh()->balance();

        $transfer = $this->start('5000.00');

        $this->assertTrue($transfer->isPending());

        $this->assertSame(0, bccomp(bcsub($before, $this->from->fresh()->balance(), 4), '5000', 4),
            'হাতে হাতে দেওয়ার পরেও টাকাটা দাতার টিলে রয়ে গেছে।');

        $this->assertSame('0.0000', $this->to->fresh()->balance(),
            'গ্রহণ করার আগেই টাকাটা গ্রহীতার হিসাবে চলে গেছে।');

        // গ্রহণের পোস্টিংটা এখনো হয়নি; পাঠানোরটা হয়েছে
        $this->assertSame(0, LedgerEntry::query()
            ->where('source_type', 'money_transfer')->count());
        $this->assertGreaterThan(0, LedgerEntry::query()
            ->where('source_type', 'money_transfer:sent')->count());
    }

    public function test_receiving_moves_the_money_and_names_who_confirmed(): void
    {
        $transfer = $this->start('5000.00');

        $this->actingAs($this->receiver);
        $this->service()->confirm($transfer, (int) $this->receiver->id);

        $this->assertSame('15000.0000', $this->from->fresh()->balance());
        $this->assertSame('5000.0000', $this->to->fresh()->balance());

        $fresh = $transfer->fresh();

        $this->assertSame(DocumentStatus::CONFIRMED, $fresh->status);
        $this->assertSame($this->receiver->id, $fresh->confirmed_by);
        $this->assertNotNull($fresh->confirmed_at);
    }

    public function test_both_names_are_kept_because_the_slip_needs_two_signatures(): void
    {
        $transfer = $this->start();

        $this->assertSame($this->giver->id, $transfer->given_by);
        $this->assertSame($this->receiver->id, $transfer->received_by);
    }

    // ── বাধা ────────────────────────────────────────────────────────────

    public function test_more_than_is_in_hand_cannot_be_handed_over(): void
    {
        $this->expectException(ValidationException::class);

        // হাতে আছে ২০,০০০ — হাতে না থাকা টাকা কেউ হাতে হাতে দিতে পারে না
        $this->start('25000.00');
    }

    public function test_the_same_counter_cannot_be_both_sides(): void
    {
        $this->expectException(ValidationException::class);

        $this->start('1000.00', ['to_till_id' => $this->from->id]);
    }

    public function test_a_transfer_needs_somewhere_to_go(): void
    {
        $this->expectException(ValidationException::class);

        $this->service()->initiate([
            'trx_date' => '2026-08-10',
            'from_till_id' => $this->from->id,
            'amount' => '1000.00',
        ]);
    }

    public function test_a_transfer_cannot_be_received_twice(): void
    {
        $transfer = $this->start();
        $this->service()->confirm($transfer);

        $this->expectException(ValidationException::class);

        $this->service()->confirm($transfer->fresh());
    }

    // ── ব্যাংকে জমা ─────────────────────────────────────────────────────

    public function test_a_bank_deposit_is_the_same_transfer_with_an_account_as_destination(): void
    {
        $bank = Account::query()->create([
            'company_id' => $this->company->id,
            'code' => '1102-DEP',
            'name_en' => 'Deposit Bank',
            'name_bn' => 'জমা ব্যাংক',
            'parent_id' => StandardChart::find(StandardChart::BANK_AND_MFS)->id,
            'type' => Account::ASSET,
            'nature' => Account::DEBIT,
            'is_bank' => true,
            'is_active' => true,
            'status' => DocumentStatus::CONFIRMED,
        ]);

        $transfer = $this->service()->initiate([
            'trx_date' => '2026-08-10',
            'from_till_id' => $this->from->id,
            'to_account_id' => $bank->id,
            'amount' => '8000.00',
        ]);

        $this->service()->confirm($transfer);

        $this->assertSame('12000.0000', $this->from->fresh()->balance());
        $this->assertSame('8000.0000', $bank->fresh()->balanceOn());
    }

    // ── বাতিল (নিয়ম ৫) ────────────────────────────────────────────────

    public function test_cancelling_a_received_transfer_reverses_it(): void
    {
        $transfer = $this->start('5000.00');
        $this->service()->confirm($transfer);

        $this->service()->cancel($transfer->fresh(), 'ভুল কাউন্টারে পাঠানো হয়েছিল');

        $this->assertSame('20000.0000', $this->from->fresh()->balance());
        $this->assertSame('0.0000', $this->to->fresh()->balance());

        // মূল এন্ট্রিগুলো থেকে যায় — মুছে গেলে স্লিপের নম্বরটা কোনো
        // রেকর্ডের সাথে মিলত না
        $this->assertSame(2, LedgerEntry::query()
            ->where('source_type', 'money_transfer')->count());
        $this->assertSame(2, LedgerEntry::query()
            ->where('source_type', 'money_transfer:reversal')->count());
    }

    public function test_cancelling_a_pending_transfer_needs_no_reversal(): void
    {
        $transfer = $this->start();

        $this->service()->cancel($transfer, 'আর দরকার নেই');

        $this->assertTrue($transfer->fresh()->isCancelled());
        $this->assertSame(0, LedgerEntry::query()->where('source_type', 'money_transfer')->count());
    }

    // ── স্ক্রিন ─────────────────────────────────────────────────────────

    public function test_the_list_puts_what_awaits_you_at_the_top(): void
    {
        $this->start('3000.00');

        $this->actingAs($this->receiver)
            ->get(route('accounts.transfer.index'))
            ->assertOk()
            ->assertSee(__('accounts::message.awaiting_you'), false)
            ->assertSee('3,000.00');

        // দাতার পর্দায় ওই ব্লকটা নেই — টাকাটা তার গ্রহণের অপেক্ষায় নয়
        $this->actingAs($this->giver)
            ->get(route('accounts.transfer.index'))
            ->assertOk()
            ->assertDontSee(__('accounts::message.awaiting_you'), false);
    }

    public function test_the_form_accepts_a_till_or_a_bank_from_one_field(): void
    {
        $this->actingAs($this->giver)
            ->post(route('accounts.transfer.store'), [
                'trx_date' => '2026-08-10',
                'from_till_id' => $this->from->id,
                'destination' => 'till:'.$this->to->id,
                'amount' => '2000.00',
                'received_by' => $this->receiver->id,
            ])
            ->assertRedirect();

        $transfer = MoneyTransfer::query()->latest('id')->firstOrFail();

        $this->assertSame($this->to->id, $transfer->to_till_id);
        $this->assertNull($transfer->to_account_id);
    }

    public function test_receiving_through_the_screen_works(): void
    {
        $transfer = $this->start('1500.00');

        $this->actingAs($this->receiver)
            ->post(route('accounts.transfer.confirm', $transfer))
            ->assertRedirect();

        $this->assertTrue($transfer->fresh()->isConfirmed());
    }

    public function test_a_user_without_the_confirm_permission_cannot_receive(): void
    {
        $transfer = $this->start();

        $stranger = User::factory()->create();
        $stranger->companies()->attach($this->company, ['is_active' => true]);
        $stranger->forceFill(['current_company_id' => $this->company->id])->save();
        $stranger->givePermissionTo(
            Permission::findOrCreate('accounts.transfer.create', 'web')
        );

        $this->actingAs($stranger)
            ->post(route('accounts.transfer.confirm', $transfer))
            ->assertForbidden();

        $this->assertTrue($transfer->fresh()->isPending());
    }

    /** কাউন্টারে টাকা বসানো — পরীক্ষার শুরুর অবস্থা। */
    private function fund(CashTill $till, string $amount): void
    {
        Account::query()->whereKey($till->account_id)->update([
            'opening_balance' => $amount,
            'opening_date' => '2026-07-01',
        ]);
    }
}
