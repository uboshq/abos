<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\OpeningBalanceService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\CostLayer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\CostLayerService;
use App\Modules\Inventory\Services\ProductService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\MasterData\Models\PaymentMethod;
use App\Modules\MasterData\Models\Tax;
use App\Modules\MasterData\Models\Unit;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\SalesInvoice;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\PrintsTheStandardPaper;
use Tests\Concerns\SignsTheDiscountAsTheOwner;
use Tests\TestCase;

/**
 * ⭐ কাউন্টারের প্রতিটা বিক্রি খাতার সাথে পাঁচভাবে মেলে — চেকলিস্ট
 * "ব্যবসা চালু, সরাসরি ক্রয় ও বিক্রয়" §৩, মালিকের নির্দেশ ২৭ সেপ্টেম্বর ২০২৬:
 * *"business zehetu asol hisab tai gormil holeei bipod"*।
 *
 * ── ⓘ পদ্ধতি ──────────────────────────────────────────────────────────
 * প্রতিটা পরীক্ষা **পর্দার আসল দরজা দিয়ে** (HTTP, `sales.direct.store`)
 * একটা বিক্রি চালায়, তারপর §১-এর পাঁচ মিল **হাতে গোনা অঙ্কের** সাথে
 * মেলায়:
 *   ⓵ খাতা — ডেবিট = ক্রেডিট, আর প্রতিটা খাতের নড়াচড়া হুবহু হাতের অঙ্ক
 *      (বাড়তি কোনো খাত নড়লেও লাল)
 *   ⓶ মজুদ — তাকের পরিমাণ, FIFO স্তরের মূল্য, আর ব্যালান্স শিটের মজুদ-খাত
 *      = স্তরের মোট মূল্য
 *   ⓷ গ্রাহকের খতিয়ান = পাওনা-খাতের নড়াচড়া, আর বিলের বকেয়া
 *   ⓸ নগদ / ব্যাংক / বিকাশের জের
 *   ⓹ বিক্রয় − বিক্রীত পণ্যের খরচ = হাতে গোনা মোট লাভ
 *
 * ⚠️ অঙ্কগুলো **টেবিল থেকে সরাসরি** পড়া হয় (`ledger_entries`,
 * `inv_stock_movements`, `inv_cost_layers`) — অ্যাপের রিপোর্ট-সেবা দিয়ে নয়।
 * ⛔ রিপোর্ট দিয়ে মাপলে রিপোর্টের ভুল আর খাতার ভুল একে অপরকে ঢেকে দিত।
 *
 * ⓘ প্রত্যাশিত অঙ্কগুলো **আক্ষরিক সংখ্যা**, কোডের সূত্র নয় — ⚠️ সূত্র লিখলে
 * পরীক্ষা কোডের ভুলটাই নকল করত, আর দুইটা একসাথে ভুল হয়ে সবুজ দেখাত।
 *
 * ⓘ পাঁচ-মিলের সাধারণ trait (`tests/Concerns/ChecksTheFiveMatches.php`) এই
 * ফাইল লেখার সময় ছিল না — তাই সহায়কগুলো এখানেই (নিচে "পাঁচ মিল" অংশ)।
 * trait এলে এগুলো সেখানে সরানো যাবে।
 */
final class EveryCounterSaleMustMatchTheBooksFiveWaysTest extends TestCase
{
    use PrintsTheStandardPaper;
    use RefreshDatabase;
    use SignsTheDiscountAsTheOwner;

    private Company $company;

    private User $owner;

    private Warehouse $warehouse;

    /** ভ্যাট ছাড়া পণ্য — দুই স্তর: ৫টা @ ১০০, তারপর ১০টা @ ১২০ */
    private Product $plain;

    private Customer $dealer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        // ⓘ ব্যাংক-চার্জ (৫২১০) আর বিকাশ-চার্জ (৫২১১) খাত লাগে
        app(StandardChart::class)->install();

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();

        /*
         * ⓘ নিজের পণ্য, নিজের দুইটা স্তর — ডেমোর পণ্য নয়।
         * ⚠️ ডেমোর পণ্যে একটাই স্তর, তাই "পুরনো স্তর আগে" (FIFO) কখনো
         * পরীক্ষায় আসত না: যেকোনো ক্রমে নিলেও দাম একই দাঁড়াত।
         */
        $this->plain = $this->productWithLayers('PRF-PLAIN', 'Proof Plain Soap', null, [
            ['5', '100', now()->subDays(10)->toDateString()],
            ['10', '120', now()->subDays(5)->toDateString()],
        ]);

        $this->dealer = $this->newDealer('0');

        /* ⓘ এই দাবিগুলো সাধারণ কাগজের তথ্য মাপে — নকশা নয় ([[PrintsTheStandardPaper]]) */
        $this->printTheStandardPaper();
    }

    // ══ ⓵ নগদে বিক্রি ═════════════════════════════════════════════════════

    /**
     * ⭐ নগদে বিক্রি: নগদ বাক্স বাড়ে, মজুদ কমে, খরচ পুরনো স্তর থেকে আগে।
     *
     * হাতে গোনা:
     *   ৮ × ১৫০ = ১,২০০ — নগদে পুরোটা
     *   খরচ (FIFO): ৫ × ১০০ + ৩ × ১২০ = ৫০০ + ৩৬০ = ৮৬০
     *   মোট লাভ: ১,২০০ − ৮৬০ = ৩৪০
     */
    public function test_a_cash_sale_fills_the_drawer_empties_the_shelf_and_costs_the_oldest_layer_first(): void
    {
        $before = $this->snapshot($this->plain);

        $this->sell([$this->line($this->plain, '8', '150')], [$this->cash('1200')])
            ->assertSessionHasNoErrors();

        $invoice = $this->lastInvoiceOf($this->dealer);

        // ⓵ খাতা
        $this->assertBooksMoved($before['ledger'], [
            $this->tillCode() => '1200',
            StandardChart::SALES => '-1200',
            StandardChart::COST_OF_GOODS_SOLD => '860',
            StandardChart::INVENTORY => '-860',
        ]);

        // ⓶ মজুদ — ৮টা নেমেছে, ৮৬০ টাকার স্তর খালি; প্রথম স্তর শূন্য, দ্বিতীয়টায় ৭
        $this->assertStockMoved($this->plain, $before, '-8', '-860');
        $this->assertSame(['0.0000', '7.0000'], $this->layersLeft($this->plain),
            '⛔ মাল পুরনো স্তর থেকে আগে বেরোয়নি — FIFO ভাঙা।');
        $this->assertSame(0, bccomp((string) $invoice->cost_of_goods, '860', 4),
            '⛔ বিলের গায়ে লেখা খরচ হাতে গোনা ৮৬০ নয়।');

        // ⓷ গ্রাহক — নগদে পুরো দাম, কিছুই বকেয়া নয়
        $this->assertDealerMoved($before['ledger'], '0');
        $this->assertSame(0, bccomp($invoice->fresh()->dueAmount(), '0', 4));

        // ⓸ নগদ বাক্স
        $this->assertSame(0, bccomp($this->balanceOf($this->tillCode()), bcadd($before['till'], '1200', 4), 4),
            '⛔ নগদ বাক্সের জের ১,২০০ বাড়েনি।');

        // ⓹ লাভ
        $this->assertGrossProfit($before['ledger'], '340');

        // ছাপা বিল = খাতা
        $this->assertPrintedTotalIsTheLedgerTotal($invoice, '1200');
    }

    // ══ ⓶ বাকিতে বিক্রি ═══════════════════════════════════════════════════

    /**
     * ⭐ বাকিতে বিক্রি: পাওনা বাড়ে, আর সীমার দেয়াল চালানেই থামায়।
     *
     * সীমা ১,৫০০।
     *   ক) ৬ × ১৫০ = ৯০০ বাকিতে → পাওনা ৯০০; খরচ ৫×১০০ + ১×১২০ = ৬২০; লাভ ২৮০
     *   খ) ৫ × ১৫০ = ৭৫০ বাকিতে → ৯০০ + ৭৫০ = ১,৬৫০ > ১,৫০০ → ⛔ ফেরত, কিছুই নড়ে না
     *   গ) ৫ × ১৫০ = ৭৫০, নগদ ১৫০ → বাকি ৬০০ → ৯০০ + ৬০০ = ১,৫০০ = সীমা → চলে
     *      খরচ ৫ × ১২০ = ৬০০; লাভ ১৫০
     */
    public function test_a_credit_sale_grows_the_receivable_and_the_limit_stops_the_next_one_at_the_challan(): void
    {
        $this->dealer->forceFill(['credit_limit' => '1500'])->save();

        // ── ক) সীমার ভিতরে বাকি
        $before = $this->snapshot($this->plain);

        $this->sell([$this->line($this->plain, '6', '150')])->assertSessionHasNoErrors();

        $first = $this->lastInvoiceOf($this->dealer);

        $this->assertBooksMoved($before['ledger'], [
            StandardChart::RECEIVABLE => '900',
            StandardChart::SALES => '-900',
            StandardChart::COST_OF_GOODS_SOLD => '620',
            StandardChart::INVENTORY => '-620',
        ]);
        $this->assertStockMoved($this->plain, $before, '-6', '-620');
        $this->assertDealerMoved($before['ledger'], '900');
        $this->assertSame(0, bccomp($first->fresh()->dueAmount(), '900', 4));
        $this->assertSame(0, bccomp((string) $this->dealer->fresh()->outstanding(), '900', 4),
            '⛔ গ্রাহকের বকেয়া ৯০০ নয়।');
        $this->assertGrossProfit($before['ledger'], '280');
        $this->assertPrintedTotalIsTheLedgerTotal($first, '900');

        // ── খ) সীমা পার — ⛔ চালানের দরজায় থামে, আর কিছুই নড়ে না
        $before = $this->snapshot($this->plain);
        $challans = DeliveryChallan::query()->where('customer_id', $this->dealer->id)->count();
        $invoices = SalesInvoice::query()->where('customer_id', $this->dealer->id)->count();

        $this->sell([$this->line($this->plain, '5', '150')])->assertSessionHasErrors('customer_id');

        $this->assertBooksMoved($before['ledger'], []);
        $this->assertStockMoved($this->plain, $before, '0', '0');
        $this->assertSame($challans, DeliveryChallan::query()->where('customer_id', $this->dealer->id)->count(),
            '⛔ ফেরানো বিক্রির চালানটা রয়ে গেছে — লেনদেন ফেরেনি।');
        $this->assertSame($invoices, SalesInvoice::query()->where('customer_id', $this->dealer->id)->count(),
            '⛔ ফেরানো বিক্রির বিলটা রয়ে গেছে।');
        $this->assertSame(0, bccomp((string) $this->dealer->fresh()->outstanding(), '900', 4));

        // ── গ) ঠিক সীমা পর্যন্ত — নগদ গুনলে পথ খোলে
        $before = $this->snapshot($this->plain);

        $this->sell([$this->line($this->plain, '5', '150')], [$this->cash('150')])->assertSessionHasNoErrors();

        $third = $this->lastInvoiceOf($this->dealer);

        $this->assertBooksMoved($before['ledger'], [
            $this->tillCode() => '150',
            StandardChart::RECEIVABLE => '600',
            StandardChart::SALES => '-750',
            StandardChart::COST_OF_GOODS_SOLD => '600',
            StandardChart::INVENTORY => '-600',
        ]);
        $this->assertStockMoved($this->plain, $before, '-5', '-600');
        $this->assertDealerMoved($before['ledger'], '600');
        $this->assertSame(0, bccomp((string) $this->dealer->fresh()->outstanding(), '1500', 4),
            '⛔ দুই বিক্রির পরে বকেয়া ঠিক সীমায় (১,৫০০) নেই।');
        $this->assertSame(0, bccomp($third->fresh()->dueAmount(), '600', 4));
        $this->assertGrossProfit($before['ledger'], '150');
        $this->assertPrintedTotalIsTheLedgerTotal($third, '750');
    }

    // ══ ⓷ ব্যাংক ও বিকাশে জমা ═════════════════════════════════════════════

    /**
     * ⭐ ব্যাংকে জমা, চার্জসহ: ব্যাংকে যা ঢুকল, চার্জ নিজের খাতে, গ্রাহক পুরো টাকায় শোধ।
     *
     * হাতে গোনা (মালিকের নিয়ম, ১৪ সেপ্টেম্বর: "যা পাঠানো হলো তাই"):
     *   ৪ × ১৫০ = ৬০০ — ব্যাংকে ৬০০ পাঠালেন, ব্যাংক ১০ কাটল
     *   ব্যাংক +৫৯০ · ব্যাংক-চার্জ (৫২১০) +১০ · গ্রাহক শোধ ৬০০
     *   খরচ ৪ × ১০০ = ৪০০; লাভ ২০০
     */
    public function test_a_bank_deposit_lands_in_the_bank_with_its_charge_and_its_transaction_number(): void
    {
        $bank = $this->moneyAccount(StandardChart::BANK, '110291', Account::BANK, 'Proof Bank A/C');

        $before = $this->snapshot($this->plain);

        $this->sell([$this->line($this->plain, '4', '150')], [[
            'amount' => '600',
            'account_id' => $bank->id,
            'payment_method_id' => $this->method('BANK'),
            'reference' => 'BRAC-TRX-5521',
            'deposit_slip_no' => 'SLIP-77',
            'charge_amount' => '10',
        ]])->assertSessionHasNoErrors();

        $invoice = $this->lastInvoiceOf($this->dealer);

        $this->assertBooksMoved($before['ledger'], [
            '110291' => '590',
            StandardChart::BANK_CHARGES => '10',
            StandardChart::SALES => '-600',
            StandardChart::COST_OF_GOODS_SOLD => '400',
            StandardChart::INVENTORY => '-400',
        ]);
        $this->assertStockMoved($this->plain, $before, '-4', '-400');
        $this->assertDealerMoved($before['ledger'], '0');
        $this->assertSame(0, bccomp($invoice->fresh()->dueAmount(), '0', 4),
            '⛔ চার্জ কাটা গেছে বলে গ্রাহকের বিল অশোধ থেকে গেছে — চার্জ ডিপোর খরচ, গ্রাহকের নয়।');
        $this->assertSame(0, bccomp($this->balanceOf('110291'), '590', 4));
        $this->assertGrossProfit($before['ledger'], '200');

        // ⓘ লেনদেন নম্বর আর চার্জ ভাউচারের গায়ে — ব্যাংকের কাগজের সাথে মেলানোর একমাত্র সুতো
        $voucher = $this->counterVoucherOf($invoice);
        $this->assertSame('BRAC-TRX-5521', $voucher->instrument_no, '⛔ লেনদেন নম্বর ভাউচারে পৌঁছায়নি।');
        $this->assertSame('SLIP-77', $voucher->deposit_slip_no, '⛔ জমার স্লিপ নম্বর ভাউচারে পৌঁছায়নি।');
        $this->assertSame(0, bccomp((string) $voucher->charge_amount, '10', 4), '⛔ চার্জ ভাউচারে লেখা নেই।');
        $this->assertSame(Voucher::ORIGIN_COUNTER, $voucher->origin);

        $this->assertPrintedTotalIsTheLedgerTotal($invoice, '600');
    }

    /**
     * ⭐ বিকাশে জমা, চার্জসহ: বিকাশের খাতে, চার্জ বিকাশ-চার্জ খাতে (৫২১১) — ব্যাংকের খাতে নয়।
     *
     * হাতে গোনা:
     *   ৪ × ১৫০ = ৬০০ — বিকাশে ৬০০, চার্জ ১১.১০ (১.৮৫%)
     *   বিকাশ +৫৮৮.৯০ · বিকাশ-চার্জ +১১.১০ · গ্রাহক শোধ ৬০০
     */
    public function test_a_bkash_deposit_lands_in_the_wallet_with_its_charge_and_its_transaction_number(): void
    {
        $bkash = $this->moneyAccount(StandardChart::MOBILE_MONEY, '110591', Account::MFS, 'Proof bKash Merchant');

        $before = $this->snapshot($this->plain);

        $this->sell([$this->line($this->plain, '4', '150')], [[
            'amount' => '600',
            'account_id' => $bkash->id,
            'payment_method_id' => $this->method('MFS'),
            'reference' => 'BKX9Q7Z3A1',
            'wallet' => 'bkash',
            'counterparty_phone' => '01711000999',
            'charge_amount' => '11.10',
        ]])->assertSessionHasNoErrors();

        $invoice = $this->lastInvoiceOf($this->dealer);

        $this->assertBooksMoved($before['ledger'], [
            '110591' => '588.90',
            StandardChart::MFS_CHARGES => '11.10',
            StandardChart::SALES => '-600',
            StandardChart::COST_OF_GOODS_SOLD => '400',
            StandardChart::INVENTORY => '-400',
        ]);
        $this->assertStockMoved($this->plain, $before, '-4', '-400');
        $this->assertDealerMoved($before['ledger'], '0');
        $this->assertSame(0, bccomp($this->balanceOf('110591'), '588.90', 4));
        $this->assertGrossProfit($before['ledger'], '200');

        $voucher = $this->counterVoucherOf($invoice);
        $this->assertSame('BKX9Q7Z3A1', $voucher->instrument_no, '⛔ বিকাশের TrxID ভাউচারে পৌঁছায়নি।');
        $this->assertSame('01711000999', $voucher->counterparty_phone, '⛔ পাঠানো নম্বরটা ভাউচারে নেই।');
        $this->assertSame(0, bccomp((string) $voucher->charge_amount, '11.10', 4));

        $this->assertPrintedTotalIsTheLedgerTotal($invoice, '600');
    }

    /**
     * ⛔ কাউন্টারের জমা কেবল টাকার খাতে — নিরীক্ষা §১.১।
     *
     * ⚠️ বিপজ্জনক ইনপুটগুলো দিয়েই খাওয়ানো হয়: বিক্রয়ের খাত, খরচের খাত
     * (ভাড়া), ব্যাংকের **মাথা** (গ্রুপ), আর হাতে-চেক (১১০৪)। ⓘ এর যেকোনো
     * একটা পেরোলে বকেয়া মুছে যেত অথচ কোনো টাকা আসত না — আর সীমার দেয়াল
     * পার হয়ে যেত। প্রতিটায়: `deposits` ঘরে ফেরত, আর খাতা-মজুদ কিছুই নড়ে না।
     *
     * ⓘ পাল্টা-দাবি (সত্যিকারের ব্যাংক/বিকাশ খাত পেরোয়) উপরের দুই পরীক্ষায়।
     */
    public function test_the_counter_deposit_goes_only_to_a_money_account(): void
    {
        foreach ([
            'বিক্রয়ের খাত' => StandardChart::SALES,
            'ভাড়ার খরচ' => StandardChart::RENT,
            'ব্যাংকের মাথা (গ্রুপ)' => StandardChart::BANK,
            'হাতে চেক' => StandardChart::CHEQUES_IN_HAND,
        ] as $label => $code) {
            $before = $this->snapshot($this->plain);
            $invoices = SalesInvoice::query()->count();

            $this->sell([$this->line($this->plain, '2', '150')], [[
                'amount' => '300',
                'account_id' => Account::query()->where('code', $code)->firstOrFail()->id,
            ]])->assertSessionHasErrors('deposits');

            $this->assertBooksMoved($before['ledger'], [], "⛔ {$label}-এ জমা নেওয়া হয়ে গেছে।");
            $this->assertStockMoved($this->plain, $before, '0', '0');
            $this->assertSame($invoices, SalesInvoice::query()->count(), "⛔ {$label}: ফেরানো বিক্রির বিল রয়ে গেছে।");
        }
    }

    /**
     * ⛔ পদ্ধতি আর খাত মেলে না — "নগদ" পদ্ধতিতে বিকাশের খাত (আর উল্টোটা) — ২৮ সেপ্টেম্বর ২০২৬।
     *
     * ⓘ দুইটাই টাকার খাত, তাই উপরের দাবি পেরোত। কিন্তু রসিদে লেখা থাকত "নগদ" আর টাকা
     * বসত বিকাশে — ক্যাশ গোনায় ঘাটতি, বিকাশের জের বাড়তি, আর দুইটা কোনোদিন মিলত না।
     * ⭐ নিয়ম এক জায়গায় ([[MethodFitsAccount]]) — কাউন্টার, ক্রয় আর সেটিংস একই কথা বলে।
     * ⓘ পাল্টা-দাবি (বিকাশ পদ্ধতি + বিকাশ খাত, পাঁচ মিলসহ) উপরের বিকাশের পরীক্ষায়।
     */
    public function test_the_deposit_method_must_fit_its_account(): void
    {
        $bkash = $this->moneyAccount(StandardChart::MOBILE_MONEY, '110592', Account::MFS, 'Proof bKash Fit');
        $till = Account::query()->where('code', $this->tillCode())->firstOrFail();

        foreach ([
            'নগদ পদ্ধতি, বিকাশের খাত' => [$this->method('CASH'), $bkash->id],
            'বিকাশ পদ্ধতি, নগদের বাক্স' => [$this->method('MFS'), $till->id],
        ] as $label => [$method, $account]) {
            $before = $this->snapshot($this->plain);
            $invoices = SalesInvoice::query()->count();

            $this->sell([$this->line($this->plain, '2', '150')], [[
                'amount' => '300',
                'account_id' => $account,
                'payment_method_id' => $method,
                // ⓘ বিকাশের বাকি ঘর ভরা — যাতে থামাটা কেবল পদ্ধতি-খাতের অমিলে, অন্য নিয়মে নয়
                'reference' => 'BKFIT'.$method,
                'wallet' => 'bkash',
                'counterparty_phone' => '01711000998',
            ]])->assertSessionHasErrors('deposits.0.account_id');

            $this->assertBooksMoved($before['ledger'], [], "⛔ {$label}: জমা খাতায় বসে গেছে।");
            $this->assertStockMoved($this->plain, $before, '0', '0');
            $this->assertSame($invoices, SalesInvoice::query()->count(), "⛔ {$label}: ফেরানো বিক্রির বিল রয়ে গেছে।");
        }
    }

    // ══ ⓸ আংশিক জমা আর বাকি ═══════════════════════════════════════════════

    /**
     * ⭐ নগদে কিছু, বিকাশে কিছু, বাকিটা বাকিতে — তিনটা অংশই নিজের খাতে।
     *
     * হাতে গোনা (সীমা ৫,০০০):
     *   ১০ × ১৫০ = ১,৫০০ — নগদ ৫০০ + বিকাশ ৪০০ = ৯০০ জমা, বাকি ৬০০
     *   খরচ: ৫ × ১০০ + ৫ × ১২০ = ১,১০০; লাভ ৪০০
     */
    public function test_part_paid_in_cash_and_bkash_leaves_exactly_the_rest_on_the_dealer(): void
    {
        $this->dealer->forceFill(['credit_limit' => '5000'])->save();
        $bkash = $this->moneyAccount(StandardChart::MOBILE_MONEY, '110592', Account::MFS, 'Proof bKash Personal');

        $before = $this->snapshot($this->plain);

        $this->sell([$this->line($this->plain, '10', '150')], [
            $this->cash('500'),
            ['amount' => '400', 'account_id' => $bkash->id, 'payment_method_id' => $this->method('MFS'),
                'reference' => 'BKPART01'],
        ])->assertSessionHasNoErrors();

        $invoice = $this->lastInvoiceOf($this->dealer);

        $this->assertBooksMoved($before['ledger'], [
            $this->tillCode() => '500',
            '110592' => '400',
            StandardChart::RECEIVABLE => '600',
            StandardChart::SALES => '-1500',
            StandardChart::COST_OF_GOODS_SOLD => '1100',
            StandardChart::INVENTORY => '-1100',
        ]);
        $this->assertStockMoved($this->plain, $before, '-10', '-1100');
        $this->assertDealerMoved($before['ledger'], '600');
        $this->assertSame(0, bccomp($invoice->fresh()->dueAmount(), '600', 4),
            '⛔ বিলের বকেয়া হাতে গোনা ৬০০ নয়।');
        $this->assertSame(2, Voucher::query()->where('against_id', $invoice->id)
            ->where('origin', Voucher::ORIGIN_COUNTER)->count(),
            '⛔ দুইটা জমা দুইটা আলাদা রসিদ হয়নি।');
        $this->assertGrossProfit($before['ledger'], '400');

        $printed = $this->assertPrintedTotalIsTheLedgerTotal($invoice, '1500');
        $this->assertSame(Money::format('600'), $printed['sales::print.invoice_due'] ?? null,
            '⛔ ছাপা বিলের "বকেয়া" হাতে গোনা ৬০০ নয়।');
    }

    // ══ ⓹ ছাড়, ভ্যাট আর রাউন্ডিং ═════════════════════════════════════════

    /**
     * ⭐ সারির ছাড় আর ভ্যাট: ভ্যাট প্রদেয় খাতে, বিক্রয় ছাড়ের পরের দামে, আর ছাপা মোট = খাতার মোট।
     *
     * হাতে গোনা (ভ্যাট ১৫%, দামের বাইরে; পণ্যের স্তর ২০টা @ ১০০):
     *   ৮ × ১৫০ = ১,২০০ · সারির ছাড় ১০% = ১২০ → ১,০৮০
     *   ভ্যাট ১,০৮০ × ১৫% = ১৬২ → মোট ১,২৪২ — নগদে পুরোটা
     *   বিক্রয় ১,০৮০ · ভ্যাট ১৬২ · খরচ ৮০০ · লাভ ২৮০
     */
    public function test_a_line_discount_and_vat_reach_their_own_accounts_and_the_printed_total(): void
    {
        $vatted = $this->vattedProduct();
        $before = $this->snapshot($vatted);

        $this->sell([$this->line($vatted, '8', '150', ['discount_percent' => '10'])], [$this->cash('1242')])
            ->assertSessionHasNoErrors();

        $invoice = $this->lastInvoiceOf($this->dealer);

        $this->assertBooksMoved($before['ledger'], [
            $this->tillCode() => '1242',
            StandardChart::SALES => '-1080',
            StandardChart::VAT_PAYABLE => '-162',
            StandardChart::COST_OF_GOODS_SOLD => '800',
            StandardChart::INVENTORY => '-800',
        ]);
        $this->assertStockMoved($vatted, $before, '-8', '-800');
        $this->assertDealerMoved($before['ledger'], '0');
        $this->assertGrossProfit($before['ledger'], '280');

        $this->assertSame(0, bccomp((string) $invoice->discount, '120', 4), '⛔ বিলে সারির ছাড় ১২০ নয়।');
        $this->assertSame(0, bccomp((string) $invoice->tax, '162', 4), '⛔ বিলে ভ্যাট ১৬২ নয়।');
        $this->assertPrintedTotalIsTheLedgerTotal($invoice, '1242');
    }

    /**
     * ⛔ কোনো সেটিং না থাকলে বিক্রির ভ্যাট বন্ধ — ভ্যাটওয়ালা পণ্যেও ভ্যাট বসে না, আর পাঁচ মিল মেলে।
     *
     * ⓘ মালিক, ২৮ সেপ্টেম্বর ২০২৬ (রাত): দুই সুইচ, ডিফল্টে সব জায়গায় বন্ধ। ডেমোতে সিডার চালু করে;
     * এখানে সেটিং মুছে লাইভের কোম্পানির হাল দেখা হয়।
     *
     * হাতে গোনা (উপরের পরীক্ষার সারি): ৮ × ১৫০ = ১,২০০ − ১০% = ১,০৮০ — ভ্যাট ০
     *   নগদ +১,০৮০ · বিক্রয় −১,০৮০ · খরচ ৮০০ · মজুদ −৮০০ · লাভ ২৮০
     */
    public function test_with_no_sales_vat_setting_a_vatted_product_sells_without_vat(): void
    {
        app(SettingsService::class)->reset('sales.vat_enabled');
        // ⚠️ বিকল্প মান true — সুইচ ঘোষিত না থাকলে "বন্ধ" পড়ে সবুজ না হয়
        $this->assertFalse((bool) app(SettingsService::class)->get('sales.vat_enabled', true),
            '⛔ কোনো সেটিং নেই, অথচ বিক্রির ভ্যাট চালু — মালিকের ডিফল্ট বন্ধ।');

        $vatted = $this->vattedProduct();
        $before = $this->snapshot($vatted);

        $this->sell([$this->line($vatted, '8', '150', ['discount_percent' => '10'])], [$this->cash('1080')])
            ->assertSessionHasNoErrors();

        $invoice = $this->lastInvoiceOf($this->dealer);

        $this->assertBooksMoved($before['ledger'], [
            $this->tillCode() => '1080',
            StandardChart::SALES => '-1080',
            StandardChart::COST_OF_GOODS_SOLD => '800',
            StandardChart::INVENTORY => '-800',
        ], '⛔ ভ্যাট বন্ধ, অথচ খাতায় ভ্যাটের সারি।');
        $this->assertStockMoved($vatted, $before, '-8', '-800');
        $this->assertDealerMoved($before['ledger'], '0');
        $this->assertGrossProfit($before['ledger'], '280');
        $this->assertSame(0, bccomp((string) $invoice->tax, '0', 4), '⛔ ভ্যাট বন্ধ, অথচ বিলে ভ্যাট।');
        $this->assertPrintedTotalIsTheLedgerTotal($invoice, '1080');
    }

    /** ⛔ বিক্রির ভ্যাট বন্ধ থাকলে বিলে পাঠানো ভ্যাট থামে — চুপচাপ শূন্য নয়। */
    public function test_with_sales_vat_off_a_posted_vat_is_refused(): void
    {
        app(SettingsService::class)->set('sales.vat_enabled', false);

        try {
            app(\App\Modules\Sales\Services\SalesInvoiceService::class)->create([
                'customer_id' => $this->dealer->id,
                'trx_date' => now()->toDateString(),
            ], [['product_id' => $this->plain->id, 'qty' => '1', 'rate' => '150', 'tax' => '22.50']]);
            $this->fail('⛔ বিক্রির ভ্যাট বন্ধ, অথচ ভ্যাটওয়ালা বিল বসে গেছে।');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('lines', $e->errors());
        }
    }

    /**
     * ⭐ বিলের ছাড় আর রাউন্ডিং: পর্দা যা নিল, বিল আর খাতাও ঠিক তাই বলে।
     *
     * হাতে গোনা (আগের পরীক্ষার সারি, তার উপর):
     *   সারির মোট ১,২৪২ (ছাড় ১২০, ভ্যাট ১৬২ সহ)
     *   বিলের ছাড় ৪০ · রাউন্ডিং −২ → দিতে হবে ১,২০০ (পর্দার `netPayable` —
     *   grossTotal − discountValue + expenseValue + roundingValue)
     *   ক্রেতা নগদে ১,২০০ দিলেন, বকেয়া শূন্য।
     *   ভ্যাট ১৬২ (ছাড়ের পরের দামে ভ্যাট আগেই গোনা) · বিক্রয় ১,২০০ − ১৬২ = ১,০৩৮
     *   খরচ ৮০০ · লাভ ২৩৮
     *
     * ⚠️ বিলের ছাড় কোন খাতে যায় (নিট বিক্রয়, নাকি আলাদা "ছাড় প্রদত্ত")
     * সেটা এখানে চাপানো হয়নি ইচ্ছাকৃতভাবে: কোড আজ সারির ছাড় বিক্রয় থেকেই
     * কাটে, তাই একই নিয়ম ধরা হয়েছে। ⛔ যা চাপানো হয়েছে তা হলো **মোট**:
     * গ্রাহকের কাছে পাওনা, ছাপা মোট আর ড্রয়ারের টাকা একই সংখ্যা।
     */
    public function test_a_bill_discount_and_rounding_reach_the_bill_the_books_and_the_printed_total(): void
    {
        $vatted = $this->vattedProduct();
        $before = $this->snapshot($vatted);

        $this->sell(
            [$this->line($vatted, '8', '150', ['discount_percent' => '10'])],
            [$this->cash('1200')],
            ['discount_amount' => '40', 'rounding_amount' => '-2'],
        )->assertSessionHasNoErrors();

        $invoice = $this->lastInvoiceOf($this->dealer);

        $this->assertSame(0, bccomp((string) $invoice->total, '1200', 4),
            '⛔ বিলের মোট '.$invoice->total.', অথচ পর্দা ক্রেতার কাছ থেকে ১,২০০ নিয়েছে '
            .'(বিলের ছাড় ৪০ আর রাউন্ডিং −২ বিলে পৌঁছায়নি)।');
        $this->assertDealerMoved($before['ledger'], '0',
            '⛔ ক্রেতা পর্দার পুরো টাকা দিয়েও বকেয়া নিয়ে বাড়ি গেলেন।');
        $this->assertBooksMoved($before['ledger'], [
            $this->tillCode() => '1200',
            StandardChart::SALES => '-1038',
            StandardChart::VAT_PAYABLE => '-162',
            StandardChart::COST_OF_GOODS_SOLD => '800',
            StandardChart::INVENTORY => '-800',
        ]);
        $this->assertStockMoved($vatted, $before, '-8', '-800');
        $this->assertGrossProfit($before['ledger'], '238');
        $this->assertPrintedTotalIsTheLedgerTotal($invoice, '1200');
    }

    /**
     * ⛔ ছাপা মোট আর খাতার মোট পয়সার নিচেও এক — ভ্যাট ভগ্ন পয়সা বানালেও।
     *
     * হাতে গোনা (বিপজ্জনক ইনপুট — শতাংশ ছাড়ের পরে ভ্যাট তিন দশমিকে পড়ে):
     *   ৩ × ১৫০ = ৪৫০ · ছাড় ৭% = ৩১.৫০ → ৪১৮.৫০
     *   ভ্যাট ৪১৮.৫০ × ১৫% = ৬২.৭৭৫ → মোট ৪৮১.২৭৫
     *
     * ⓘ টাকা পয়সায় গোনা হয় — ড্রয়ারে ০.৫ পয়সা নেই। ⚠️ তাই দাবিটা:
     * ছাপা মোটের অঙ্ক (কমা বাদে) = খাতায় পাওনার ডেবিট, চার দশমিক পর্যন্ত।
     * ⛔ না মিললে ক্রেতা ছাপা অঙ্ক দেন, আর খাতায় ভগ্ন পয়সা চিরকাল বকেয়া।
     */
    public function test_the_printed_total_equals_the_ledger_total_even_when_vat_makes_a_fraction_of_a_paisa(): void
    {
        // ⓘ সীমা না থাকলে বাকি নেই (মালিক, ১ অক্টোবর ২০২৬) — এই দাবি বাকির খাতা মাপে, সীমা নয়
        $this->dealer->forceFill(['credit_limit' => '5000'])->save();
        $vatted = $this->vattedProduct();

        $this->sell([$this->line($vatted, '3', '150', ['discount_percent' => '7'])])
            ->assertSessionHasNoErrors();

        $invoice = $this->lastInvoiceOf($this->dealer);

        $printed = $this->printedTotals($invoice);
        $printedTotal = str_replace(',', '', (string) ($printed['core.print.total'] ?? ''));
        $booked = $this->receivableDebitOf($invoice);

        $this->assertSame(0, bccomp($printedTotal, $booked, 4),
            "⛔ ছাপা বিলের মোট {$printedTotal}, খাতায় পাওনা {$booked} — ক্রেতা ছাপা অঙ্ক দিলে "
            .'বাকি ভগ্ন পয়সা চিরকাল বকেয়া থাকে।');
    }

    // ══ পাঁচ মিল — সহায়ক (ChecksTheFiveMatches trait এলে সেখানে যাবে) ══════

    /**
     * লেনদেনের আগের ছবি।
     *
     * @return array{ledger: int, floor: string, layers: string, till: string}
     */
    private function snapshot(Product $product): array
    {
        return [
            'ledger' => (int) DB::table('ledger_entries')->max('id'),
            'floor' => $this->floorOf($product),
            'layers' => $this->layerValueOf($product),
            'till' => $this->balanceOf($this->tillCode()),
        ];
    }

    /**
     * ⓵ খাতা — নতুন সারিগুলোতে ডেবিট = ক্রেডিট, আর খাতপ্রতি নড়াচড়া হুবহু প্রত্যাশা।
     *
     * ⚠️ তালিকার বাইরের কোনো খাত নড়লেও লাল — ⛔ কেবল চেনা খাতগুলো দেখলে
     * টাকা ভুল খাতে চলে গেলেও বাকিগুলো দিব্যি মিলত।
     *
     * @param  array<string, string>  $expected  খাতের কোড → নিট (ডেবিট − ক্রেডিট)
     */
    private function assertBooksMoved(int $fromId, array $expected, string $why = ''): void
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

        $this->assertSame($want, $actual, trim($why."\n⛔ খাতের নড়াচড়া হাতে গোনা অঙ্কের সাথে মেলে না।"));
    }

    /**
     * ⓶ মজুদ — তাকের পরিমাণ, স্তরের মূল্য, আর ব্যালান্স শিটের মজুদ = সব স্তরের মূল্য।
     *
     * @param  array{floor: string, layers: string}  $before
     */
    private function assertStockMoved(Product $product, array $before, string $qty, string $value): void
    {
        $this->assertSame(0, bccomp($this->floorOf($product), bcadd($before['floor'], $qty, 4), 4),
            "⛔ তাকের পরিমাণ {$qty} নড়েনি।");
        $this->assertSame(0, bccomp($this->layerValueOf($product), bcadd($before['layers'], $value, 4), 4),
            "⛔ FIFO স্তরের মূল্য {$value} নড়েনি।");

        $layers = (string) DB::table('inv_cost_layers')->where('company_id', $this->company->id)
            ->selectRaw('COALESCE(SUM(qty_remaining * unit_cost),0) AS v')->value('v');

        $this->assertSame(0, bccomp($this->balanceOf(StandardChart::INVENTORY), $layers, 4),
            "⛔ ব্যালান্স শিটের মজুদ-খাত {$this->balanceOf(StandardChart::INVENTORY)} ≠ স্তরের মোট মূল্য {$layers}।");
    }

    /**
     * ⓷ গ্রাহকের খতিয়ান — তাঁর নামের সারিগুলোর নড়াচড়া = পাওনা-খাতের নড়াচড়া = প্রত্যাশা।
     *
     * ⚠️ পাওনা-খাতে নামহীন একটা সারিও থাকলে গ্রাহকের খতিয়ান আর খাত আলাদা হয়ে যায়।
     */
    private function assertDealerMoved(int $fromId, string $expected, string $why = ''): void
    {
        $receivable = (int) Account::query()->where('code', StandardChart::RECEIVABLE)->value('id');

        $party = (string) DB::table('ledger_entries')->where('id', '>', $fromId)
            ->where('party_type', 'customer')->where('party_id', $this->dealer->id)
            ->selectRaw('COALESCE(SUM(debit) - SUM(credit),0) AS n')->value('n');

        $account = (string) DB::table('ledger_entries')->where('id', '>', $fromId)
            ->where('account_id', $receivable)
            ->selectRaw('COALESCE(SUM(debit) - SUM(credit),0) AS n')->value('n');

        $this->assertSame(0, bccomp($party, $expected, 4),
            trim($why."\n⛔ গ্রাহকের খতিয়ান {$party} নড়েছে, হাতে গোনা {$expected}।"));
        $this->assertSame(0, bccomp($account, $party, 4),
            "⛔ পাওনা-খাত {$account} নড়েছে, গ্রাহকের খতিয়ান {$party} — নামহীন সারি আছে।");
    }

    /** ⓹ লাভ — বিক্রয়ের ক্রেডিট − বিক্রীত পণ্যের খরচের ডেবিট = হাতে গোনা লাভ। */
    private function assertGrossProfit(int $fromId, string $expected): void
    {
        $net = fn (string $code): string => (string) DB::table('ledger_entries')->where('id', '>', $fromId)
            ->where('account_id', Account::query()->where('code', $code)->value('id'))
            ->selectRaw('COALESCE(SUM(debit) - SUM(credit),0) AS n')->value('n');

        $profit = bcsub(bcmul($net(StandardChart::SALES), '-1', 4), $net(StandardChart::COST_OF_GOODS_SOLD), 4);

        $this->assertSame(0, bccomp($profit, $expected, 4), "⛔ মোট লাভ {$profit}, হাতে গোনা {$expected}।");
    }

    /**
     * ছাপা বিলের মোট = হাতে গোনা মোট = খাতায় পাওনার ডেবিট।
     *
     * @return array<string, string> ছাপা কাগজের মোটের সারিগুলো
     */
    private function assertPrintedTotalIsTheLedgerTotal(SalesInvoice $invoice, string $expected): array
    {
        $this->assertSame(0, bccomp($this->receivableDebitOf($invoice), $expected, 4),
            '⛔ খাতায় বিলের পাওনা '.$this->receivableDebitOf($invoice).", হাতে গোনা {$expected}।");

        $printed = $this->printedTotals($invoice);

        $this->assertSame(Money::format($expected), $printed['core.print.total'] ?? null,
            '⛔ ছাপা বিলের মোট খাতার মোটের সমান নয়।');

        return $printed;
    }

    /** @return array<string, string> */
    private function printedTotals(SalesInvoice $invoice): array
    {
        $seen = [];

        View::composer('print.document', function ($view) use (&$seen) {
            $seen = $view->getData();
        });

        $this->get(route('sales.print.invoice', ['invoice' => $invoice->id, 'paper' => '80mm']))->assertOk();

        $this->assertArrayHasKey('doc', $seen, 'ছাপার পাতাটা কাগজই আঁকেনি।');

        return $seen['doc']->totals;
    }

    private function receivableDebitOf(SalesInvoice $invoice): string
    {
        return bcadd((string) DB::table('ledger_entries')
            ->where('source_type', SalesInvoice::drillSourceType())
            ->where('source_id', $invoice->id)
            ->where('account_id', Account::query()->where('code', StandardChart::RECEIVABLE)->value('id'))
            ->selectRaw('COALESCE(SUM(debit),0) AS d')->value('d'), '0', 4);
    }

    // ── মাপার যন্ত্র ─────────────────────────────────────────────────────

    private function balanceOf(string $code): string
    {
        $id = Account::query()->where('code', $code)->value('id');

        return bcadd((string) DB::table('ledger_entries')->where('account_id', $id)
            ->selectRaw('COALESCE(SUM(debit) - SUM(credit),0) AS n')->value('n'), '0', 4);
    }

    private function floorOf(Product $product): string
    {
        return bcadd((string) DB::table('inv_stock_movements')
            ->where('product_id', $product->id)->where('warehouse_id', $this->warehouse->id)
            ->selectRaw('COALESCE(SUM(floor_change),0) AS q')->value('q'), '0', 4);
    }

    private function layerValueOf(Product $product): string
    {
        return bcadd((string) DB::table('inv_cost_layers')->where('product_id', $product->id)
            ->selectRaw('COALESCE(SUM(qty_remaining * unit_cost),0) AS v')->value('v'), '0', 4);
    }

    /** @return list<string> প্রতিটা স্তরে কতটা বাকি, পুরনো থেকে নতুন */
    private function layersLeft(Product $product): array
    {
        return CostLayer::query()->where('product_id', $product->id)->orderBy('trx_date')->orderBy('id')
            ->pluck('qty_remaining')->map(fn ($q) => bcadd((string) $q, '0', 4))->all();
    }

    // ── প্রস্তুতি ────────────────────────────────────────────────────────

    /**
     * কাউন্টারের আসল দরজা দিয়ে একটা বিক্রি।
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  list<array<string, mixed>>  $deposits
     * @param  array<string, mixed>  $extra
     */
    private function sell(array $lines, array $deposits = [], array $extra = []): TestResponse
    {
        $response = $this->actingAs($this->owner)->post(route('sales.direct.store'), [
            'own_transport' => '1', // ⓘ ধাপ ৫ — নিশ্চিতে পরিবহন লাগে ([[TransportRule]]); এই দাবি অন্য কিছু মাপে
            'customer_id' => $this->dealer->id,
            'warehouse_id' => $this->warehouse->id,
            'lines' => $lines,
            ...($deposits === [] ? [] : ['deposits' => $deposits]),
            ...$extra,
        ]);

        // ⓘ যেকোনো ছাড়ে মালিকের সই (১ অক্টোবর ২০২৬) — এই দাবি হিসাব মাপে, সই নয় ([[SignsTheDiscountAsTheOwner]]) — শেষ সইয়ে বিক্রি নিজে শেষ
        $this->ownerSignsTheDiscounts();

        return $response;
    }

    /** @param  array<string, mixed>  $more */
    private function line(Product $product, string $qty, string $rate, array $more = []): array
    {
        return ['product_id' => $product->id, 'qty' => $qty, 'rate' => $rate, ...$more];
    }

    /** নগদের জমা — প্রধান টিলের খাতে, পর্দা যেভাবে পাঠায়। */
    private function cash(string $amount): array
    {
        return [
            'amount' => $amount,
            'account_id' => app(CashTillService::class)->ensurePrimaryTill()->account_id,
            'payment_method_id' => $this->method('CASH'),
        ];
    }

    private function tillCode(): string
    {
        return (string) app(CashTillService::class)->ensurePrimaryTill()->account->code;
    }

    private function method(string $code): int
    {
        return (int) PaymentMethod::query()->where('code', $code)->firstOrFail()->id;
    }

    private function lastInvoiceOf(Customer $customer): SalesInvoice
    {
        $invoice = SalesInvoice::query()->where('customer_id', $customer->id)->latest('id')->firstOrFail();

        $this->assertSame(DocumentStatus::CONFIRMED, $invoice->status, 'বিলটা নিশ্চিত হয়নি — দৃশ্যটাই বানানো যায়নি।');

        return $invoice;
    }

    private function counterVoucherOf(SalesInvoice $invoice): Voucher
    {
        return Voucher::query()->where('against_id', $invoice->id)
            ->where('origin', Voucher::ORIGIN_COUNTER)->latest('id')->firstOrFail();
    }

    /**
     * নতুন ডিলার — শূন্য সীমায় জন্মায় (নিরীক্ষা §১.২), সীমা পরে সরাসরি বসে।
     */
    private function newDealer(string $limit): Customer
    {
        $customer = Customer::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->company->defaultBranch()?->id,
            'code' => 'CUS-PROOF',
            'name_en' => 'Proof Dealer',
            'credit_limit' => '0',
            'credit_days' => 0,
            'status' => DocumentStatus::CONFIRMED,
            'is_active' => true,
        ]);

        if (bccomp($limit, '0', 4) > 0) {
            $customer->forceFill(['credit_limit' => $limit])->save();
        }

        return $customer->fresh();
    }

    /** ব্যাংক বা বিকাশের একটা আসল টাকার খাত — মায়ের (১১০২ / ১১০৫) সন্তান। */
    private function moneyAccount(string $parentCode, string $code, string $kind, string $name): Account
    {
        $parent = Account::query()->where('code', $parentCode)->firstOrFail();

        return Account::query()->create([
            'parent_id' => $parent->id,
            'code' => $code,
            'name_en' => $name,
            'type' => $parent->type,
            'nature' => $parent->nature,
            'is_group' => false,
            'money_kind' => $kind,
        ]);
    }

    /** ভ্যাট ১৫% (দামের বাইরে) লাগা পণ্য — এক স্তর, ২০টা @ ১০০। */
    private function vattedProduct(): Product
    {
        $tax = Tax::query()->where('code', 'VAT15')->first()
            ?? Tax::query()->create([
                'code' => 'VAT15', 'name_en' => 'VAT 15%', 'name_bn' => 'ভ্যাট ১৫%',
                'rate' => '15', 'kind' => 'vat', 'is_inclusive' => false, 'is_active' => true,
            ]);

        $this->assertFalse((bool) $tax->is_inclusive, 'দৃশ্যটাই বানানো যায়নি — ভ্যাট দামের ভিতরে।');
        $this->assertSame(0, bccomp((string) $tax->rate, '15', 4), 'দৃশ্যটাই বানানো যায়নি — হার ১৫% নয়।');

        return $this->productWithLayers('PRF-VAT', 'Proof Vatted Oil', $tax->id, [
            ['20', '100', now()->subDays(3)->toDateString()],
        ]);
    }

    /**
     * পণ্য আর তার খোলা মজুদ — ডেমো যে পথে বসায় ঠিক সেই পথে: তাকে মাল,
     * FIFO স্তর, আর খাতায় মজুদ-খাত (নইলে ব্যালান্স শিটের মিলটা শুরু থেকেই ভাঙা)।
     *
     * @param  list<array{0: string, 1: string, 2: string}>  $layers  পরিমাণ, দর, তারিখ
     */
    private function productWithLayers(string $code, string $name, ?int $taxId, array $layers): Product
    {
        $product = app(ProductService::class)->create([
            'code' => $code,
            'name_en' => $name,
            'name_bn' => $name,
            'unit_id' => Unit::query()->where('code', 'PCS')->value('id'),
            'purchase_price' => '100',
            'sale_price' => '150',
            'tax_id' => $taxId,
        ]);

        foreach ($layers as [$qty, $cost, $date]) {
            $movement = app(StockService::class)->move(
                product: $product, warehouse: $this->warehouse,
                sourceType: 'opening', sourceId: $product->id,
                floor: $qty, date: $date, narration: 'proof opening',
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
        }

        return $product->fresh();
    }
}
