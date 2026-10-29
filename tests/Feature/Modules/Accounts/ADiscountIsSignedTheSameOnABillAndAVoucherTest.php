<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Events\ApprovalDecided;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Accounts\Services\VoucherWriter;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\SalesInvoiceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⭐ ছাড়ের মালিকের সই — বিক্রির বিলে আর রসিদ ভাউচারে হুবহু একই ফল (মালিকের নিয়ম ১–২ অক্টোবর: যেকোনো ছাড়ে মালিকের সই, ৳০.৫০ পর্যন্ত
 * রাউন্ডিং মুক্ত; fe, ২৫ অক্টোবর ২০২৬: ভাউচারের পথ লাইভের SalesInvoiceService::assertDiscountApproved()-এর হুবহু)।
 *
 * দাবি — প্রতিটা অবস্থা দুই কাগজে চালিয়ে, ফল একই:
 *   ১. ছক নেই (বা বন্ধ) → ফেরত, "সইদাতা নেই" — চুপচাপ পোস্ট নয় (fail-closed)
 *   ২. ছক চালু → সইয়ের অপেক্ষায়
 *   ৩. সই পড়লে → পার
 *   ৪. সইয়ের পরে অঙ্ক বদলালে → আবার সইয়ের অপেক্ষায়
 *   ৫. রাউন্ডিং ৳০.৫০ → মুক্ত; ৳০.৫১ → সইয়ের অপেক্ষায়
 */
final class ADiscountIsSignedTheSameOnABillAndAVoucherTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $signer;

    private ApprovalFlow $flow;

    private Account $till;

    private int $refs = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        app(StandardChart::class)->install();

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->signer = User::factory()->create(['current_company_id' => $company->id]);
        $this->signer->companies()->attach($company->id);
        $this->signer->givePermissionTo(['approval.view', 'approval.decide']);

        $this->flow = ApprovalFlow::query()->where('module', 'sales')->where('action', 'discount')->firstOrFail();
        $this->flow->update(['threshold_amount' => '0', 'is_active' => true]);
        $this->flow->steps()->delete();
        ApprovalFlowStep::create(['approval_flow_id' => $this->flow->id, 'level' => 1, 'approver_type' => ApprovalFlowStep::BY_USER,
            'approver_id' => $this->signer->id, 'requires_all' => false]);

        Customer::query()->orderBy('id')->firstOrFail()->forceFill(['credit_limit' => '10000000'])->save();
        $this->till = Account::query()->where('money_kind', Account::CASH)->postable()->active()->orderBy('code')->firstOrFail();
        $this->actingAs($this->owner);
    }

    /** ১ */
    public function test_no_flow_refuses_both_with_no_signer(): void
    {
        $this->flow->update(['is_active' => false]);

        $this->same('no_signer', $this->billOutcome($this->bill('100')), $this->voucherOutcome($this->voucher('discount', '50')), 'ছক নেই');
    }

    /** ২ */
    public function test_a_flow_holds_both(): void
    {
        $this->same('held', $this->billOutcome($this->bill('100')), $this->voucherOutcome($this->voucher('discount', '50')), 'ছক চালু');
    }

    /** ৩ */
    public function test_once_signed_both_pass(): void
    {
        $bill = $this->bill('100');
        $this->billOutcome($bill);
        $this->approveFor($bill);

        $voucher = $this->voucher('discount', '50');
        $this->approveFor($voucher);
        $voucher = $voucher->fresh();
        // ⓘ শেষ সইয়ে ভাউচার নিজে পোস্ট হয়; না হলে (তিন হাতের নিয়মে) মালিক চাপেন — বিলের মতোই
        $voucherSays = $voucher->isDraft() ? $this->voucherPost($voucher) : 'passes';

        $this->same('passes', $this->billOutcome($bill->fresh()), $voucherSays, 'সই পড়ার পরে');
    }

    /** ৪ */
    public function test_a_changed_amount_after_signing_holds_both_again(): void
    {
        $service = app(SalesInvoiceService::class);
        $bill = $this->bill('100');
        $this->billOutcome($bill);
        $this->approveFor($bill);
        $bill = $service->update($bill->fresh(), $this->header(), [$this->line('200')]);

        // ⓘ শেষ সইয়ের নিজে-পোস্ট বন্ধ রেখে — সই-পাওয়া খসড়ার অঙ্ক বদলানো যায় (বিলের মতোই)
        Event::fake([ApprovalDecided::class]);
        $voucher = $this->voucher('discount', '50', draft: true);
        $this->voucherPost($voucher);
        $this->approveFor($voucher);
        $data = $this->voucherData('discount', '60');
        $writer = app(VoucherWriter::class);
        app(VoucherService::class)->update($voucher->fresh(), $data, $writer->linesFor(Voucher::RECEIPT, $data));

        $this->same('held', $this->billOutcome($bill->fresh()), $this->voucherPost($voucher->fresh()), 'সইয়ের পরে অঙ্ক বদলাল');
    }

    /** ৫ */
    public function test_rounding_of_fifty_paisa_is_free_and_above_it_held_on_both(): void
    {
        $this->same('passes', $this->billOutcome($this->bill('0', '0.50')), $this->voucherOutcome($this->voucher('rounding', '0.50')), 'রাউন্ডিং ৳০.৫০');
        $this->same('held', $this->billOutcome($this->bill('0', '0.51')), $this->voucherOutcome($this->voucher('rounding', '0.51')), 'রাউন্ডিং ৳০.৫১');
    }

    // ── প্রস্তুতি ────────────────────────────────────────────────────────

    private function same(string $expected, string $bill, string $voucher, string $case): void
    {
        $this->assertSame($expected, $bill, "দৃশ্যটাই ঠিক নয় — {$case}: বিক্রির বিল নিজেই \"{$expected}\" নয়, \"{$bill}\"");
        $this->assertSame($bill, $voucher, "⛔ {$case}: ভাউচার বিক্রির বিলের মতো নয় — বিল \"{$bill}\", ভাউচার \"{$voucher}\"");
    }

    /** ⓘ বিলের ফল — পাকা হলে passes; ছাড়ের ঘরে সইদাতা নেই → no_signer; সইয়ের অপেক্ষা → held */
    private function billOutcome(SalesInvoice $bill): string
    {
        try {
            app(SalesInvoiceService::class)->confirm($bill->fresh());
        } catch (ValidationException $e) {
            $said = (string) ($e->errors()['discount'][0] ?? '');

            return match ($said) {
                __('sales::validation.discount_no_signer') => 'no_signer',
                __('sales::validation.discount_awaiting') => 'held',
                default => 'other: '.json_encode($e->errors(), JSON_UNESCAPED_UNICODE),
            };
        }

        return $bill->fresh()->status === DocumentStatus::CONFIRMED ? 'passes' : 'stayed: '.$bill->fresh()->status;
    }

    private function voucherOutcome(Voucher|string $voucher): string
    {
        return $voucher instanceof Voucher ? ($voucher->fresh()->isDraft() ? 'held' : 'passes') : $voucher;
    }

    private function voucherPost(Voucher $voucher): string
    {
        try {
            $held = app(VoucherWriter::class)->post($voucher->fresh());
        } catch (ValidationException $e) {
            return $this->voucherRefusal($e);
        }

        return $held !== null || $voucher->fresh()->isDraft() ? 'held' : 'passes';
    }

    private function voucherRefusal(ValidationException $e): string
    {
        $said = (string) ($e->errors()['difference.amount'][0] ?? '');

        return str_contains($said, (string) __('accounts::validation.difference_discount_no_signer'))
            || $said === __('accounts::validation.difference_rounding_no_signer', ['amount' => '0.51', 'free' => '0.50'])
            ? 'no_signer' : 'other: '.json_encode($e->errors(), JSON_UNESCAPED_UNICODE);
    }

    /** ⓘ লেখা আর পোস্ট — লেখক মালিক, কাউন্টারে নগদ; ফেরত এলে তার কারণ */
    private function voucher(string $kind, string $amount, bool $draft = false): Voucher|string
    {
        $data = $this->voucherData($kind, $amount);
        $writer = app(VoucherWriter::class);

        try {
            [$voucher] = $writer->store($data, $writer->linesFor(Voucher::RECEIPT, $data), $draft);
        } catch (ValidationException $e) {
            return $this->voucherRefusal($e);
        }

        return $voucher;
    }

    /** @return array<string, mixed> */
    private function voucherData(string $kind, string $amount): array
    {
        return ['type' => Voucher::RECEIPT, 'trx_date' => now()->toDateString(), 'narration' => 'ছাড়ের সই', 'party_type' => 'customer',
            'party_id' => Customer::query()->orderBy('id')->value('id'), 'to_account_id' => $this->till->id,
            'from_account_id' => StandardChart::find(StandardChart::RECEIVABLE)->id, 'instrument' => 'cash', 'amount' => '1000',
            'difference_kind' => $kind, 'difference_amount' => $amount];
    }

    private function approveFor(SalesInvoice|Voucher|string $paper): void
    {
        if (is_string($paper)) {
            $this->fail('দৃশ্যটাই ঠিক নয় — কাগজটাই লেখা হয়নি: '.$paper);
        }
        $approval = Approval::query()->where('approvable_type', $paper::class)->where('approvable_id', $paper->id)
            ->where('action', 'discount')->where('status', Approval::PENDING)->latest('id')->firstOrFail();
        app(ApprovalEngine::class)->approve($approval, $this->signer, 'ঠিক আছে');
    }

    private function bill(string $discount, string $rounding = '0'): SalesInvoice
    {
        return app(SalesInvoiceService::class)->create([...$this->header(), 'rounding_amount' => $rounding], [$this->line($discount)]);
    }

    /** @return array<string, mixed> */
    private function header(): array
    {
        return ['customer_id' => Customer::query()->orderBy('id')->value('id'), 'warehouse_id' => Warehouse::query()->orderBy('id')->value('id'),
            'trx_date' => now()->toDateString()];
    }

    /** @return array<string, mixed> */
    private function line(string $discount): array
    {
        return ['product_id' => Product::query()->orderBy('id')->value('id'), 'qty' => '50', 'rate' => '1000', 'discount' => $discount];
    }
}
