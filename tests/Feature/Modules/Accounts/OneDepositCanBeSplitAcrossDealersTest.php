<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Approval;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherApproval;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Customer\Models\Customer;
use App\Modules\MasterData\Models\TransferMode;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⭐ একটা ব্যাংক জমা কয়েকজন ডিলারের নামে ভাগ হতে পারে — মালিক, ২১ নভেম্বর ২০২৬।
 *
 * ⓘ লাইভে: ৳১,০০,০০০ জমা, লেনদেন নম্বর 175300556, দুই ডিলারের ৳৫০,০০০ করে। RCV-0537 (গ্রাহক ১০৪১) সই পেয়েও পোস্টে থামল
 * "লেনদেন নম্বর 175300556 আগেই বসেছে — ভাউচার RCV-0283" — অথচ RCV-0283 ২৫ অক্টোবরের অন্য রসিদ, অন্য মাধ্যমে। দ্বিতীয় ডিলারের
 * RCV-0538-এ নম্বরটা "0556" লিখে পাহারা পেরোতে হল।
 *
 * ⭐ নতুন নিয়ম (আন্তর্জাতিক; [[VoucherService::sameDeposit()]]): একই টাকা কেবল একই নম্বর + একই খাত/মাধ্যম + একই মূল্য-তারিখ +
 * একই পক্ষ, আর কেবল পাকা কাগজে। দাবিগুলো:
 *   ১. একই নম্বর, অন্য পক্ষ — চলে (জমার ভাগ), আর দুই কাগজের পাতায় "একই জমা আরও যে রসিদে" সারি;
 *   ২. একই নম্বর, অন্য তারিখ — চলে; অন্য ব্যাংক — চলে; অন্য মাধ্যম — চলে;
 *   ৩. একই নম্বর, পক্ষ, ব্যাংক আর তারিখ — থামে, আগের কাগজের নম্বরসহ;
 *   ৪. খসড়া বা বাতিল রসিদের একই নম্বর থামায় না;
 *   ৫. পর্দার চলতি উত্তর পোস্টের একই কথা বলে ("split" / "used");
 *   ৬. আটকে থাকা সই-পড়া রসিদ কমান্ডে সাধারণ পোস্টের দরজায় যায় — `--apply` ছাড়া কিছুই লেখে না।
 */
final class OneDepositCanBeSplitAcrossDealersTest extends TestCase
{
    use RefreshDatabase;

    private const NUMBER = '175300556';

    private const DAY = '2026-08-10';

    private Company $company;

    private User $owner;

    private Account $bank;

    private Account $otherBank;

    private Customer $dealerA;

    private Customer $dealerB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs($this->owner);
        app(StandardChart::class)->install();

        $this->bank = $this->makeBank('1102-SPLIT-A');
        $this->otherBank = $this->makeBank('1102-SPLIT-B');

        $customers = Customer::query()->orderBy('id')->take(2)->get();
        $this->assertCount(2, $customers, 'প্রস্তুতিটাই ভুল — দুইজন গ্রাহক নেই');
        [$this->dealerA, $this->dealerB] = [$customers[0], $customers[1]];
    }

    /** ১ — লাইভের হুবহু: একটা জমা, দুই ডিলার, একই নম্বর */
    public function test_the_same_number_for_another_dealer_is_a_split_and_both_papers_say_so(): void
    {
        $first = $this->service()->post($this->receipt($this->dealerA));
        $second = $this->service()->post($this->receipt($this->dealerB));

        $this->assertTrue($first->fresh()->isPosted());
        $this->assertTrue($second->fresh()->isPosted(), '⛔ একটা জমার দ্বিতীয় ডিলারের ভাগ "আগেই বসেছে" বলে থামল');
        $this->assertSame(self::NUMBER, $second->fresh()->instrument_no, '⛔ নম্বরটা বদলে বসল');
        $this->assertSame((int) $this->bank->id, (int) $second->fresh()->money_account_id);

        // ⭐ কাগজের পাতায় একটা সারি — নোটিশের ফিতা নয়
        $this->get(route('accounts.voucher.show', $first))->assertOk()
            ->assertSee('data-voucher-same-deposit', false)
            ->assertSee(__('accounts::field.same_deposit'))
            ->assertSee($second->fresh()->document_no);
        $this->get(route('accounts.voucher.show', $second))->assertOk()
            ->assertSee('data-voucher-same-deposit', false)
            ->assertSee($first->fresh()->document_no);

        $this->assertSame([$second->id], app(VoucherService::class)->sharedDeposit($first->fresh())->pluck('id')->all());
    }

    /** ২ — অন্য তারিখ, অন্য ব্যাংক, অন্য মাধ্যম: সম্পর্কই নেই */
    public function test_another_date_bank_or_mode_is_not_the_same_money(): void
    {
        $nps = $this->mode('NPSB-SPL');
        $rtgs = $this->mode('RTGS-SPL');

        $this->service()->post($this->receipt($this->dealerA, extra: ['transfer_mode_id' => $nps->id]));

        // ⓘ একই পক্ষ — তবু অন্য দিনের জমা (লাইভের RCV-0283: ২৫ অক্টোবর, ভিন্ন মাধ্যম)
        $otherDay = $this->service()->post($this->receipt($this->dealerA, date: '2026-08-11', extra: ['transfer_mode_id' => $nps->id]));
        $this->assertTrue($otherDay->isPosted(), '⛔ অন্য তারিখের একই নম্বর আগের জমার জোড়া বলে থামল');

        // ⓘ মূল্য-তারিখ আলাদা, কাগজের তারিখ এক — মূল্য-তারিখই জমার দিন
        $otherValueDay = $this->service()->post($this->receipt($this->dealerA, extra: ['transfer_mode_id' => $nps->id, 'value_date' => '2026-08-09']));
        $this->assertTrue($otherValueDay->isPosted(), '⛔ অন্য মূল্য-তারিখের একই নম্বর থামল');

        $otherBank = $this->service()->post($this->receipt($this->dealerA, bank: $this->otherBank, extra: ['transfer_mode_id' => $nps->id]));
        $this->assertTrue($otherBank->isPosted(), '⛔ অন্য ব্যাংকের একই নম্বর থামল');

        $otherMode = $this->service()->post($this->receipt($this->dealerA, extra: ['transfer_mode_id' => $rtgs->id]));
        $this->assertTrue($otherMode->isPosted(), '⛔ অন্য মাধ্যমের (RTGS বনাম NPSB) একই নম্বর থামল');
    }

    /** ৩ — একই নম্বর, পক্ষ, ব্যাংক আর তারিখ: একই টাকা দুইবার নয় */
    public function test_the_same_number_party_bank_and_date_is_refused(): void
    {
        $first = $this->service()->post($this->receipt($this->dealerA));
        $again = $this->receipt($this->dealerA);

        try {
            $this->service()->post($again);
            $this->fail('⛔ একই পক্ষ, একই ব্যাংক, একই দিনের একই নম্বর দ্বিতীয়বার খাতায় উঠল');
        } catch (ValidationException $e) {
            $this->assertSame(
                __('accounts::validation.bank_reference_used', ['reference' => self::NUMBER, 'no' => $first->document_no]),
                $e->errors()['instrument_no'][0] ?? null,
                '⛔ জোড়াটা আগের কাগজের নম্বরসহ পরিষ্কার কথায় ধরা পড়েনি',
            );
        }

        $this->assertSame(DocumentStatus::DRAFT, $again->fresh()->status);
        $this->assertNull($again->fresh()->money_account_id, '⛔ থামা পোস্ট খাতা-জোড়া রেখে দিল');

        // ⓘ মাধ্যম কেবল একটায় লেখা — অজানা মাধ্যম "অন্য মাধ্যম" নয়, তাই তবুও একই টাকা
        $withMode = $this->receipt($this->dealerA, extra: ['transfer_mode_id' => $this->mode('BEFTN-SPL')->id]);
        $this->expectException(ValidationException::class);
        $this->service()->post($withMode);
    }

    /** ৪ — খসড়া বা বাতিল রসিদ কখনো থামায় না */
    public function test_a_draft_or_cancelled_receipt_never_blocks(): void
    {
        // ⓘ খসড়া — একই পক্ষ, একই সব কিছু, তবু পাকা নয়
        $draft = $this->receipt($this->dealerA);
        $posted = $this->service()->post($this->receipt($this->dealerA));
        $this->assertTrue($posted->isPosted(), '⛔ খসড়া রসিদের একই নম্বর পোস্ট থামাল');
        $this->assertSame(DocumentStatus::DRAFT, $draft->fresh()->status);

        // ⓘ বাতিল — ভুল অঙ্কে তোলা, বাতিল করে একই নম্বরে সঠিকটা
        $this->service()->cancel($posted->fresh(), 'ভুল অঙ্ক');
        $right = $this->service()->post($this->receipt($this->dealerA, amount: '50500.00'));
        $this->assertTrue($right->isPosted(), '⛔ বাতিল রসিদের একই নম্বর সঠিক রসিদ থামাল');

        // ⓘ বাতিল কাগজ ভাগ হিসেবেও দেখায় না
        $this->assertTrue(app(VoucherService::class)->sharedDeposit($right->fresh())->isEmpty(), '⛔ বাতিল রসিদকে "একই জমা" বলে দেখায়');
    }

    /** ৫ — পর্দার চলতি উত্তর = পোস্টের প্রশ্ন */
    public function test_the_screens_answer_says_split_for_another_party_and_used_for_the_same(): void
    {
        $first = $this->service()->post($this->receipt($this->dealerA));
        $ask = fn (Customer $party, string $date = self::DAY) => $this->getJson(route('accounts.voucher.reference_check', [
            'account_id' => $this->bank->id, 'instrument_no' => self::NUMBER,
            'party_type' => 'customer', 'party_id' => $party->id, 'trx_date' => $date,
        ]))->assertOk();

        $split = $ask($this->dealerB);
        $this->assertSame('split', $split->json('state'), '⛔ অন্য ডিলারের একই নম্বরকে পর্দা "আগে বসেছে" বলে');
        $this->assertStringContainsString($first->document_no, (string) $split->json('message'));
        $this->assertSame(__('accounts::message.same_deposit_also_in', ['no' => $first->document_no]), $split->json('message'));

        $this->assertSame('used', $ask($this->dealerA)->json('state'), '⛔ একই পক্ষের একই নম্বর পর্দায় "আগে বসেনি" দেখায়');
        $this->assertSame('free', $ask($this->dealerA, '2026-08-12')->json('state'), '⛔ অন্য দিনের একই নম্বর পর্দায় জোড়া দেখায়');
    }

    /** ৬ — আটকে থাকা সই-পড়া রসিদ: কমান্ড, শুকনো চালান আর আসল */
    public function test_the_held_receipt_command_is_dry_by_default_and_posts_through_the_normal_door(): void
    {
        $held = $this->receipt($this->dealerA);
        Approval::query()->create([
            'company_id' => $this->company->id,
            'approvable_type' => Voucher::class,
            'approvable_id' => $held->id,
            'module' => VoucherApproval::MODULE,
            'action' => app(VoucherApproval::class)->actionFor($held->fresh()) ?? 'voucher.post',
            'amount' => $held->fresh()->amount,
            'status' => Approval::APPROVED,
            'requested_by' => $this->owner->id,
            'requested_at' => now(),
            'decided_at' => now(),
        ]);

        $this->artisan('accounts:post-held-receipt', ['document_no' => $held->document_no, '--company' => $this->company->code])
            ->assertSuccessful();
        $this->assertSame(DocumentStatus::DRAFT, $held->fresh()->status, '⛔ --apply ছাড়াই কমান্ড রসিদ পোস্ট করল');

        $this->artisan('accounts:post-held-receipt', ['document_no' => $held->document_no, '--company' => $this->company->code, '--apply' => true])
            ->assertSuccessful();
        $this->assertTrue($held->fresh()->isPosted(), '⛔ --apply দিয়েও সই-পড়া রসিদ পোস্ট হল না');

        // ⓘ আবার চালালে কিছুই নয় — পাকা রসিদ দ্বিতীয়বার নয়
        $this->artisan('accounts:post-held-receipt', ['document_no' => $held->document_no, '--company' => $this->company->code, '--apply' => true])
            ->assertFailed();

        // ⓘ সইয়ের অনুরোধ নেই — এই কমান্ডের কাজ নয়
        $plain = $this->receipt($this->dealerB, number: 'NOSIGN-0001');
        $this->artisan('accounts:post-held-receipt', ['document_no' => $plain->document_no, '--company' => $this->company->code, '--apply' => true])
            ->assertFailed();
        $this->assertSame(DocumentStatus::DRAFT, $plain->fresh()->status);
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    private function service(): VoucherService
    {
        return app(VoucherService::class);
    }

    /** @param  array<string, mixed>  $extra */
    private function receipt(Customer $party, ?Account $bank = null, string $date = self::DAY, string $amount = '50000.00', string $number = self::NUMBER, array $extra = []): Voucher
    {
        $bank ??= $this->bank;
        $receivable = (int) StandardChart::accountFor(StandardChart::RECEIVABLE)->id;

        return $this->service()->create(
            [
                'type' => Voucher::RECEIPT, 'trx_date' => $date, 'narration' => 'জমার ভাগ',
                'party_type' => 'customer', 'party_id' => $party->id,
                'instrument' => 'transfer', 'instrument_no' => $number,
                ...$extra,
            ],
            $this->service()->twoLineEntry(Voucher::RECEIPT, $receivable, (int) $bank->id, $amount, 'জমার ভাগ'),
        );
    }

    private function makeBank(string $code): Account
    {
        return Account::query()->create([
            'company_id' => $this->company->id, 'code' => $code, 'name_en' => $code, 'name_bn' => $code,
            'parent_id' => StandardChart::find(StandardChart::BANK)->id, 'type' => Account::ASSET, 'nature' => Account::DEBIT,
            'money_kind' => Account::BANK, 'is_active' => true, 'status' => DocumentStatus::CONFIRMED,
        ]);
    }

    private function mode(string $code): TransferMode
    {
        return TransferMode::query()->create(['company_id' => $this->company->id, 'code' => $code, 'name_en' => $code,
            'name_bn' => $code, 'applies_to' => 'bank', 'is_active' => true]);
    }
}
