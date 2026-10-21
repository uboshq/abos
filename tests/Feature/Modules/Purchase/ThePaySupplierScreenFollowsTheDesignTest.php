<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Purchase\Models\Payment;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Services\PurchaseBillService;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\Supplier\Services\SupplierBank;
use App\Modules\Supplier\Services\SupplierService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * ⭐ টাকা দেওয়া (সরবরাহকারী-পরিশোধ) — মালিকের চূড়ান্ত নকশার দফাগুলো, আসল দরজা দিয়ে (মালিক, ২১ অক্টোবর ২০২৬, fe-র মাধ্যমে;
 * মালিকের-ফাইল/টাকা-নেওয়া-দেওয়া-চূড়ান্ত-নকশা-১০০-২১-অক্টোবর.pdf)। প্রতিটা পরীক্ষার নামে দফার নম্বর।
 *
 * ⓘ পর্দা `purchase.payment.create`, জমা `purchase.payment.store` (`intent=post` হলে রেখে সাথে সাথে পোস্ট), প্রিভিউ
 * `purchase.payment.simulate`। খাতা মাপা হয় খাতার কোড ধরে, এই পরিশোধের দাখিলায়।
 */
final class ThePaySupplierScreenFollowsTheDesignTest extends TestCase
{
    use PutsMoneyInTheTill;
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Supplier $supplier;

    private Product $product;

    private Account $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        app(StandardChart::class)->install();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        // ⓘ নতুন সরবরাহকারী — ডেমোর অন্য বিল মাপে ঢোকে না; পর্দার তালিকায় আসতে ডেমোর প্রথম সরবরাহকারীর ধরন
        $this->supplier = app(SupplierService::class)->create([
            'name_en' => 'Jabed Agro Food', 'branch_id' => $this->company->defaultBranch()?->id, 'credit_limit' => 0, 'credit_days' => 0,
            'party_type_id' => Supplier::query()->orderBy('id')->value('party_type_id'), 'bin' => '000123456-0101', 'tin' => '1234567890',
        ]);
        $this->product = Product::query()->whereNull('tax_id')->where('track_batch', false)->where('track_serial', false)
            ->where('qc_required', false)->orderBy('id')->firstOrFail();
        $this->bank = Account::query()->create([
            'company_id' => $this->company->id, 'code' => '1102-PAY', 'name_en' => 'Pay bank', 'name_bn' => 'পরিশোধের ব্যাংক',
            'parent_id' => StandardChart::find(StandardChart::BANK)->id, 'type' => Account::ASSET, 'nature' => Account::DEBIT,
            'money_kind' => Account::BANK,
        ]);
        app(SettingsService::class)->set('purchase.vat_enabled', true);
    }

    public function test_item_1_and_3_the_head_has_its_three_figures_and_the_open_bills_come_by_due_date(): void
    {
        $later = $this->bill('40000', due: now()->addDays(9));
        $sooner = $this->bill('80000', due: now()->addDays(2));

        $html = (string) $this->get(route('purchase.payment.create', ['purchase_bill_id' => $later->id]))->assertOk()->getContent();

        foreach (['owed', 'this', 'left'] as $figure) {
            $this->assertStringContainsString(e(__('money.screen.head.'.$figure)), $html, "⛔ #১: মাথার অঙ্ক \"{$figure}\" নেই।");
        }
        $this->assertStringContainsString('data-head-owed', $html);

        $first = strpos($html, $sooner->document_no);
        $second = strpos($html, $later->document_no);
        $this->assertNotFalse($first, '⛔ #৩: খোলা বিল পর্দায় আসেনি।');
        $this->assertLessThan($second, $first, '⛔ #৩: আগে যেটার শেষ দিন, সেটা আগে নয়।');
        $this->assertStringContainsString(e(__('money.screen.due_order')), $html);

        // ⭐ "এখন প্রয়োগ" ডানে আটকানো — মাথায়, প্রতিটা সারিতে আর মোটে; মেনু খোলা ১৩৬৬-এ টেবিল সরলেও লেখার ঘর চোখে থাকে
        // (মিল-তালিকার ফাঁক ১, ২২ অক্টোবর ২০২৬)
        $this->assertSame(3, preg_match_all('/<t[hd] class="num sticky end-0[^"]*"[^>]*data-apply-col/', $html),
            '⛔ #৩: "এখন প্রয়োগ" ঘর ডানে আটকানো নয় — মেনু খোলা ১৩৬৬-এ কেটে যায়');
    }

    public function test_item_2_only_the_needed_fields_and_no_paid_to_from_party_box(): void
    {
        $html = (string) $this->get(route('purchase.payment.create'))->assertOk()->getContent();

        $this->assertStringNotContainsString(e(__('accounts::field.paid_to_from_party')), $html, '⛔ #২: "পক্ষ থেকে" ঘর ফিরে এসেছে।');
        foreach (['from_branch', 'from_account_name', 'from_account_no', 'charge_borne_by'] as $gone) {
            $this->assertStringNotContainsString('name="'.$gone.'"', $html, "⛔ #২: সরানো ঘর {$gone} পর্দায়।");
        }
        $this->assertStringContainsString('data-party-type-option="supplier" aria-current="true"', $html, '⛔ #২: পক্ষের ধরনে সরবরাহকারী বাছা নয়।');
        $this->assertStringContainsString('data-method-block="transfer"', $html);

        // ⛔ ব্যাংকে TrxID ছাড়া পোস্ট নয় — কাগজ খসড়াই থাকে, কারণসহ সম্পাদনায় ফেরে
        $this->pay(['instrument' => 'transfer', 'instrument_no' => '', 'amount' => '1000'], [])
            ->assertRedirect()->assertSessionHasErrors('instrument_no');
        $this->assertSame(DocumentStatus::DRAFT, $this->lastPayment()->status, '⛔ #২: রেফারেন্স ছাড়া ব্যাংক-পরিশোধ পোস্ট হল।');
    }

    public function test_item_4_money_beyond_the_bills_sits_as_an_advance_on_the_suppliers_account(): void
    {
        $bill = $this->bill('1000');
        $this->putMoneyIn($this->till(), '5000');

        $this->pay(['account_id' => $this->till()->id, 'amount' => '1500'], [[$bill->id, '1000']])->assertSessionHasNoErrors();

        $payment = $this->lastPayment();
        $this->assertSame(DocumentStatus::CONFIRMED, $payment->status);
        $book = $this->book($payment);
        $this->assertMoved($book, StandardChart::PAYABLE, debit: '1500', message: '⛔ #৪: প্রয়োগের বাইরের ৫০০ সরবরাহকারীর খাতায় (অগ্রিম) বসেনি');
        $this->assertSame(0, bccomp($bill->fresh()->dueAmount(), '0', 4), '⛔ #৪: প্রয়োগ করা বিল শোধ দেখায় না');
    }

    public function test_item_5_and_25_a_bill_not_withheld_is_withheld_once_on_the_payment_at_the_suppliers_rates(): void
    {
        $this->supplier->forceFill(['ait_rate' => '5', 'vds_rate' => '15'])->save();
        $bill = $this->bill('10000', tax: '1500');        // মোট ১১,৫০০ — ভ্যাট বাদে ১০,০০০
        $this->putMoneyIn($this->till(), '20000');

        $this->pay(['account_id' => $this->till()->id, 'amount' => '11500'], [[$bill->id, '11500']])->assertSessionHasNoErrors();

        $payment = $this->lastPayment();
        $book = $this->book($payment);
        $this->assertMoved($book, StandardChart::PAYABLE, debit: '11500', message: '⛔ #৫: সরবরাহকারীর দেনা পুরো অঙ্কে কমেনি');
        $this->assertMoved($book, StandardChart::TAX_DEDUCTED_AT_SOURCE, credit: '500', message: '⛔ #৫: AIT ভ্যাট বাদে মূল্যের ৫% (৫০০) নয়');
        $this->assertMoved($book, StandardChart::VDS_PAYABLE, credit: '225', message: '⛔ #৫: VDS বিলের ভ্যাটের ১৫% (২২৫) নয়, বা ২১২২-এ নয়');
        $this->assertSame(0, bccomp($this->moved($book, (string) $this->till()->code)['credit'], '10775', 4), '⛔ #৫: খাত থেকে নিট (১০,৭৭৫) যায়নি');
        $this->assertSame('payment', $bill->fresh()->withheld_at, '⛔ #৫: বিলে "পরিশোধে কাটা" দাগ বসেনি');
        $this->assertBalanced($book);
    }

    public function test_item_5_a_bill_withheld_on_the_bill_is_not_withheld_again_and_the_net_matches(): void
    {
        $this->supplier->forceFill(['ait_rate' => '5', 'vds_rate' => '15'])->save();
        $bill = $this->bill('10000', extra: ['ait_amount' => '500']);
        $this->putMoneyIn($this->till(), '20000');
        $this->assertSame(0, bccomp($bill->dueAmount(), '9500', 4), 'প্রস্তুতিটাই ভুল — বিলেই কাটা বিলের বাকি নিট নয়');

        $this->pay(['account_id' => $this->till()->id, 'amount' => '9500'], [[$bill->id, '9500']])->assertSessionHasNoErrors();

        $book = $this->book($this->lastPayment());
        $this->assertArrayNotHasKey(StandardChart::TAX_DEDUCTED_AT_SOURCE, $book, '⛔ #৫: বিলেই কাটা কর পরিশোধে আবার কাটা হল');
        $this->assertArrayNotHasKey(StandardChart::VDS_PAYABLE, $book, '⛔ #৫: বিলেই কাটা ভ্যাট পরিশোধে আবার কাটা হল');
        $this->assertSame(0, bccomp($this->moved($book, (string) $this->till()->code)['credit'], '9500', 4), '⛔ #৫: নিট মেলে না');
        $this->assertSame(0, bccomp($bill->fresh()->dueAmount(), '0', 4));
    }

    public function test_item_5_tax_is_never_withheld_twice_even_when_the_bill_changed_after_the_draft(): void
    {
        $this->supplier->forceFill(['ait_rate' => '5'])->save();
        $bill = $this->bill('10000');
        $this->putMoneyIn($this->till(), '20000');

        $this->pay(['account_id' => $this->till()->id, 'amount' => '10000'], [[$bill->id, '10000']], intent: 'draft')->assertSessionHasNoErrors();
        $draft = $this->lastPayment();
        $this->assertSame(0, bccomp((string) $draft->ait_amount, '500', 4), 'প্রস্তুতিটাই ভুল — খসড়ায় কাটা বসেনি');

        // ⓘ খসড়ার পরে বিলে "বিলেই কাটা" দাগ (অন্য পথে) — পোস্টের মুহূর্তে দ্বিতীয়বার কাটা থামে
        $bill->forceFill(['withheld_at' => 'bill'])->save();
        $this->post(route('purchase.payment.confirm', $draft))->assertSessionHasErrors('lines');
        $this->assertSame(DocumentStatus::DRAFT, $draft->fresh()->status, '⛔ #৫: কর দুবার কাটা হল — বিলে আর পরিশোধে');
        $this->assertStringContainsString($bill->document_no, $this->errorOn('lines'), '⛔ #৫: বার্তায় কোন বিলে আগেই কাটা তা নেই');
    }

    /** ⭐ #২৫: হার সরবরাহকারীর রেকর্ডে; হাতে বদলালে কারণ লাগে, আর নিরীক্ষায় আগে-পরে-কারণ থাকে */
    public function test_item_25_a_rate_change_on_the_supplier_needs_a_reason_and_is_audited(): void
    {
        // ⓘ সম্পাদনার ফর্ম যা পাঠায় — সরবরাহকারীর নিজের মান, কেবল হার নতুন
        $form = [...array_filter($this->supplier->only(['code', 'name_en', 'name_bn', 'party_type_id', 'branch_id', 'phone', 'bin', 'tin',
            'credit_limit', 'credit_days']), fn ($v) => $v !== null), 'ait_rate' => '5'];

        $this->from(route('supplier.edit', $this->supplier))->put(route('supplier.update', $this->supplier), $form)
            ->assertSessionHasErrors('rate_reason');
        $this->assertNull($this->supplier->fresh()->ait_rate, '⛔ #২৫: কারণ ছাড়াই উৎসে করের হার বদলে গেল');

        $this->from(route('supplier.edit', $this->supplier))->put(route('supplier.update', $this->supplier), [...$form, 'rate_reason' => 'NBR circular 2026'])
            ->assertSessionHasNoErrors();
        $this->assertSame(0, bccomp((string) $this->supplier->fresh()->ait_rate, '5', 4), '⛔ #২৫: কারণসহও হার বসেনি');
        $this->assertTrue(\App\Models\AuditTrail::query()->where('action', 'withholding_rate')->where('auditable_id', $this->supplier->id)
            ->where('reason', 'like', '%NBR circular 2026%')->exists(), '⛔ #২৫: হার বদল নিরীক্ষার খাতায় কারণসহ নেই');
    }

    public function test_item_6_a_bank_payment_waits_until_the_suppliers_bank_is_signed_by_two(): void
    {
        $bill = $this->bill('1000');

        $this->pay(['instrument' => 'transfer', 'instrument_no' => 'BF261021001'], [[$bill->id, '1000']])->assertSessionHasErrors('payee_bank');
        $this->assertSame(DocumentStatus::DRAFT, $this->lastPayment()->status, '⛔ #৬: সই-না-পাওয়া ব্যাংকে টাকা গেল');

        $this->signBank();
        $this->post(route('purchase.payment.confirm', $this->lastPayment()))->assertSessionHasNoErrors();
        $this->assertSame(DocumentStatus::CONFIRMED, $this->lastPayment()->fresh()->status, '⛔ #৬: সই-পাওয়া ব্যাংকেও পরিশোধ আটকাল');
    }

    public function test_item_9_the_bank_charge_is_our_expense_and_the_supplier_is_cleared_in_full(): void
    {
        $bill = $this->bill('120000');
        $this->signBank();

        $this->pay(['instrument' => 'transfer', 'instrument_no' => 'BF26102100981', 'charge_amount' => '25'], [[$bill->id, '120000']], amount: '120000')
            ->assertSessionHasNoErrors();

        $book = $this->book($this->lastPayment());
        $this->assertMoved($book, StandardChart::PAYABLE, debit: '120000', message: '⛔ #৯: সরবরাহকারীর খাতায় পুরো টাকা কমেনি');
        $this->assertMoved($book, StandardChart::BANK_CHARGES, debit: '25', message: '⛔ #৯: চার্জ ব্যাংক চার্জের খাতে (৫২১০) বসেনি');
        $this->assertSame(0, bccomp($this->moved($book, '1102-PAY')['credit'], '120025', 4), '⛔ #৯: ব্যাংক থেকে টাকা আর চার্জ দুটো যায়নি');
        $this->assertBalanced($book);
    }

    public function test_item_10_the_preview_is_exactly_what_posts(): void
    {
        $this->supplier->forceFill(['ait_rate' => '5', 'vds_rate' => '15'])->save();
        $bill = $this->bill('10000', tax: '1500');
        $this->signBank();
        $data = ['instrument' => 'transfer', 'instrument_no' => 'BF261021777', 'charge_amount' => '25'];

        $preview = $this->post(route('purchase.payment.simulate'), $this->form($data, [[$bill->id, '11500']], '11500'))->assertOk()->json();
        $this->assertTrue($preview['balanced'], '⛔ #১০: প্রিভিউতে ডেবিট-ক্রেডিট মেলে না');
        $this->assertSame(0, LedgerEntry::query()->where('source_type', Payment::drillSourceType())->count(), '⛔ #১০: প্রিভিউ খাতায় কিছু লিখল');

        $this->pay($data, [[$bill->id, '11500']], amount: '11500')->assertSessionHasNoErrors();
        $posted = LedgerEntry::query()->where('source_type', Payment::drillSourceType())->where('source_id', $this->lastPayment()->id)->get();

        $this->assertCount(count($preview['rows']), $posted, '⛔ #১০: প্রিভিউর সারি আর খাতার সারি সংখ্যায় আলাদা');
        $this->assertSame($preview['debit'], \App\Core\Support\Money::format((string) $posted->sum('debit')), '⛔ #১০: প্রিভিউর ডেবিট খাতার নয়');
        $this->assertSame([$preview['ait'], $preview['vds']], ['500.00', '225.00'], '⛔ #১০: প্রিভিউর কাটা পোস্টের হিসাবের নয়');
    }

    /**
     * ⛔ #৫ + #১০, চেকে: কর একবার কাটা, চেক নিটে, আর প্রিভিউ = পরিশোধ + চেকের দুই দাখিলা মিলিয়ে (cb, ২২ অক্টোবর ২০২৬)। আগে চেকের পথ
     * কাটা জানতই না — চেক পুরো ১১,৫০০-এ, ২১২১/২১২২ ফাঁকা, বিলে দাগ নেই। বাতিলে সব উল্টো, আর বিলের দাগ ওঠে।
     */
    public function test_item_5_and_10_a_cheque_withholds_once_pays_the_net_and_matches_its_preview(): void
    {
        $this->supplier->forceFill(['ait_rate' => '5', 'vds_rate' => '15'])->save();
        $bill = $this->bill('10000', tax: '1500');
        $data = ['account_id' => $this->bank->id, 'instrument' => 'cheque', 'instrument_no' => 'CHQ-5501', 'instrument_date' => now()->toDateString()];

        $preview = $this->post(route('purchase.payment.simulate'), $this->form($data, [[$bill->id, '11500']], '11500'))->assertOk()->json();
        $shown = [];
        foreach ($preview['rows'] as $row) {
            $code = strtok((string) $row['account'], ' ');
            $shown[$code] = [
                bcadd($shown[$code][0] ?? '0', str_replace(',', '', $row['debit'] ?: '0'), 2),
                bcadd($shown[$code][1] ?? '0', str_replace(',', '', $row['credit'] ?: '0'), 2),
            ];
        }

        $this->pay($data, [[$bill->id, '11500']], amount: '11500');
        $payment = $this->lastPayment()->fresh(['cheque']);
        $this->assertSame(DocumentStatus::CONFIRMED, $payment->status, '⛔ চেকে কর কাটা পরিশোধ পোস্ট হল না: '.$this->errorOn('amount').$this->errorOn('lines'));
        $this->assertSame(0, bccomp((string) $payment->cheque?->amount, '10775', 4), '⛔ #৫: চেক নিটে (১১,৫০০ − ৫০০ − ২২৫) লেখা হয়নি');

        $posted = $this->bothPapers($payment);
        $this->assertMoved($posted, StandardChart::TAX_DEDUCTED_AT_SOURCE, credit: '500', message: '⛔ #৫: চেকের পথে AIT ২১২১-এ বসেনি');
        $this->assertMoved($posted, StandardChart::VDS_PAYABLE, credit: '225', message: '⛔ #৫: চেকের পথে VDS ২১২২-এ বসেনি');
        $this->assertMoved($posted, StandardChart::PAYABLE, debit: '11500', message: '⛔ #৫: সরবরাহকারীর পুরো ১১,৫০০ মেটেনি');
        $this->assertMoved($posted, StandardChart::CHEQUES_ISSUED, credit: '10775', message: '⛔ #৫: দেওয়া চেকে নিট বসেনি');
        foreach ($posted as $code => $moved) {
            $this->assertSame([bcadd($moved['debit'], '0', 2), bcadd($moved['credit'], '0', 2)], $shown[$code] ?? null,
                "⛔ #১০: চেকে প্রিভিউ যা দেখাল খাতায় তা বসেনি — খাত {$code}");
        }
        $this->assertSame(count($shown), count($posted), '⛔ #১০: প্রিভিউর খাত আর খাতার খাত সংখ্যায় আলাদা');
        $this->assertSame('payment', $bill->fresh()->withheld_at, '⛔ #৫: চেকে কাটার পরেও বিলে "পরিশোধে কাটা" দাগ নেই');

        app(\App\Modules\Purchase\Services\PaymentService::class)->cancel($payment->fresh(), 'Wrong cheque');
        foreach ($this->bothPapers($payment) as $code => $moved) {
            $this->assertSame(0, bccomp($moved['debit'], $moved['credit'], 4), "⛔ বাতিলের পরেও খাত {$code}-এ চেকের পরিশোধের দাগ রয়ে গেল");
        }
        $this->assertNull($bill->fresh()->withheld_at, '⛔ বাতিলের পরেও বিলে "পরিশোধে কাটা" দাগ — বিলে কাটার পথ মিথ্যে বন্ধ');
    }

    /** ⛔ #৫: চেক ফেরত এলে টাকা যায়নি — কাটা কর/ভ্যাটও উল্টো, সরবরাহকারীর পুরো দেনা ফেরে, বিলের দাগ ওঠে */
    public function test_item_5_a_bounced_cheque_undoes_the_tax_it_withheld(): void
    {
        $this->supplier->forceFill(['ait_rate' => '5', 'vds_rate' => '15'])->save();
        $bill = $this->bill('10000', tax: '1500');
        $data = ['account_id' => $this->bank->id, 'instrument' => 'cheque', 'instrument_no' => 'CHQ-5502', 'instrument_date' => now()->toDateString()];

        $this->pay($data, [[$bill->id, '11500']], amount: '11500');
        $payment = $this->lastPayment()->fresh(['cheque']);
        $this->assertSame(DocumentStatus::CONFIRMED, $payment->status, 'প্রস্তুতিটাই ভুল — চেকের পরিশোধ পোস্ট হয়নি');

        app(\App\Modules\Accounts\Services\ChequeService::class)->bounce($payment->cheque()->firstOrFail(), 'তহবিল নেই');

        $book = $this->bothPapers($payment->fresh());
        foreach ([StandardChart::TAX_DEDUCTED_AT_SOURCE, StandardChart::VDS_PAYABLE, StandardChart::PAYABLE] as $code) {
            $this->assertSame(0, bccomp($book[$code]['debit'] ?? '0', $book[$code]['credit'] ?? '0', 4),
                "⛔ #৫: চেক ফেরতের পরেও খাত {$code}-এ পরিশোধের দাগ — টাকা না গিয়েও কর দেনা বা দেনা কম");
        }
        $this->assertNull($bill->fresh()->withheld_at, '⛔ #৫: চেক ফেরতের পরেও বিলে "পরিশোধে কাটা" দাগ');
    }

    /** @return array<string, array{debit: string, credit: string}> পরিশোধ আর তার চেক — দুই কাগজের দাখিলা খাত ধরে */
    private function bothPapers(Payment $payment): array
    {
        $book = $this->book($payment);
        $codes = Account::query()->pluck('code', 'id');
        // ⓘ চেকের নিজের দাখিলা, বাতিলের উল্টো (`cheque:returned`) আর ফেরতের উল্টো (`cheque:bounced`) — [[ChequeService]]
        $cheque = \App\Modules\Accounts\Models\Cheque::drillSourceType();
        LedgerEntry::query()->whereIn('source_type', [$cheque, $cheque.':returned', $cheque.':bounced'])->where('source_id', $payment->cheque_id)->get()
            ->each(function (LedgerEntry $entry) use (&$book, $codes): void {
                $code = (string) $codes[$entry->account_id];
                $book[$code] ??= ['debit' => '0', 'credit' => '0'];
                $book[$code]['debit'] = bcadd($book[$code]['debit'], (string) $entry->debit, 4);
                $book[$code]['credit'] = bcadd($book[$code]['credit'], (string) $entry->credit, 4);
            });

        return $book;
    }

    /**
     * ⛔ #১০: প্রিভিউ ফর্মের একবারের টোকেন খরচ করে না — আগে প্রথম প্রিভিউ টোকেনটা খেয়ে ফেলত, আর পরে "পোস্ট" চাপলে পরিশোধ "জমা
     * হয়েছে" বলে ফিরত, কিছুই না রেখে (মিল-তালিকার সময় ধরা, ২২ অক্টোবর ২০২৬)। একই টোকেনে দুইবার প্রিভিউ, তারপর জমা — জমা হয়।
     */
    public function test_item_10_a_preview_never_spends_the_forms_one_time_token(): void
    {
        $bill = $this->bill('1000');
        $this->putMoneyIn($this->till(), '5000');
        $once = app(\App\Core\Services\FormIsNotSubmittedTwice::class)->newToken();
        $form = [...$this->form(['account_id' => $this->till()->id], [[$bill->id, '1000']], '1000'), '_once' => $once];

        $this->post(route('purchase.payment.simulate'), $form)->assertOk();
        $this->post(route('purchase.payment.simulate'), $form)->assertOk();
        $before = Payment::query()->count();

        $this->from(route('purchase.payment.create'))->post(route('purchase.payment.store'), [...$form, 'intent' => 'post'])
            ->assertSessionHasNoErrors();

        $this->assertSame($before + 1, Payment::query()->count(), '⛔ #১০: প্রিভিউর পরে আসল জমা "আগেই জমা হয়েছে" ধরা পড়ল — কিছুই রাখা হয়নি');
        $this->assertSame(DocumentStatus::CONFIRMED, $this->lastPayment()->status);
    }

    /**
     * ⭐ #১০: প্রিভিউ কিছুই লেখে না — পরিশোধ, খাতা, নিরীক্ষা, নম্বরের সিরিজ সবই আগের মতো (fe, ২২ অক্টোবর ২০২৬; তাই শাখার
     * ছাপের পাহারা থেকে বাদ: [[EveryNewPaperFormCarriesItsBranchTest::EXCLUDED]])।
     */
    public function test_item_10_the_preview_writes_nothing(): void
    {
        $this->supplier->forceFill(['ait_rate' => '5'])->save();
        $bill = $this->bill('10000');
        $count = fn () => [Payment::query()->count(), LedgerEntry::query()->count(),
            \App\Models\AuditTrail::query()->withoutGlobalScopes()->count(), \App\Models\IssuedNumber::query()->withoutGlobalScopes()->count()];
        $before = $count();

        $form = $this->form(['account_id' => $this->till()->id], [[$bill->id, '10000']], '10000');
        $this->post(route('purchase.payment.simulate'), $form)->assertOk();
        $this->post(route('purchase.payment.simulate'), $form)->assertOk();

        $this->assertSame($before, $count(), '⛔ #১০: প্রিভিউ কিছু লিখল — পরিশোধ, খাতা, নিরীক্ষা বা নম্বরের সারি বদলেছে');
    }

    /**
     * ⛔ #১৮: শাখায় সীমিত মানুষ অন্য শাখার সরবরাহকারীর প্রিভিউ দেখেন না, পরিশোধও করেন না — দেনা, কাটা বা ব্যাংক কিছুই ফাঁস নয়
     * (fe, ২২ অক্টোবর ২০২৬; আগে প্রিভিউ `Supplier::find` দিয়ে যেকোনো শাখার সরবরাহকারী খুঁজত)। ⓘ নিজের শাখায় চলে — বাধাটা ঢালাও নয়।
     */
    public function test_item_18_a_branch_clerk_can_neither_preview_nor_pay_another_branchs_supplier(): void
    {
        $mine = \App\Models\Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'MMS')->firstOrFail();
        $theirs = \App\Models\Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'NTK')->firstOrFail();
        $far = app(SupplierService::class)->create(['name_en' => 'Far Mill', 'branch_id' => $theirs->id, 'credit_limit' => 0, 'credit_days' => 0,
            'party_type_id' => $this->supplier->party_type_id]);
        $near = app(SupplierService::class)->create(['name_en' => 'Near Mill', 'branch_id' => $mine->id, 'credit_limit' => 0, 'credit_days' => 0,
            'party_type_id' => $this->supplier->party_type_id]);

        $clerk = $this->someone(['purchase.payment.view', 'purchase.payment.create']);
        $clerk->forceFill(['current_branch_id' => $mine->id])->save();
        \App\Models\UserDataScope::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'user_id' => $clerk->id, 'scope_type' => \App\Models\UserDataScope::BRANCH, 'scope_id' => $mine->id,
        ]);
        CompanyContext::set($this->company->id, $mine->id);
        $this->actingAs($clerk->fresh());

        $own = ['account_id' => $this->till()->id];
        $this->post(route('purchase.payment.simulate'), [...$this->form($own, [], '1000'), 'supplier_id' => $near->id])->assertOk();

        $response = $this->post(route('purchase.payment.simulate'), [...$this->form($own, [], '1000'), 'supplier_id' => $far->id]);
        $this->assertContains($response->status(), [403, 404, 422, 302], '⛔ #১৮: অন্য শাখার সরবরাহকারীর প্রিভিউ খুলল');
        $this->assertStringNotContainsString('Far Mill', (string) $response->getContent(), '⛔ #১৮: প্রিভিউতে অন্য শাখার সরবরাহকারী ফাঁস হল');
        $this->assertStringNotContainsString('"rows"', (string) $response->getContent(), '⛔ #১৮: অন্য শাখার সরবরাহকারীর খাতার সারি ফাঁস হল');

        $before = Payment::query()->withoutGlobalScopes()->count();
        $this->from(route('purchase.payment.create'))
            ->post(route('purchase.payment.store'), [...$this->form($own, [], '1000'), 'supplier_id' => $far->id, 'intent' => 'post'])
            ->assertSessionHasErrors('supplier_id');
        $this->assertSame($before, Payment::query()->withoutGlobalScopes()->count(), '⛔ #১৮: অন্য শাখার সরবরাহকারীকে পরিশোধ বানানো গেল');
    }

    public function test_item_11_the_main_button_and_the_controls_follow_the_companys_signature_setting(): void
    {
        $html = (string) $this->get(route('purchase.payment.create'))->assertOk()->getContent();

        $this->assertStringContainsString(e(__('money.screen.control.signatures_off')), $html, '⛔ #১১: সই বন্ধ, অথচ নিয়ন্ত্রণে তা লেখা নেই');
        $this->assertMatchesRegularExpression('/data-pay-primary[^>]*>\s*'.preg_quote(e(__('money.screen.post')), '/').'\s*</u', $html,
            '⛔ #১১: সই বন্ধ কোম্পানিতে মূল বোতাম "পোস্ট" নয়');
        $this->assertStringContainsString(e(__('money.screen.draft')), $html);
    }

    public function test_item_24_the_paying_accounts_balance_is_on_the_screen(): void
    {
        $this->putMoneyIn($this->till(), '8420');

        $html = (string) $this->get(route('purchase.payment.create'))->assertOk()->getContent();

        $this->assertStringContainsString('"'.$this->till()->id.'":{"kind":"cash","balance":"8420.00"}', html_entity_decode($html),
            '⛔ #২৪: যে খাত থেকে দেওয়া হবে তাতে কত আছে, পর্দা জানে না');
        $this->assertStringContainsString('data-account-balance', $html);
    }

    // ── সহায়ক ────────────────────────────────────────────────────────────

    /** @param  array<string, mixed>  $extra */
    private function bill(string $rate, string $tax = '0', ?\DateTimeInterface $due = null, array $extra = []): PurchaseBill
    {
        $bills = app(PurchaseBillService::class);
        $bill = $bills->create([
            'supplier_id' => $this->supplier->id, 'trx_date' => now()->toDateString(),
            'due_on' => ($due ?? now()->addDays(5))->format('Y-m-d'), ...$extra,
        ], [['product_id' => $this->product->id, 'qty' => '1', 'rate' => $rate, 'tax' => $tax]]);

        return $bills->confirm($bill->fresh())->fresh();
    }

    /**
     * ⓘ সেশনের ভুলের প্রথম বার্তা — সেশন JSON-এ রাখা, তাই পরীক্ষার স্মৃতিতে থলেটা কখনো array
     * ([[json-session-turns-errors-into-an-array-in-tests]]; দুই রূপই পড়া হয়)।
     */
    private function errorOn(string $field): string
    {
        $errors = session('errors');

        if ($errors instanceof \Illuminate\Support\ViewErrorBag) {
            return (string) $errors->first($field);
        }

        return (string) ($errors['default']['messages'][$field][0] ?? '');
    }

    private function till(): Account
    {
        return app(CashTillService::class)->ensurePrimaryTill()->account;
    }

    /**
     * @param  array<string, mixed>  $fields
     * @param  list<array{0: int, 1: string}>  $lines
     */
    private function pay(array $fields, array $lines, string $intent = 'post', ?string $amount = null): TestResponse
    {
        return $this->from(route('purchase.payment.create'))
            ->post(route('purchase.payment.store'), [...$this->form($fields, $lines, $amount ?? (string) ($fields['amount'] ?? '1000')), 'intent' => $intent]);
    }

    /**
     * @param  array<string, mixed>  $fields
     * @param  list<array{0: int, 1: string}>  $lines
     * @return array<string, mixed>
     */
    private function form(array $fields, array $lines, string $amount): array
    {
        return [
            'supplier_id' => $this->supplier->id, 'account_id' => $this->bank->id, 'trx_date' => now()->toDateString(),
            'instrument' => 'cash', ...$fields, 'amount' => $amount,
            'lines' => array_map(fn (array $l) => ['purchase_bill_id' => $l[0], 'amount' => $l[1]], $lines),
        ];
    }

    private function lastPayment(): Payment
    {
        return Payment::query()->latest('id')->firstOrFail();
    }

    /** @return array<string, array{debit: string, credit: string}> খাতার কোড → এই পরিশোধে নড়াচড়া */
    private function book(Payment $payment): array
    {
        $book = [];
        $codes = Account::query()->pluck('code', 'id');
        LedgerEntry::query()->where('source_type', Payment::drillSourceType())->where('source_id', $payment->id)->get()
            ->each(function (LedgerEntry $entry) use (&$book, $codes): void {
                $code = (string) $codes[$entry->account_id];
                $book[$code] ??= ['debit' => '0', 'credit' => '0'];
                $book[$code]['debit'] = bcadd($book[$code]['debit'], (string) $entry->debit, 4);
                $book[$code]['credit'] = bcadd($book[$code]['credit'], (string) $entry->credit, 4);
            });

        return $book;
    }

    /** @param  array<string, array{debit: string, credit: string}>  $book */
    private function moved(array $book, string $code): array
    {
        $this->assertArrayHasKey($code, $book, "খাত {$code} এই পরিশোধে নড়েইনি: ".json_encode($book));

        return $book[$code];
    }

    /** @param  array<string, array{debit: string, credit: string}>  $book */
    private function assertMoved(array $book, string $code, string $debit = '0', string $credit = '0', string $message = ''): void
    {
        $row = $this->moved($book, $code);
        $this->assertSame(0, bccomp($row['debit'], $debit, 4), $message." — {$code} ডেবিট {$row['debit']}, চাওয়া {$debit}");
        $this->assertSame(0, bccomp($row['credit'], $credit, 4), $message." — {$code} ক্রেডিট {$row['credit']}, চাওয়া {$credit}");
    }

    /** @param  array<string, array{debit: string, credit: string}>  $book */
    private function assertBalanced(array $book): void
    {
        $this->assertSame(0, bccomp(array_reduce($book, fn ($s, $r) => bcadd($s, $r['debit'], 4), '0'),
            array_reduce($book, fn ($s, $r) => bcadd($s, $r['credit'], 4), '0'), 4), '⛔ #২৫: ডেবিট আর ক্রেডিট মেলে না');
    }

    /** ⓘ দুই হাতে সই-পাওয়া ব্যাংক তথ্য — fe-র [[SupplierBank]] দিয়ে */
    private function signBank(): void
    {
        $bank = app(SupplierBank::class);
        $detail = $bank->request($this->supplier, [
            'bank_name' => 'Sonali Bank', 'branch_name' => 'Motijheel', 'account_name' => 'Jabed Agro Food Processing Ltd',
            'account_no' => '0012345674521', 'routing_no' => '200274466', 'reason' => 'Bank letter',
        ], $this->someone(['supplier.view', 'supplier.update', 'supplier.bank.view']));
        $bank->sign($detail, $this->someone(['supplier.view', 'supplier.bank.approve', 'supplier.bank.view']));
    }

    /** @param  list<string>  $keys */
    private function someone(array $keys): User
    {
        $user = User::factory()->create([
            'current_company_id' => $this->company->id, 'current_branch_id' => $this->company->defaultBranch()?->id, 'is_active' => true,
        ]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);
        CompanyContext::forCompany($this->company->id, function () use ($user, $keys): void {
            foreach ($keys as $key) {
                $user->givePermissionTo(Permission::findOrCreate($key, 'web'));
            }
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }
}
