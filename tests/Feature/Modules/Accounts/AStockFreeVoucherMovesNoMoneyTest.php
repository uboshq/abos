<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Services\SettingsService;
use App\Models\LedgerEntry;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\NoteLine;
use App\Modules\Accounts\Models\TradeVoucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\TradeVoucherService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\WritesNotesAgainstBills;
use Tests\TestCase;

/**
 * ⭐ স্টক ছাড়া বিক্রয় ভাউচার (SV) আর ক্রয় ভাউচার (PUV) — মালিকের নকশা v5, ২৩ নভেম্বর ২০২৬ ([[TradeVoucherService]])।
 *
 * দাবি:
 *  - SV: Dr ১১১০ (গ্রাহক) / Cr সারির আয়ের খাত / Cr ২১২০ — নগদ, ব্যাংক বা MFS-এর কোনো খাত ছোঁয় না; ফর্মে টাকার মাধ্যম
 *    পাঠালেও উপেক্ষা;
 *  - SV বিলের একই বাকির দেয়াল মানে — সীমা পেরোলে থামে, খাতায় কিছুই নয়;
 *  - PUV: Dr খরচের খাত (+ হিসাবে-কেবল পণ্য ৫১০০) · Dr ১১৩৯ / Cr ২১১৮ (মোট − উৎসে কর) · Cr ২১২১ উৎসে কর; উৎসে ভ্যাট (২১২২) নয়;
 *  - সুইচ `accounts.stock_free_vouchers` বন্ধ: নতুন কাগজ থামে, ফর্মের দরজা ফেরায়; চালু: চলে।
 */
final class AStockFreeVoucherMovesNoMoneyTest extends TestCase
{
    use RefreshDatabase;
    use WritesNotesAgainstBills;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTheNoteScene();
    }

    public function test_the_sales_voucher_books_a_receivable_and_ignores_any_money_fields(): void
    {
        $this->switchOn();
        $cash = Account::query()->where('money_kind', Account::CASH)->where('is_group', false)->orderBy('code')->firstOrFail();
        $bank = Account::query()->where('money_kind', Account::BANK)->where('is_group', false)->orderBy('code')->first();

        $this->post(route('accounts.trade_voucher.store', ['kind' => TradeVoucher::SALE]), [
            'party_id' => $this->customer->id,
            'narration' => 'স্টক ছাড়া বিক্রি',
            'lines' => [
                ['type' => NoteLine::ACCOUNT, 'account_id' => $this->accountId('4300'), 'amount' => '500', 'tax_id' => $this->vat15->id],
            ],
            // ⛔ টাকার ঘর নেই — বানিয়ে পাঠালেও খাতায় যায় না
            'payment_mode' => 'cash',
            'from_account_id' => $cash->id,
            'to_account_id' => $cash->id,
            'bank_account_id' => $bank?->id,
            'paid_amount' => '575',
            'submit' => 'send',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $voucher = TradeVoucher::query()->latest('id')->firstOrFail();
        $this->assertTrue($voucher->isConfirmed(), 'দৃশ্যটাই ভুল — SV খাতায় ওঠেনি।');
        $this->assertSame('sales_voucher', $voucher->sourceType());

        $this->assertMoney('575', $this->sideOn('sales_voucher', $voucher->id, StandardChart::RECEIVABLE, 'debit', ['customer', (int) $this->customer->id]));
        $this->assertMoney('500', $this->sideOn('sales_voucher', $voucher->id, '4300', 'credit'));
        $this->assertMoney('75', $this->sideOn('sales_voucher', $voucher->id, StandardChart::VAT_PAYABLE, 'credit'));
        $this->assertSame([StandardChart::RECEIVABLE, StandardChart::VAT_PAYABLE, '4300'], $this->codesOn('sales_voucher', $voucher->id));

        $moneyAccounts = Account::query()->whereNotNull('money_kind')->pluck('id');
        $this->assertSame(0, LedgerEntry::query()->where('source_type', 'sales_voucher')->where('source_id', $voucher->id)
            ->whereIn('account_id', $moneyAccounts)->count(), '⛔ SV নগদ/ব্যাংক/MFS-এর খাত ছুঁয়েছে — টাকা আসে কেবল রসিদে।');
    }

    public function test_the_sales_voucher_stops_at_the_credit_limit_and_posts_nothing(): void
    {
        $this->switchOn();
        app(SettingsService::class)->set('customer.credit_limit_enabled', true);
        app(SettingsService::class)->flush();
        $this->customer->forceFill(['credit_limit' => '100'])->save();

        $service = app(TradeVoucherService::class);
        $voucher = $service->create(TradeVoucher::SALE, [
            'party_id' => $this->customer->id,
            'narration' => 'সীমার বাইরে',
            'lines' => [['type' => NoteLine::ACCOUNT, 'account_id' => $this->accountId('4300'), 'amount' => '500']],
        ]);

        try {
            $service->confirm($voucher);
            $this->fail('⛔ বাকির সীমা ১০০, অথচ ৫০০ টাকার SV খাতায় উঠল।');
        } catch (ValidationException) {
        }

        $this->assertTrue($voucher->fresh()->isDraft());
        $this->assertSame(0, LedgerEntry::query()->where('source_type', 'sales_voucher')->where('source_id', $voucher->id)->count(),
            '⛔ থামানো SV-র সারি খাতায় বসল।');
    }

    public function test_the_purchase_voucher_deducts_tax_at_the_bill_and_no_vds(): void
    {
        $this->switchOn();

        $service = app(TradeVoucherService::class);
        $voucher = $service->confirm($service->create(TradeVoucher::PURCHASE, [
            'party_id' => $this->supplier->id,
            'narration' => 'স্টক ছাড়া কেনা',
            'ref_no' => 'THEIR-BILL-1',
            'tds_amount' => '50',
            'lines' => [
                ['type' => NoteLine::ACCOUNT, 'account_id' => $this->accountId(StandardChart::MARKETING), 'amount' => '1000', 'tax_id' => $this->vat15->id],
                ['type' => NoteLine::PRODUCT, 'product_id' => $this->product->id, 'qty' => '2', 'rate' => '100'],
            ],
        ]));

        $this->assertTrue($voucher->isConfirmed());
        $this->assertSame('purchase_voucher', $voucher->sourceType());
        $this->assertMoney('1350', (string) $voucher->total);
        $this->assertMoney('1300', (string) $voucher->party_total);

        $supplier = ['supplier', (int) $this->supplier->id];
        $this->assertMoney('1000', $this->sideOn('purchase_voucher', $voucher->id, StandardChart::MARKETING, 'debit'));
        $this->assertMoney('200', $this->sideOn('purchase_voucher', $voucher->id, StandardChart::COST_OF_GOODS_SOLD, 'debit'), '⛔ হিসাবে-কেবল পণ্য ৫১০০-এ নয়।');
        $this->assertMoney('150', $this->sideOn('purchase_voucher', $voucher->id, StandardChart::INPUT_VAT_RECOVERABLE, 'debit'));
        $this->assertMoney('1300', $this->sideOn('purchase_voucher', $voucher->id, StandardChart::VENDOR_PAYABLE, 'credit', $supplier), '⛔ দেনা মোট − উৎসে কর নয়।');
        $this->assertMoney('50', $this->sideOn('purchase_voucher', $voucher->id, StandardChart::TAX_DEDUCTED_AT_SOURCE, 'credit'));
        $this->assertMoney('0', $this->sideOn('purchase_voucher', $voucher->id, StandardChart::VDS_PAYABLE, 'credit'), '⛔ PUV-তে উৎসে ভ্যাট কাটা হলো — সেটা পরিশোধে।');

        $this->assertSame(0, LedgerEntry::query()->where('source_type', 'purchase_voucher')->where('source_id', $voucher->id)
            ->whereIn('account_id', Account::query()->whereNotNull('money_kind')->pluck('id'))->count(), '⛔ PUV টাকার খাত ছুঁয়েছে।');
    }

    public function test_with_the_switch_off_no_voucher_is_written_and_the_form_turns_you_back(): void
    {
        app(SettingsService::class)->set(TradeVoucherService::SWITCH, false);
        app(SettingsService::class)->flush();

        try {
            app(TradeVoucherService::class)->create(TradeVoucher::SALE, [
                'party_id' => $this->customer->id, 'narration' => 'বন্ধ',
                'lines' => [['type' => NoteLine::ACCOUNT, 'account_id' => $this->accountId('4300'), 'amount' => '10']],
            ]);
            $this->fail('⛔ সুইচ বন্ধ, তবু SV লেখা গেল।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('kind', $e->errors());
        }

        $response = $this->get(route('accounts.trade_voucher.create', ['kind' => TradeVoucher::SALE]));

        // ⓘ সুইচের পাহারা ([[RefuseSwitchedOffScreens]]) আগেই ৪০৪ দিতে পারে; না দিলে কন্ট্রোলার তালিকায় ফেরায়
        $this->assertContains($response->getStatusCode(), [302, 404], '⛔ সুইচ বন্ধ, তবু SV-র ফর্ম খুলল।');

        if ($response->getStatusCode() === 302) {
            $response->assertRedirect(route('accounts.trade_voucher.index', ['kind' => TradeVoucher::SALE]));
        }

        $this->assertSame(0, TradeVoucher::query()->count());
    }

    public function test_with_the_switch_on_a_voucher_is_written(): void
    {
        $this->switchOn();

        $voucher = app(TradeVoucherService::class)->create(TradeVoucher::SALE, [
            'party_id' => $this->customer->id, 'narration' => 'চালু',
            'lines' => [['type' => NoteLine::ACCOUNT, 'account_id' => $this->accountId('4300'), 'amount' => '10']],
        ]);

        $this->assertTrue($voucher->isDraft());
        $this->assertStringContainsString('SV', (string) $voucher->document_no);
    }

    private function switchOn(): void
    {
        app(SettingsService::class)->set(TradeVoucherService::SWITCH, true);
        app(SettingsService::class)->flush();
        $this->assertTrue(app(TradeVoucherService::class)->isOn(), 'দৃশ্যটাই ভুল — সুইচ চালু হয়নি।');
    }
}
