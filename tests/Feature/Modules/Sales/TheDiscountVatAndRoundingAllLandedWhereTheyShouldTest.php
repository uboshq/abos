<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Approval;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\OpeningBalanceService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\CostLayerService;
use App\Modules\Inventory\Services\ProductService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\MasterData\Models\PaymentMethod;
use App\Modules\MasterData\Models\Tax;
use App\Modules\MasterData\Models\Unit;
use App\Modules\Sales\Models\SalesInvoice;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ChecksTheFiveMatches;
use Tests\Concerns\PrintsTheStandardPaper;
use Tests\Concerns\SignsTheDiscountAsTheOwner;
use Tests\TestCase;

/**
 * ⭐ সারির ছাড়, বিলের ছাড়, ভ্যাট আর রাউন্ডিং — খাতে ঠিকমতো যায়, আর ছাপা
 * বিলের মোট খাতার মোটের সমান।
 *
 * চেকলিস্ট "ব্যবসা চালু, সরাসরি ক্রয় ও বিক্রয়" §৩, মালিকের নির্দেশ
 * ২৭ সেপ্টেম্বর ২০২৬: *"business zehetu asol hisab tai gormil holeei bipod"*।
 *
 * ── ⓘ পদ্ধতি ──────────────────────────────────────────────────────────
 * প্রতিটা বিক্রি **কাউন্টারের আসল দরজা দিয়ে** (HTTP, `sales.direct.store`),
 * মালিকের লগইনে, ডেমোর TDEPOT কোম্পানিতে। তারপর তিন স্তরে মাপা:
 *   ক · এই বিক্রির নতুন দাখিলাগুলো — **প্রতিটা** খাতের নড়াচড়া হুবহু হাতের
 *       অঙ্ক; তালিকার বাইরের কোনো খাত নড়লেও লাল
 *   খ · §১-এর পাঁচ মিল ([[ChecksTheFiveMatches]]) — গোটা কোম্পানির জের
 *       = আগের জের (মাপা) + হাতে গোনা নড়াচড়া
 *   গ · ছাপা কাগজ — বিলের ছাপার পাতা **আসল টেমপ্লেটে আঁকা HTML** থেকে
 *       মোটের ছকটা পড়ে, প্রতিটা সারি হাতে লেখা স্ট্রিংয়ের সাথে (লাখ-কোটির
 *       কমা, পয়সাসহ) — আর মোটের সারি = খাতায় পাওনার ডেবিট
 *
 * ⛔ প্রত্যাশিত অঙ্ক আর ছাপা স্ট্রিং **আক্ষরিক**, কোডের সূত্র বা
 * `Money::format()` দিয়ে বানানো নয় — নইলে কোডের ভুলটাই নকল হত।
 *
 * ── ⓘ ঘরের নিয়ম আজ যা (কোড পড়ে, [[SalesInvoiceService::postToLedger()]]) ──
 * পাওনা (১১১০) ডেবিট = বিলের মোট; ভ্যাট প্রদেয় (২১২০) ক্রেডিট = ভ্যাট;
 * বিক্রয় (৪১০০) ক্রেডিট = মোট − ভ্যাট। অর্থাৎ সারির ছাড়, বিলের ছাড় আর
 * রাউন্ডিং — তিনটাই **নিট বিক্রয়ে** মিশে যায়; আলাদা "ছাড় প্রদত্ত" (৫৩০০)
 * বা রাউন্ডিং খাত নড়ে না। ⚠️ এই পরীক্ষা সেটাই দাবি করে (৫৩০০ শূন্য নড়ে),
 * কারণ আজকের নকশা সেটা — আলাদা খাত চাইলে সেটা মালিকের সিদ্ধান্ত, আর তখন
 * এই দাবিগুলো সেই সিদ্ধান্তের সাথে বদলাবে।
 */
final class TheDiscountVatAndRoundingAllLandedWhereTheyShouldTest extends TestCase
{
    use ChecksTheFiveMatches;
    use PrintsTheStandardPaper;
    use RefreshDatabase;
    use SignsTheDiscountAsTheOwner;

    private Company $company;

    private User $owner;

    private Warehouse $warehouse;

    private Customer $dealer;

    /** ছাপার পাতা যে ভাষায় আঁকা হয়েছে — লেবেলগুলো সেই ভাষাতেই মেলানো হয়। */
    private string $printLocale = 'en';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        /* ⓘ বিলের ডিফল্ট ২৯ সেপ্টেম্বর থেকে নকশা; এই দাবি সাধারণ কাগজের মোট পড়ে ([[PrintsTheStandardPaper]]) */
        $this->printTheStandardPaper();

        app(StandardChart::class)->install();

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();

        $this->dealer = Customer::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->company->defaultBranch()?->id,
            'code' => 'CUS-DVR',
            'name_en' => 'Discount VAT Rounding Dealer',
            'credit_limit' => '0',
            'credit_days' => 0,
            'status' => DocumentStatus::CONFIRMED,
            'is_active' => true,
        ]);

        // ⓘ শূন্য সীমায় জন্মায় (নিরীক্ষা §১.২) — সীমা পরে সরাসরি
        $this->dealer->forceFill(['credit_limit' => '5000'])->save();
        $this->dealer = $this->dealer->fresh();
    }

    // ══ ক · কেবল সারির ছাড় ════════════════════════════════════════════════

    /**
     * হাতে গোনা (ভ্যাট নেই; স্তর ২০টা @ ১০০):
     *   ৬ × ১৫০ = ৯০০ · সারির ছাড় ১০% = ৯০ → ৮১০ — নগদে পুরোটা
     *   বিক্রয় ৮১০ · খরচ ৬ × ১০০ = ৬০০ · লাভ ২১০
     */
    public function test_a_line_discount_alone_nets_the_sale_and_the_printed_total(): void
    {
        $plain = $this->product('DVR-PLAIN', '150', null, '20', '100');
        $before = $this->snapshot();

        $this->sell([$this->line($plain, '6', '150', '10')], [$this->cash('810')])->assertSessionHasNoErrors();

        $invoice = $this->lastInvoice();

        $this->assertInvoiceFigures($invoice, subtotal: '900', discount: '90', tax: '0', billDiscount: '0', rounding: '0', total: '810');

        $this->assertTheNewEntriesAre($before['id'], [
            $this->tillCode() => '810',
            StandardChart::SALES => '-810',
            StandardChart::COST_OF_GOODS_SOLD => '600',
            StandardChart::INVENTORY => '-600',
        ]);

        $this->assertFiveMatches($before, [
            StandardChart::SALES => '-810',
            StandardChart::VAT_PAYABLE => '0',
            StandardChart::DISCOUNT_GIVEN => '0',
            StandardChart::COST_OF_GOODS_SOLD => '600',
            StandardChart::INVENTORY => '-600',
        ], stock: [$plain->id => ['qty' => '14', 'value' => '1400']], dealer: '0', cash: '810', profit: '210');

        $this->assertTheReceivableDebitIs($invoice, '810');
        $this->assertPrinted($invoice, 'a4', [
            'core.print.subtotal' => '900.00',
            'core.print.discount' => '90.00',
            'core.print.total' => '810.00',
            'sales::print.paid' => '810.00',
            'sales::print.invoice_due' => '0.00',
            'sales::print.outstanding' => '0.00',
        ]);
    }

    // ══ খ · কেবল বিলের ছাড় ════════════════════════════════════════════════

    /**
     * হাতে গোনা (বাকিতে, ভ্যাট নেই):
     *   ৮ × ১৫০ = ১,২০০ · বিলের ছাড় ৫০ → ১,১৫০ — পুরোটা বাকি
     *   বিক্রয় ১,১৫০ · খরচ ৮০০ · লাভ ৩৫০ · ডিলারের পাওনা ১,১৫০
     */
    public function test_a_bill_discount_alone_nets_the_sale_the_receivable_and_the_printed_total(): void
    {
        $plain = $this->product('DVR-PLAIN', '150', null, '20', '100');
        $before = $this->snapshot();

        $this->sell([$this->line($plain, '8', '150')], [], ['discount_amount' => '50'])->assertSessionHasNoErrors();

        $invoice = $this->lastInvoice();

        $this->assertInvoiceFigures($invoice, subtotal: '1200', discount: '0', tax: '0', billDiscount: '50', rounding: '0', total: '1150');

        $this->assertTheNewEntriesAre($before['id'], [
            StandardChart::RECEIVABLE => '1150',
            StandardChart::SALES => '-1150',
            StandardChart::COST_OF_GOODS_SOLD => '800',
            StandardChart::INVENTORY => '-800',
        ]);

        $this->assertFiveMatches($before, [
            StandardChart::SALES => '-1150',
            StandardChart::DISCOUNT_GIVEN => '0',
            StandardChart::RECEIVABLE => '1150',
            StandardChart::COST_OF_GOODS_SOLD => '800',
            StandardChart::INVENTORY => '-800',
        ], stock: [$plain->id => ['qty' => '12', 'value' => '1200']], dealer: '1150', cash: '0', profit: '350');

        $this->assertSame(0, bccomp($invoice->fresh()->dueAmount(), '1150', 4), '⛔ বিলের বকেয়া হাতে গোনা ১,১৫০ নয়।');

        $this->assertTheReceivableDebitIs($invoice, '1150');
        $this->assertPrinted($invoice, 'a4', [
            'core.print.subtotal' => '1,200.00',
            'sales::print.bill_discount' => '50.00',
            'core.print.total' => '1,150.00',
            'sales::print.invoice_due' => '1,150.00',
            'sales::print.outstanding' => '1,150.00',
        ]);
    }

    // ══ গ · সারির ছাড় + বিলের ছাড় + ভ্যাট (দামের বাইরে) ══════════════════

    /**
     * হাতে গোনা:
     *   সারি ১ (ভ্যাট ১৫% বাইরে, স্তর @ ১২০): ৫ × ২০০ = ১,০০০ · ছাড় ৮% = ৮০ → ৯২০
     *                                        · ভ্যাট ৯২০ × ১৫% = ১৩৮ → ১,০৫৮
     *   সারি ২ (ভ্যাট নেই, স্তর @ ১০০):      ৪ × ১৫০ = ৬০০ · ছাড় ৫% = ৩০ → ৫৭০
     *   ভ্যাটের আগের দাম ৯২০ + ৫৭০ = ১,৪৯০ · বিলের ছাড় ১৪৯ (১০%)
     *   ⭐ ভ্যাট ছাড়ের পরের দামে (মালিক, ১০ অক্টোবর ২০২৬): ১৩৮ × (১ − ১৪৯ ÷ ১,৪৯০) = ১২৪.২০
     *   মোট ১,৪৯০ − ১৪৯ + ১২৪.২০ = ১,৪৬৫.২০ · নগদ ১,০০০ · বাকি ৪৬৫.২০
     *   বিক্রয় ১,৪৬৫.২০ − ১২৪.২০ = ১,৩৪১ (= ৯২০ + ৫৭০ − ১৪৯)
     *   খরচ ৫ × ১২০ + ৪ × ১০০ = ১,০০০ · লাভ ৩৪১
     */
    public function test_line_and_bill_discount_with_vat_each_reach_their_own_account(): void
    {
        $vatted = $this->product('DVR-VATEX', '200', $this->vat15(false)->id, '20', '120');
        $plain = $this->product('DVR-PLAIN', '150', null, '20', '100');
        $before = $this->snapshot();

        $this->sell(
            [$this->line($vatted, '5', '200', '8'), $this->line($plain, '4', '150', '5')],
            [$this->cash('1000')],
            ['discount_amount' => '149'],
        )->assertSessionHasNoErrors();

        $invoice = $this->lastInvoice();

        $this->assertInvoiceFigures($invoice, subtotal: '1600', discount: '110', tax: '124.2', billDiscount: '149', rounding: '0', total: '1465.2');

        $this->assertTheNewEntriesAre($before['id'], [
            $this->tillCode() => '1000',
            StandardChart::RECEIVABLE => '465.2',
            StandardChart::SALES => '-1341',
            StandardChart::VAT_PAYABLE => '-124.2',
            StandardChart::COST_OF_GOODS_SOLD => '1000',
            StandardChart::INVENTORY => '-1000',
        ]);

        $this->assertFiveMatches($before, [
            StandardChart::SALES => '-1341',
            StandardChart::VAT_PAYABLE => '-124.2',
            StandardChart::DISCOUNT_GIVEN => '0',
            StandardChart::RECEIVABLE => '465.2',
            StandardChart::COST_OF_GOODS_SOLD => '1000',
            StandardChart::INVENTORY => '-1000',
        ], stock: [
            $vatted->id => ['qty' => '15', 'value' => '1800'],
            $plain->id => ['qty' => '16', 'value' => '1600'],
        ], dealer: '465.2', cash: '1000', profit: '341');

        $this->assertSame(0, bccomp($invoice->fresh()->dueAmount(), '465.2', 4), '⛔ বিলের বকেয়া হাতে গোনা ৪৬৫.২০ নয়।');

        $this->assertTheReceivableDebitIs($invoice, '1465.2');
        $this->assertPrinted($invoice, 'a4', [
            'core.print.subtotal' => '1,600.00',
            'core.print.discount' => '110.00',
            'core.print.tax' => '124.20',
            'sales::print.bill_discount' => '149.00',
            'core.print.total' => '1,465.20',
            'sales::print.paid' => '1,000.00',
            'sales::print.invoice_due' => '465.20',
            'sales::print.outstanding' => '465.20',
        ]);
    }

    // ══ ঘ · রাউন্ডিং — নিচে আর উপরে, আর সীমার দেয়াল ══════════════════════

    /**
     * ① নিচে গোল (লাখের অঙ্ক — কমার নিয়মও দেখা হয়):
     *   ৩ × ৪১,১৫২.৭৯ = ১,২৩,৪৫৮.৩৭ · রাউন্ডিং −০.৩৭ → ১,২৩,৪৫৮.০০ — নগদে পুরোটা
     *   বিক্রয় ১,২৩,৪৫৮ · খরচ ৩ × ৩০,০০০ = ৯০,০০০ · লাভ ৩৩,৪৫৮
     *
     * ② উপরে গোল (ভ্যাট ১৫% বাইরে, স্তর @ ১২০):
     *   ৩ × ২০০ = ৬০০ · ছাড় ৭% = ৪২ → ৫৫৮ · ভ্যাট ৮৩.৭০ → ৬৪১.৭০
     *   রাউন্ডিং +০.৩০ → ৬৪২.০০ — পুরোটা বাকি
     *   ভ্যাট ৮৩.৭০ · বিক্রয় ৬৪২ − ৮৩.৭০ = ৫৫৮.৩০ · খরচ ৩৬০ · লাভ ১৯৮.৩০
     *
     * ③ ⛔ সীমার দেয়াল (ডিফল্ট ৫ টাকা): রাউন্ডিং −৬.৩৭ → ফেরত, কিছুই নড়ে না।
     *   ⓘ বিপজ্জনক ইনপুট দিয়েই — সীমা না থাকলে রাউন্ডিং ছাড়ের পিছনের দরজা।
     */
    public function test_rounding_down_and_up_lands_in_the_sale_and_the_printed_total_equals_the_books(): void
    {
        $big = $this->product('DVR-BIG', '41152.79', null, '10', '30000');
        $vatted = $this->product('DVR-VATEX', '200', $this->vat15(false)->id, '20', '120');

        // ── ① নিচে গোল, লাখের অঙ্কে
        $before = $this->snapshot();

        $this->sell([$this->line($big, '3', '41152.79')], [$this->cash('123458')], ['rounding_amount' => '-0.37'])
            ->assertSessionHasNoErrors();

        $first = $this->lastInvoice();

        $this->assertInvoiceFigures($first, subtotal: '123458.37', discount: '0', tax: '0', billDiscount: '0', rounding: '-0.37', total: '123458');

        $this->assertTheNewEntriesAre($before['id'], [
            $this->tillCode() => '123458',
            StandardChart::SALES => '-123458',
            StandardChart::COST_OF_GOODS_SOLD => '90000',
            StandardChart::INVENTORY => '-90000',
        ]);

        $this->assertFiveMatches($before, [
            StandardChart::SALES => '-123458',
            StandardChart::DISCOUNT_GIVEN => '0',
            StandardChart::COST_OF_GOODS_SOLD => '90000',
            StandardChart::INVENTORY => '-90000',
        ], stock: [$big->id => ['qty' => '7', 'value' => '210000']], dealer: '0', cash: '123458', profit: '33458');

        $this->assertSame(0, bccomp($first->fresh()->dueAmount(), '0', 4),
            '⛔ ক্রেতা গোল করা অঙ্ক দিলেন, অথচ ৩৭ পয়সা বকেয়া রয়ে গেল।');

        $this->assertTheReceivableDebitIs($first, '123458');
        $this->assertPrinted($first, 'a4', [
            'core.print.subtotal' => '1,23,458.37',
            'sales::field.rounding' => '-0.37',
            'core.print.total' => '1,23,458.00',
            'sales::print.paid' => '1,23,458.00',
            'sales::print.invoice_due' => '0.00',
            'sales::print.outstanding' => '0.00',
        ]);

        /*
         * ⓘ কাউন্টারের রোল (৮০মিমি): মালিকের নিয়মে থার্মালে পয়সা নেই
         * ([[PaperSize::decimals()]])। ⭐ গোল করা মোটে তাই ছাপা = খাতা হুবহু।
         * ⚠️ রাউন্ডিংয়ের সারিটা ইচ্ছাকৃতভাবে দাবিতে নেই — থার্মালে −০.৩৭
         * শূন্যে গোল হয়ে "0" ছাপে; সেটা মালিকের প্রশ্ন (ফলাফল-নথিতে), আর
         * আজকের আচরণ দাবি করে পাথরে বসানো ঠিক নয়।
         */
        $roll = $this->printedTotals($first, '80mm');
        $this->assertSame('1,23,458', $roll[__('core.print.total', [], $this->printLocale)] ?? null, '⛔ রোলের ছাপা মোট খাতার ১,২৩,৪৫৮ নয়।');
        $this->assertSame('1,23,458', $roll[__('sales::print.paid', [], $this->printLocale)] ?? null, '⛔ রোলে "জমা" খাতার জমা নয়।');
        $this->assertSame('0', $roll[__('sales::print.invoice_due', [], $this->printLocale)] ?? null, '⛔ রোলে বকেয়া শূন্য নয়।');

        // ── ② উপরে গোল, ভ্যাটসহ, বাকিতে
        $before = $this->snapshot();

        $this->sell([$this->line($vatted, '3', '200', '7')], [], ['rounding_amount' => '0.30'])
            ->assertSessionHasNoErrors();

        $second = $this->lastInvoice();

        $this->assertInvoiceFigures($second, subtotal: '600', discount: '42', tax: '83.70', billDiscount: '0', rounding: '0.30', total: '642');

        $this->assertTheNewEntriesAre($before['id'], [
            StandardChart::RECEIVABLE => '642',
            StandardChart::SALES => '-558.30',
            StandardChart::VAT_PAYABLE => '-83.70',
            StandardChart::COST_OF_GOODS_SOLD => '360',
            StandardChart::INVENTORY => '-360',
        ]);

        $this->assertFiveMatches($before, [
            StandardChart::SALES => '-558.30',
            StandardChart::VAT_PAYABLE => '-83.70',
            StandardChart::DISCOUNT_GIVEN => '0',
            StandardChart::RECEIVABLE => '642',
            StandardChart::COST_OF_GOODS_SOLD => '360',
            StandardChart::INVENTORY => '-360',
        ], stock: [$vatted->id => ['qty' => '17', 'value' => '2040']], dealer: '642', cash: '0', profit: '198.30');

        $this->assertTheReceivableDebitIs($second, '642');
        $this->assertPrinted($second, 'a4', [
            'core.print.subtotal' => '600.00',
            'core.print.discount' => '42.00',
            'core.print.tax' => '83.70',
            'sales::field.rounding' => '0.30',
            'core.print.total' => '642.00',
            'sales::print.invoice_due' => '642.00',
            'sales::print.outstanding' => '642.00',
        ]);

        // ── ③ ⛔ সীমার বাইরের রাউন্ডিং — ফেরত, আর কিছুই নড়ে না
        $before = $this->snapshot();
        $invoices = SalesInvoice::query()->count();

        $this->sell([$this->line($big, '3', '41152.79')], [$this->cash('123452')], ['rounding_amount' => '-6.37'])
            ->assertSessionHasErrors('rounding_amount');

        $this->assertTheNewEntriesAre($before['id'], [], '⛔ সীমার বাইরের রাউন্ডিং খাতায় উঠে গেছে।');
        $this->assertSame($invoices, SalesInvoice::query()->count(), '⛔ ফেরানো বিক্রির বিল রয়ে গেছে।');
        $this->assertTheStockMatches([$big->id => ['qty' => '7', 'value' => '210000']]);
    }

    // ══ ঙ · ভ্যাট দামের ভিতরে (inclusive) ═════════════════════════════════

    /**
     * ① হাতে গোনা (ভ্যাট ১৫% দামের ভিতরে, স্তর @ ১৫০):
     *   ৫ × ২৩০ = ১,১৫০ · ছাড় ১০% = ১১৫ → ১,০৩৫ — মোট **বাড়ে না**
     *   ভ্যাট = ১,০৩৫ − ১,০৩৫ ÷ ১.১৫ = ১,০৩৫ − ৯০০ = ১৩৫ · বিক্রয় ৯০০
     *   খরচ ৫ × ১৫০ = ৭৫০ · লাভ ১৫০ — নগদে পুরোটা
     *   ⓘ তুলনায়: একই দর বাইরের ভ্যাটে হলে মোট ১,০৩৫ + ১৫৫.২৫ = ১,১৯০.২৫ হত।
     *
     * ② ভগ্ন পয়সা (স্তর @ ৭০):
     *   ৩ × ১০০ = ৩০০ · ৩০০ ÷ ১.১৫ = ২৬০.৮৬৯৫… → ভ্যাট ৩৯.১৩০৪… → পয়সায় ৩৯.১৩
     *   বিক্রয় ৩০০ − ৩৯.১৩ = ২৬০.৮৭ · খরচ ২১০ · লাভ ৫০.৮৭ — পুরোটা বাকি
     */
    public function test_vat_inside_the_price_is_carved_out_of_the_same_total(): void
    {
        $inclusive = $this->vat15(true);
        $oil = $this->product('DVR-VATIN', '230', $inclusive->id, '20', '150');
        $salt = $this->product('DVR-VATIN2', '100', $inclusive->id, '10', '70');

        // ── ① ঠিক ভাগ হয়
        $before = $this->snapshot();

        $this->sell([$this->line($oil, '5', '230', '10')], [$this->cash('1035')])->assertSessionHasNoErrors();

        $first = $this->lastInvoice();

        $this->assertInvoiceFigures($first, subtotal: '1150', discount: '115', tax: '135', billDiscount: '0', rounding: '0', total: '1035');

        $this->assertTheNewEntriesAre($before['id'], [
            $this->tillCode() => '1035',
            StandardChart::SALES => '-900',
            StandardChart::VAT_PAYABLE => '-135',
            StandardChart::COST_OF_GOODS_SOLD => '750',
            StandardChart::INVENTORY => '-750',
        ]);

        $this->assertFiveMatches($before, [
            StandardChart::SALES => '-900',
            StandardChart::VAT_PAYABLE => '-135',
            StandardChart::DISCOUNT_GIVEN => '0',
            StandardChart::COST_OF_GOODS_SOLD => '750',
            StandardChart::INVENTORY => '-750',
        ], stock: [$oil->id => ['qty' => '15', 'value' => '2250']], dealer: '0', cash: '1035', profit: '150');

        $this->assertTheReceivableDebitIs($first, '1035');
        $this->assertPrinted($first, 'a4', [
            'core.print.subtotal' => '1,150.00',
            'core.print.discount' => '115.00',
            // ⓘ দামের ভিতরের ভ্যাট নিজের নামে — যোগে আবার ধরা নয় (পুনঃঅডিট ৯ অক্টোবর ২০২৬, ছাপা ১৪)
            'core.print.tax_included' => '135.00',
            'core.print.total' => '1,035.00',
            'sales::print.paid' => '1,035.00',
            'sales::print.invoice_due' => '0.00',
            'sales::print.outstanding' => '0.00',
        ]);

        // ── ② ভগ্ন পয়সা
        $before = $this->snapshot();

        $this->sell([$this->line($salt, '3', '100')])->assertSessionHasNoErrors();

        $second = $this->lastInvoice();

        $this->assertInvoiceFigures($second, subtotal: '300', discount: '0', tax: '39.13', billDiscount: '0', rounding: '0', total: '300');

        $this->assertTheNewEntriesAre($before['id'], [
            StandardChart::RECEIVABLE => '300',
            StandardChart::SALES => '-260.87',
            StandardChart::VAT_PAYABLE => '-39.13',
            StandardChart::COST_OF_GOODS_SOLD => '210',
            StandardChart::INVENTORY => '-210',
        ]);

        $this->assertFiveMatches($before, [
            StandardChart::SALES => '-260.87',
            StandardChart::VAT_PAYABLE => '-39.13',
            StandardChart::RECEIVABLE => '300',
            StandardChart::COST_OF_GOODS_SOLD => '210',
            StandardChart::INVENTORY => '-210',
        ], stock: [$salt->id => ['qty' => '7', 'value' => '490']], dealer: '300', cash: '0', profit: '50.87');

        $this->assertTheReceivableDebitIs($second, '300');
        $this->assertPrinted($second, 'a4', [
            'core.print.subtotal' => '300.00',
            'core.print.tax' => '39.13',
            'core.print.total' => '300.00',
            'sales::print.invoice_due' => '300.00',
            'sales::print.outstanding' => '300.00',
        ]);
    }

    /**
     * ⛔ দামের ভেতরের ভ্যাট হাতে পাঠালেও মোট বাড়ে না — বিল খুলে আবার সংরক্ষণের পথ (Sales অডিট ১০ অক্টোবর ২০২৬)।
     *
     * ⓘ বিপজ্জনক ইনপুট: সারি সম্পাদক পুরনো ভ্যাটটা (১৩৫) ফেরত পাঠায়। হাতে গোনা: ৫ × ২৩০ − ১১৫ = ১,০৩৫,
     * ভ্যাট ১৩৫ ভেতরেই → মোট ১,০৩৫। ⚠️ আগে বসত ১,১৭০ — ভ্যাট দুইবার।
     */
    public function test_vat_inside_the_price_sent_back_by_hand_is_not_added_twice(): void
    {
        $oil = $this->product('DVR-VATIN3', '230', $this->vat15(true)->id, '20', '150');

        $invoice = app(\App\Modules\Sales\Services\SalesInvoiceService::class)->create([
            'customer_id' => $this->dealer->id,
            'trx_date' => now()->toDateString(),
        ], [['product_id' => $oil->id, 'qty' => '5', 'rate' => '230', 'discount' => '115', 'tax' => '135']]);

        $this->assertSame([0, 0], [bccomp((string) $invoice->tax, '135', 4), bccomp((string) $invoice->total, '1035', 4)],
            '⛔ ভেতরের ভ্যাট হাতে ফেরত এলে ভ্যাট '.$invoice->tax.', মোট '.$invoice->total.' — ভ্যাট মোটের উপর আবার বসেছে।');
    }

    // ══ চ · বিলের ছাড় ছাড়ের অনুমোদন এড়ায় না ═══════════════════════════════

    /**
     * ⛔ যেকোনো ছাড়ে মালিকের সই লাগে (`sales.discount`, [[OwnerSignsDiscounts]] — মালিকের নিয়ম,
     * ১ অক্টোবর ২০২৬; আগে ডেমোতে ১,০০০ টাকার সীমা ছিল)।
     *
     * একই মানুষ, একই ডিলার, একই পণ্য, একই মোট ছাড় (১,৫০০) — কেবল কোন
     * ঘরে বসল সেটা আলাদা:
     *   ① পাল্টা-দাবি: সারির ছাড় ৫০% (২০ × ১৫০ = ৩,০০০ → ১,৫০০ ছাড়) → সই চায়, কিছুই নড়ে না
     *   ② দাবি: বিলের ছাড় ১,৫০০ (একই ৩,০০০ → ১,৫০০) → **একইভাবে** সই চাইবে
     *
     * ⚠️ ② না আটকালে যে ছাড় সই এড়াতে চায়, সে কেবল ঘর বদলায় — সারির
     * বদলে বিলের নিচে লেখে। ঠিক এই পিছনের দরজার জন্যই রাউন্ডিংয়ে সীমা
     * বসানো হয়েছিল ([[DirectSaleController]] `rounding_amount`)।
     */
    public function test_a_bill_discount_asks_for_the_same_signature_as_a_line_discount(): void
    {
        $plain = $this->product('DVR-PLAIN', '150', null, '20', '100');
        $this->ownerSigns = false;

        // ── ① পাল্টা-দাবি — সারির ছাড় সই চায়
        $before = $this->snapshot();
        $invoices = SalesInvoice::query()->where('status', DocumentStatus::CONFIRMED)->count();

        $this->assertItAsksForASignature(
            $this->sell([$this->line($plain, '20', '150', '50')]),
            'দৃশ্যটাই বানানো যায়নি — ১,৫০০ টাকার সারির ছাড় সই চায়নি।',
        );

        $this->assertTheNewEntriesAre($before['id'], [], 'দৃশ্যটাই বানানো যায়নি — ১,৫০০ টাকার সারির ছাড় সই ছাড়াই খাতায় উঠেছে।');
        $this->assertSame($invoices, SalesInvoice::query()->where('status', DocumentStatus::CONFIRMED)->count());

        // ── ② দাবি — বিলের ছাড়ও একই সই চায়
        // ⓘ এক ডিলারের একটাই খোলা কাউন্টার-খসড়া চলে ([[DirectSaleService::assertNoOtherOpenDraft()]]), আর ① এখন
        //    সইয়ের অপেক্ষায় খসড়া — তাই ② একই রকম আরেক ডিলারে: একই মানুষ, একই পণ্য, একই মোট ছাড়।
        $this->dealer = $this->dealer->replicate(['public_id'])->fill(['code' => 'CUS-DVR2', 'name_en' => 'Discount VAT Rounding Dealer Two']);
        $this->dealer->save();
        $this->dealer = $this->dealer->fresh();

        $this->assertItAsksForASignature(
            $this->sell([$this->line($plain, '20', '150')], [], ['discount_amount' => '1500']),
            '⛔ ১,৫০০ টাকার বিলের ছাড় সই চায়নি — সারিতে একই ছাড় সই চায়।',
        );

        $this->assertTheNewEntriesAre($before['id'], [],
            '⛔ ১,৫০০ টাকার বিলের ছাড় কারও সই ছাড়াই খাতায় উঠে গেছে — সারিতে একই ছাড় সই চায়।');
        $this->assertSame($invoices, SalesInvoice::query()->where('status', DocumentStatus::CONFIRMED)->count(),
            '⛔ সই ছাড়াই বিলের ছাড়ের বিল নিশ্চিত হয়ে গেছে।');
        $this->assertTheStockMatches([$plain->id => ['qty' => '20', 'value' => '2000']]);
    }

    // ══ মাপার যন্ত্র ═════════════════════════════════════════════════════

    /** ⓘ এ পর্যন্ত কয়টা ছাড়ের সই অপেক্ষায় — প্রতিটা দাবি নিজের একটা নতুন অনুরোধ চায় */
    private int $askedSoFar = 0;

    /**
     * ছাড়ের সই চাওয়া হয়েছে — নতুন একটা অপেক্ষার অনুরোধ, আর কাউন্টার বার্তা নিয়ে ফেরে (বিক্রি খসড়া)।
     *
     * ⓘ আগে কাউন্টার `discount` ঘরে ভুল ফেরত দিত, আর অনুরোধটা লেনদেনের সাথে মুছত — এখন বিক্রি খসড়া থাকে আর
     * অনুরোধ টিকে থাকে ([[DirectSaleService::holdForDiscount()]])। তাই মাপা হয় অনুরোধটাই।
     */
    private function assertItAsksForASignature(TestResponse $response, string $why): void
    {
        $asked = Approval::query()->where('action', 'discount')->where('status', Approval::PENDING)->count();

        $this->assertGreaterThan($this->askedSoFar, $asked, $why.' ফেরত এসেছে (HTTP '.$response->getStatusCode().')');
        $this->assertNotNull($response->getSession()?->get('approval_notice'), $why.' — কাউন্টার কোনো বার্তা দেয়নি।');

        $this->askedSoFar = $asked;
    }

    /**
     * লেনদেনের আগের ছবি — **মাপা** জের, যাতে পাঁচ মিল গোটা কোম্পানির
     * অঙ্কে চলতে পারে (ডেমোতে আগে থেকেই দাখিলা আছে)।
     *
     * @return array{id: int, books: array<string, string>, cash: string, profit: string}
     */
    private function snapshot(): array
    {
        $books = [];

        foreach ([
            StandardChart::SALES, StandardChart::VAT_PAYABLE, StandardChart::DISCOUNT_GIVEN,
            StandardChart::RECEIVABLE, StandardChart::COST_OF_GOODS_SOLD, StandardChart::INVENTORY,
        ] as $code) {
            $books[$code] = $this->fiveMatchMovementOf($code);
        }

        return [
            'id' => (int) DB::table('ledger_entries')->max('id'),
            'books' => $books,
            'cash' => $this->fiveMatchLedgerMovement($this->fiveMatchMoneyAccounts(Account::CASH, StandardChart::CASH_IN_HAND)),
            'profit' => $this->grossProfitNow(),
        ];
    }

    /**
     * পাঁচ মিল — আগের জের + হাতে গোনা নড়াচড়া = এখনকার জের।
     *
     * @param  array{id: int, books: array<string, string>, cash: string, profit: string}  $before
     * @param  array<string, string>  $delta  খাত-কোড => হাতে গোনা (ডেবিট − ক্রেডিট)
     * @param  array<int, array{qty?: string, value?: string}>  $stock  পণ্য => হাতে গোনা (পরম)
     */
    private function assertFiveMatches(array $before, array $delta, array $stock, string $dealer, string $cash, string $profit): void
    {
        $books = [];

        foreach ($delta as $code => $move) {
            $books[$code] = bcadd($before['books'][$code] ?? $this->fiveMatchMovementOf((string) $code), $move, 4);
        }

        $this->assertTheFiveMatches([
            'books' => $books,
            'stock' => $stock,
            'party' => [[
                'type' => 'customer', 'id' => (int) $this->dealer->id,
                'code' => StandardChart::RECEIVABLE, 'amount' => $dealer,
            ]],
            'money' => [Account::CASH => bcadd($before['cash'], $cash, 4)],
            'profit' => bcadd($before['profit'], $profit, 4),
        ]);
    }

    /**
     * এই বিক্রির নতুন দাখিলা — ডেবিট = ক্রেডিট, আর খাতপ্রতি নড়াচড়া হুবহু।
     *
     * ⚠️ তালিকার বাইরের কোনো খাত নড়লেও লাল — ⛔ কেবল চেনা খাত দেখলে
     * ছাড় বা রাউন্ডিং ভুল খাতে চলে গেলেও বাকিগুলো দিব্যি মিলত।
     *
     * @param  array<string, string>  $expected
     */
    private function assertTheNewEntriesAre(int $fromId, array $expected, string $why = ''): void
    {
        $sums = DB::table('ledger_entries')->where('id', '>', $fromId)
            ->selectRaw('COALESCE(SUM(debit),0) AS d, COALESCE(SUM(credit),0) AS c')->first();

        $this->assertSame(0, bccomp((string) $sums->d, (string) $sums->c, 4),
            "⛔ নতুন দাখিলায় ডেবিট {$sums->d} ≠ ক্রেডিট {$sums->c}।");

        $actual = [];

        foreach (DB::table('ledger_entries')->where('id', '>', $fromId)
            ->selectRaw('account_id, SUM(debit) - SUM(credit) AS net')
            ->groupBy('account_id')->get() as $row) {
            if (bccomp((string) $row->net, '0', 4) === 0) {
                continue;
            }

            $code = (string) Account::query()->whereKey($row->account_id)->value('code');
            $actual[$code] = bcadd((string) $row->net, '0', 4);
        }

        $want = array_map(fn (string $v) => bcadd($v, '0', 4), $expected);

        ksort($actual);
        ksort($want);

        $this->assertSame($want, $actual, trim($why."\n⛔ খাতের নড়াচড়া হাতে গোনা অঙ্কের সাথে মেলে না (খাত-কোড => ডেবিট − ক্রেডিট)।"));
    }

    /** বিলের গায়ের ছয়টা অঙ্ক — হাতে গোনার সাথে। */
    private function assertInvoiceFigures(
        SalesInvoice $invoice,
        string $subtotal,
        string $discount,
        string $tax,
        string $billDiscount,
        string $rounding,
        string $total,
    ): void {
        $invoice = $invoice->fresh();

        foreach ([
            'subtotal' => $subtotal,
            'discount' => $discount,
            'tax' => $tax,
            'bill_discount' => $billDiscount,
            'rounding_amount' => $rounding,
            'total' => $total,
        ] as $field => $expected) {
            $this->assertSame(0, bccomp((string) ($invoice->{$field} ?? '0'), $expected, 4),
                "⛔ বিলের {$field} ".$invoice->{$field}.", হাতে গোনা {$expected}।");
        }
    }

    /** খাতায় এই বিলের পাওনার ডেবিট = হাতে গোনা মোট — ছাপা মোটের সাথে মেলানোর অন্য পাশ। */
    private function assertTheReceivableDebitIs(SalesInvoice $invoice, string $expected): void
    {
        $booked = bcadd((string) DB::table('ledger_entries')
            ->where('source_type', SalesInvoice::drillSourceType())
            ->where('source_id', $invoice->id)
            ->where('account_id', Account::query()->where('code', StandardChart::RECEIVABLE)->value('id'))
            ->selectRaw('COALESCE(SUM(debit),0) AS d')->value('d'), '0', 4);

        $this->assertSame(0, bccomp($booked, $expected, 4), "⛔ খাতায় বিলের পাওনা {$booked}, হাতে গোনা {$expected}।");

        // ⓘ বিলটা নিজে নিজে ভারসাম্যে
        $this->assertTheBooksMatch([], SalesInvoice::drillSourceType(), (int) $invoice->id);
    }

    /**
     * ছাপা কাগজের মোটের ছক — হুবহু হাতে লেখা সারিগুলো, এই ক্রমে, আর কিছু নয়।
     *
     * @param  array<string, string>  $expected  অনুবাদ-চাবি => ছাপা স্ট্রিং
     */
    private function assertPrinted(SalesInvoice $invoice, string $paper, array $expected): void
    {
        $printed = $this->printedTotals($invoice, $paper);

        $want = [];

        foreach ($expected as $key => $value) {
            $want[__($key, [], $this->printLocale)] = $value;
        }

        $this->assertSame($want, $printed, "⛔ ছাপা বিলের ({$paper}) মোটের ছক হাতে গোনা অঙ্কের সাথে মেলে না।");
    }

    /**
     * বিলের ছাপার পাতা আসল দরজা দিয়ে, তারপর **একই টেমপ্লেট একই ডাটায়**
     * আঁকা HTML থেকে মোটের ছক।
     *
     * ⓘ দরজাটা PDF ফেরত দেয়, তাই অক্ষর পড়তে ডাটাটা ধরা হয় আর টেমপ্লেটটা
     * আবার আঁকা হয় — ⚠️ `$doc->totals` সরাসরি পড়লে কাগজের নিজের রূপ
     * ([[PaperSize::money()]], থার্মালে পয়সা বাদ) ধরা পড়ত না।
     *
     * @return array<string, string> ছাপা লেবেল => ছাপা অঙ্ক
     */
    private function printedTotals(SalesInvoice $invoice, string $paper): array
    {
        $seen = null;

        View::composer('print.document', function ($view) use (&$seen) {
            $seen ??= $view->getData();
        });

        $this->actingAs($this->owner)
            ->get(route('sales.print.invoice', ['invoice' => $invoice->id, 'paper' => $paper]))
            ->assertOk();

        $this->assertIsArray($seen, 'ছাপার পাতাটা কাগজই আঁকেনি।');

        $previous = app()->getLocale();
        $this->printLocale = (string) ($seen['locale'] ?? $previous);
        app()->setLocale($this->printLocale);

        try {
            $html = View::make('print.document', $seen)->render();

            $this->assertSame(1, preg_match('#<table class="totals">(.*?)</table>#s', $html, $table),
                'ছাপা কাগজে মোটের ছকটাই নেই।');

            preg_match_all('#<tr[^>]*>\s*<td>(.*?)</td>\s*<td class="num"[^>]*>(.*?)</td>\s*</tr>#s',
                $table[1], $rows, PREG_SET_ORDER);

            $this->assertNotSame([], $rows, 'মোটের ছকে একটা সারিও পড়া যায়নি — পাঠকটাই অন্ধ।');

            $printed = [];

            foreach ($rows as [, $label, $value]) {
                $printed[trim(html_entity_decode(strip_tags($label)))] = trim(html_entity_decode(strip_tags($value)));
            }

            return $printed;
        } finally {
            app()->setLocale($previous);
        }
    }

    private function grossProfitNow(): string
    {
        $sales = bcmul($this->fiveMatchMovementOf(StandardChart::SALES), '-1', 4);
        $returns = bcmul($this->fiveMatchMovementOf(StandardChart::SALES_RETURN), '-1', 4);

        return bcsub(bcadd($sales, $returns, 4), $this->fiveMatchMovementOf(StandardChart::COST_OF_GOODS_SOLD), 4);
    }

    // ══ প্রস্তুতি ══════════════════════════════════════════════════════════

    /**
     * কাউন্টারের আসল দরজা দিয়ে একটা বিক্রি।
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  list<array<string, mixed>>  $deposits
     * @param  array<string, mixed>  $extra
     */
    /** ⓘ সই মাপার দাবিটা (চ) এটা বন্ধ রাখে — বাকিরা ছাড়ের হিসাব মাপে, সই নয় */
    private bool $ownerSigns = true;

    private function sell(array $lines, array $deposits = [], array $extra = []): TestResponse
    {
        $response = $this->postSale($lines, $deposits, $extra);

        if ($this->ownerSigns) {
            // ⓘ যেকোনো ছাড়ে মালিকের সই (১ অক্টোবর ২০২৬) — এই দাবি হিসাব মাপে, সই নয় ([[SignsTheDiscountAsTheOwner]]) — শেষ সইয়ে বিক্রি নিজে শেষ
            $this->ownerSignsTheDiscounts();
        }

        return $response;
    }

    private function postSale(array $lines, array $deposits = [], array $extra = []): TestResponse
    {
        return $this->actingAs($this->owner)->post(route('sales.direct.store'), [
            'own_transport' => '1', // ⓘ ধাপ ৫ — নিশ্চিতে পরিবহন লাগে ([[TransportRule]]); এই দাবি অন্য কিছু মাপে
            'customer_id' => $this->dealer->id,
            'warehouse_id' => $this->warehouse->id,
            'lines' => $lines,
            ...($deposits === [] ? [] : ['deposits' => $deposits]),
            ...$extra,
        ]);
    }

    private function line(Product $product, string $qty, string $rate, ?string $discountPercent = null): array
    {
        return [
            'product_id' => $product->id,
            'qty' => $qty,
            'rate' => $rate,
            ...($discountPercent === null ? [] : ['discount_percent' => $discountPercent]),
        ];
    }

    private function cash(string $amount): array
    {
        return [
            'amount' => $amount,
            'account_id' => app(CashTillService::class)->ensurePrimaryTill()->account_id,
            'payment_method_id' => (int) PaymentMethod::query()->where('code', 'CASH')->firstOrFail()->id,
        ];
    }

    private function tillCode(): string
    {
        return (string) app(CashTillService::class)->ensurePrimaryTill()->account->code;
    }

    private function lastInvoice(): SalesInvoice
    {
        $invoice = SalesInvoice::query()->where('customer_id', $this->dealer->id)->latest('id')->firstOrFail();

        $this->assertSame(DocumentStatus::CONFIRMED, $invoice->status, 'বিলটা নিশ্চিত হয়নি — দৃশ্যটাই বানানো যায়নি।');

        return $invoice;
    }

    /** ভ্যাট ১৫% — দামের বাইরে বা ভিতরে। */
    private function vat15(bool $inclusive): Tax
    {
        $code = $inclusive ? 'DVR-VAT15IN' : 'DVR-VAT15EX';

        $tax = Tax::query()->create([
            'code' => $code, 'name_en' => $code, 'name_bn' => $code,
            'rate' => '15', 'kind' => 'vat', 'is_inclusive' => $inclusive, 'is_active' => true,
        ])->fresh();

        $this->assertSame($inclusive, (bool) $tax->is_inclusive, 'দৃশ্যটাই বানানো যায়নি — ভ্যাটের ধরন উল্টো বসেছে।');
        $this->assertSame(0, bccomp((string) $tax->rate, '15', 4), 'দৃশ্যটাই বানানো যায়নি — হার ১৫% নয়।');

        return $tax;
    }

    /**
     * পণ্য আর তার খোলা মজুদ — তাকে মাল, FIFO স্তর, আর খাতায় মজুদ-খাত
     * (নইলে ব্যালান্স শিটের মিলটা শুরু থেকেই ভাঙা)। দর = মান দাম, যাতে
     * দামের নীতি কোনো সতর্কতা না তোলে।
     */
    private function product(string $code, string $salePrice, ?int $taxId, string $qty, string $cost): Product
    {
        $product = app(ProductService::class)->create([
            'code' => $code,
            'name_en' => $code,
            'name_bn' => $code,
            'unit_id' => Unit::query()->where('code', 'PCS')->value('id'),
            'purchase_price' => $cost,
            'sale_price' => $salePrice,
            'tax_id' => $taxId,
        ]);

        $date = now()->subDays(5)->toDateString();

        $movement = app(StockService::class)->move(
            product: $product, warehouse: $this->warehouse,
            sourceType: 'opening', sourceId: $product->id,
            floor: $qty, date: $date, narration: 'dvr opening',
        );

        app(CostLayerService::class)->receive(
            product: $product, qty: $qty, unitCost: $cost,
            sourceType: 'opening', sourceId: $product->id,
            documentNo: 'OPENING', date: $date,
        );

        app(OpeningBalanceService::class)->forInventory(
            sourceId: $movement->id, documentNo: 'OPENING',
            amount: bcmul($qty, $cost, 4), date: $date,
        );

        return $product->fresh();
    }
}
