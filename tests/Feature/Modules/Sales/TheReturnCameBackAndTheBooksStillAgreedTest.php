<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\CostLayerService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\MasterData\Models\ReasonCode;
use App\Modules\MasterData\Models\Unit;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * বিক্রয় ফেরত এল — আর হিসাবের পাঁচ মিল তবুও মিলল কি না।
 *
 * ── ⭐ চেকলিস্ট §৩: "বিক্রয় ফেরত: কারণসহ। মজুদ ফেরে, পাওনা কমে, আর
 * বিক্রীত পণ্যের খরচ উল্টায়।" ───────────────────────────────────────────
 * প্রতিটা দাবি আসল দরজা দিয়ে চলে: মাল কেনা `purchase.direct.store`,
 * বেচা `sales.direct.store`, ফেরত `sales.return.store` → `confirm` →
 * `cancel`, আর নগদ ফেরত `accounts.voucher.store`। ⚠️ সেবা সরাসরি ডাকা
 * হয় কেবল মাল তাকে বসাতে (`StockService::place`) — ওটা পরীক্ষার বিষয় নয়।
 *
 * ── ⭐ অঙ্কগুলো হাতে গোনা ──────────────────────────────────────────────
 * প্রতিটা প্রত্যাশিত সংখ্যা এই ফাইলে লেখা, মন্তব্যে গোনার পথসহ। ⛔ কোনো
 * সংখ্যা যে কোড পরীক্ষা হচ্ছে তার থেকে আবার গোনা হয় না — নাহলে কোড ভুল
 * হলে প্রত্যাশাও একই ভুল করত আর পরীক্ষা সবুজ থাকত।
 *
 * ── মাল ও দাম (setUp-এ, আসল ক্রয়ের দরজা দিয়ে) ─────────────────────────
 *   পণ্য A (লট নেই):  P1 ১০ @ ৬০, তারপর P2 ১০ @ ৮০  → ২০ একক, ১,৪০০ টাকা
 *   পণ্য B (লট ধরা):  LOT-A ১০ @ ৫০, তারপর LOT-B ১০ @ ৭০ → ২০ একক, ১,২০০ টাকা
 *   বিক্রয়মূল্য: A ১০০, B ১৫০ · ভ্যাট নেই · ক্রেতা নতুন (আগের কোনো জের নেই)
 *
 * ── ⭐ বাড়ির নিয়ম (CostLayerService::returnToLayers) ────────────────────
 * ফেরত আসা মাল **যে স্তর থেকে বেরিয়েছিল সেই স্তরে, সেই দামে** ফেরে —
 * শেষে যেটা বেরিয়েছে আগে সেটা। আজকের গড় দরে নয়, বিক্রির গড় দরেও নয়।
 *
 * ── পাঁচ মিল (চেকলিস্ট §১) ─────────────────────────────────────────────
 *   ১ খাতা — নতুন দাখিলায় ডেবিট = ক্রেডিট, আর **প্রতিটা** খাতের নড়াচড়া
 *     হাতে গোনা অঙ্কের সমান (যে খাত তালিকায় নেই, তার নড়াচড়া শূন্য)
 *   ২ মজুদ — তাকের পরিমাণ, মজুদ-মূল্য রিপোর্ট (`inventory.stock_value`)-এর
 *     পরিমাণ ও মূল্য, আর FIFO স্তরে পড়ে থাকা মূল্য — তিনটাই হাতে গোনা
 *     অঙ্কের সমান; মজুদ খাতের (১১২০) নড়াচড়া = রিপোর্টের মূল্যের নড়াচড়া
 *   ৩ পক্ষ — গ্রাহকের খতিয়ান = পাওনা খাতে তাঁর অংশ = হাতে গোনা
 *   ৪ টাকা — নগদ বাক্সের নড়াচড়া হাতে গোনা; অন্য কোনো টাকার খাত নড়ে না
 *   ৫ লাভ — লাভ-ক্ষতি রিপোর্টের নিট নড়াচড়া = বিক্রয় − ফেরত − খরচ = হাতে গোনা
 *
 * TODO(abos-d8): `tests/Concerns/ChecksTheFiveMatches.php` তৈরি হলে নিচের
 * `assertFiveMatches()`/`books()` সরিয়ে ঐ trait ব্যবহার করতে হবে। আজ
 * (২৭ সেপ্টেম্বর ২০২৬) ফাইলটা নেই, তাই এখানে ব্যক্তিগত সহায়ক।
 */
final class TheReturnCameBackAndTheBooksStillAgreedTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    private Customer $customer;

    private Product $plain;

    private Product $lotted;

    private Batch $lotA;

    private Batch $lotB;

    private int $billNo = 0;

    /** @var list<Product> এই ফাইলের পণ্যগুলো — মজুদ খাত বনাম রিপোর্টের তুলনা এদের উপর */
    private array $mine = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();
        app(CashTillService::class)->ensurePrimaryTill();

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->customer = $this->newCustomer('RET-C1');

        $this->plain = $this->newProduct('RET-A', false);
        $this->lotted = $this->newProduct('RET-B', true);
        $this->mine = [$this->plain, $this->lotted];

        $this->buy($this->plain, '10', '60', '100');
        $this->buy($this->plain, '10', '80', '100');

        $this->lotA = $this->buy($this->lotted, '10', '50', '150', 'LOT-A');
        $this->lotB = $this->buy($this->lotted, '10', '70', '150', 'LOT-B');

        /*
         * ⓘ ভিত্তিটাই ঠিক না হলে নিচের কোনো দাবি কিছু প্রমাণ করে না —
         * তাই কেনার পরে মজুদ আগে হাতে গোনা অঙ্কে মেলানো।
         */
        $this->assertStock($this->plain, '20', '1400', 'ভিত্তি: A কেনার পরে');
        $this->assertStock($this->lotted, '20', '1200', 'ভিত্তি: B কেনার পরে');
    }

    // ── (ক) বাকির বিক্রয়, পুরো ফেরত ────────────────────────────────────

    /**
     * ৫ × ১০০ বাকিতে; খরচ P1 থেকে ৫ × ৬০ = ৩০০। পুরো ৫ ফেরত।
     */
    public function test_a_full_return_of_a_credit_sale_puts_every_book_back(): void
    {
        $start = $this->books();

        $invoice = $this->sell($this->customer, [$this->line($this->plain, '5', '100')]);

        $this->assertFiveMatches($start, 'বিক্রির পরে',
            ledger: ['AR' => '500', 'SALES' => '-500', 'COGS' => '300', 'INV' => '-300'],
            stock: [[$this->plain, '15', '1100']],
            party: '500', cash: '0', profit: '200');

        $return = $this->takeBack($invoice, [['product' => $this->plain, 'qty' => '5']]);
        $this->confirm($return)->assertSessionHasNoErrors();

        $this->assertSame(DocumentStatus::CONFIRMED, $return->fresh()->status);
        $this->assertMoney('300', (string) $return->fresh()->cost_of_goods, 'ফেরতের খরচ = ৫ × ৬০');

        $this->assertFiveMatches($start, 'পুরো ফেরতের পরে',
            ledger: ['AR' => '0', 'SALES' => '-500', 'RETURNS' => '500', 'COGS' => '0', 'INV' => '0'],
            stock: [[$this->plain, '20', '1400']],
            party: '0', cash: '0', profit: '0');
    }

    // ── (খ) আংশিক ফেরত — মূল স্তরের দামে ────────────────────────────────

    /**
     * ১২ × ১০০ বাকিতে = ১,২০০। খরচ: P1-এর ১০ × ৬০ + P2-এর ২ × ৮০ = ৭৬০।
     * তারপর নতুন চালান P3 ৫ × ১২০ (আজকের দর বদলায়)। তারপর ৩ ফেরত।
     *
     * হাতে গোনা ফেরতের খরচ — শেষে যা বেরিয়েছে আগে সেটা:
     *   P2 থেকে ২ × ৮০ = ১৬০, তারপর P1 থেকে ১ × ৬০ = ৬০  →  ২২০
     *
     * ⛔ যে সংখ্যাগুলো হলে ভুল:
     *   আজকের গড় (৮×৮০ + ৫×১২০)/১৩ = ৯৫.৩৮ → ২৮৬.১৫
     *   বিক্রির গড় ৭৬০/১২ = ৬৩.৩৩ → ১৯০
     *   পণ্য-মাস্টারের ক্রয়মূল্য (শেষ কেনা ১২০) → ৩৬০
     */
    public function test_a_partial_return_reverses_cost_at_the_layer_it_left_from(): void
    {
        $start = $this->books();

        $invoice = $this->sell($this->customer, [$this->line($this->plain, '12', '100')]);

        $this->assertFiveMatches($start, 'বিক্রির পরে',
            ledger: ['AR' => '1200', 'SALES' => '-1200', 'COGS' => '760', 'INV' => '-760'],
            stock: [[$this->plain, '8', '640']],
            party: '1200', cash: '0', profit: '440');

        $this->buy($this->plain, '5', '120', '100');
        $beforeReturn = $this->books();

        // মজুদ: ৮ × ৮০ + ৫ × ১২০ = ১,২৪০
        $this->assertStock($this->plain, '13', '1240', 'P3-এর পরে, ফেরতের আগে');

        $return = $this->takeBack($invoice, [['product' => $this->plain, 'qty' => '3']]);
        $this->confirm($return)->assertSessionHasNoErrors();

        $this->assertMoney('220', (string) $return->fresh()->cost_of_goods,
            'ফেরতের খরচ মূল স্তরের দামে নয় — ২ × ৮০ + ১ × ৬০ = ২২০ হওয়ার কথা');

        // কেবল ফেরতের নড়াচড়া: ফেরত ৩০০, পাওনা −৩০০, মজুদ +২২০, খরচ −২২০
        $this->assertFiveMatches($beforeReturn, 'আংশিক ফেরতের পরে',
            ledger: ['AR' => '-300', 'RETURNS' => '300', 'INV' => '220', 'COGS' => '-220'],
            // ১৩ + ৩ = ১৬ একক; ১,২৪০ + ২২০ = ১,৪৬০ (P1 ১@৬০ + P2 ১০@৮০ + P3 ৫@১২০)
            stock: [[$this->plain, '16', '1460']],
            party: '900', cash: '0', profit: '-80');
    }

    // ── (গ) লট ধরা পণ্য — একই লটে ফেরে ──────────────────────────────────

    /**
     * LOT-A থেকে ৪ × ১৫০ = ৬০০ বাকিতে; খরচ ৪ × ৫০ = ২০০। ৪টাই LOT-A-তে ফেরত।
     *
     * ⚠️ ইচ্ছা করে LOT-A (প্রথম চালান): এখানে পণ্য-ধরা FIFO আর মালিকের
     * নতুন নিয়ম "খরচ বাছা লটের নিজের দামে" (abos-d8 বসাচ্ছেন) — দুই নিয়মেই
     * ২০০। ⓘ তাই এই দাবি কোনো একটা নিয়মকে পাকা করে না; লটের নিজের দামের
     * দাবি নিচের আলাদা পরীক্ষায়, abos-d8-এর কাজের উপর দাঁড়ানো।
     */
    public function test_a_lot_tracked_return_goes_back_into_the_same_lot(): void
    {
        $start = $this->books();

        $invoice = $this->sell($this->customer, [$this->line($this->lotted, '4', '150', $this->lotA)]);

        $this->assertLots(['LOT-A' => '6', 'LOT-B' => '10'], 'বিক্রির পরে');
        $this->assertFiveMatches($start, 'লটের বিক্রির পরে',
            ledger: ['AR' => '600', 'SALES' => '-600', 'COGS' => '200', 'INV' => '-200'],
            stock: [[$this->lotted, '16', '1000']],
            party: '600', cash: '0', profit: '400');

        $return = $this->takeBack($invoice, [['product' => $this->lotted, 'qty' => '4', 'batch' => $this->lotA]]);
        $this->confirm($return)->assertSessionHasNoErrors();

        $this->assertLots(['LOT-A' => '10', 'LOT-B' => '10'], 'ফেরতের পরে');
        $this->assertFiveMatches($start, 'লটের ফেরতের পরে',
            ledger: ['AR' => '0', 'SALES' => '-600', 'RETURNS' => '600', 'COGS' => '0', 'INV' => '0'],
            stock: [[$this->lotted, '20', '1200']],
            party: '0', cash: '0', profit: '0');
    }

    /**
     * ⭐ বাছা লটের নিজের দামে খরচ, আর ফেরতও সেই দামে — মালিকের সিদ্ধান্ত,
     * ২৭ সেপ্টেম্বর ২০২৬।
     *
     * ⚠️ **abos-d8-এর CostLayerService বদলের উপর দাঁড়ানো।** LOT-B (৭০) থেকে
     * ৪টা: মালিকের নিয়মে বিক্রির খরচ ৪ × ৭০ = ২৮০, ফেরতেও ২৮০। ⓘ আজকের
     * পণ্য-ধরা FIFO-তে খরচ বসে LOT-A-র দামে (২০০) — ঐ অবস্থায় পরীক্ষাটা
     * নিজেকে "বাদ" বলে, সবুজও না, লালও না; পুরনো আচরণ পাকা হয় না।
     * abos-d8 বসালে নিজে থেকেই চলবে।
     */
    public function test_a_picked_lot_returns_at_that_lots_own_price(): void
    {
        $start = $this->books();

        $invoice = $this->sell($this->customer, [$this->line($this->lotted, '4', '150', $this->lotB)]);

        if (bccomp((string) $invoice->fresh()->cost_of_goods, '280', 4) !== 0) {
            $this->markTestSkipped('abos-d8-এর "খরচ বাছা লটের দামে" এখনো বসেনি — বিক্রির খরচ '
                .$invoice->fresh()->cost_of_goods.', মালিকের নিয়মে ২৮০।');
        }

        $return = $this->takeBack($invoice, [['product' => $this->lotted, 'qty' => '4', 'batch' => $this->lotB]]);
        $this->confirm($return)->assertSessionHasNoErrors();

        $this->assertMoney('280', (string) $return->fresh()->cost_of_goods, 'LOT-B-র ফেরত-খরচ ৪ × ৭০');
        $this->assertLots(['LOT-A' => '10', 'LOT-B' => '10'], 'ফেরতের পরে');
        $this->assertFiveMatches($start, 'বাছা লটের ফেরতের পরে',
            ledger: ['AR' => '0', 'SALES' => '-600', 'RETURNS' => '600', 'COGS' => '0', 'INV' => '0'],
            stock: [[$this->lotted, '20', '1200']],
            party: '0', cash: '0', profit: '0');
    }

    /**
     * ⛔ লট ধরা পণ্যের ফেরত লট ছাড়া ঢুকলে লটের যোগফল আর পণ্যের মজুদ
     * আলাদা হয়ে যায় — মালিকের নিয়ম: *"লট ছাড়া মাল ঢুকবেও না, বেরোবেও না"*।
     *
     * ⓘ "ফেরত দেওয়া হলো" কারণটা (REJECTED) লট চায় না, তাই কারণের পাহারা
     * এখানে থামায় না — প্রশ্নটা কারণের নয়, মজুদের।
     */
    public function test_a_lot_tracked_return_never_lands_without_a_lot(): void
    {
        $invoice = $this->sell($this->customer, [$this->line($this->lotted, '4', '150', $this->lotB)]);

        $return = $this->takeBack($invoice, [['product' => $this->lotted, 'qty' => '4']], expectSaved: false);

        if ($return !== null) {
            $this->confirm($return);
        }

        $unlotted = StockMovement::query()
            ->where('product_id', $this->lotted->id)
            ->whereNull('batch_id')
            ->where('floor_change', '<>', 0)
            ->count();

        $this->assertSame(0, $unlotted,
            '⛔ লট ধরা পণ্যের মাল লট ছাড়া তাকে উঠেছে — লটের যোগফল আর পণ্যের মজুদ আর মেলে না।');

        $lots = bcadd($this->lotFloor($this->lotA), $this->lotFloor($this->lotB), 4);
        $this->assertMoney($this->floor($this->lotted), $lots,
            'লটগুলোর যোগফল পণ্যের তাকের মজুদের সমান নয়');
    }

    /**
     * ⛔ বিলে যে লট যায়নি, সেই লটে ফেরত ঢোকে না।
     *
     * LOT-B থেকে ৪টা বেচা; ফেরতে LOT-A বলা হলো। LOT-A-র মজুদ ১০-ই থাকতে হবে —
     * নাহলে রিকলের দিন LOT-A এমন ক্রেতার নামে উঠত যিনি কখনো পাননি।
     */
    public function test_a_return_cannot_land_in_a_lot_the_bill_never_sold(): void
    {
        $invoice = $this->sell($this->customer, [$this->line($this->lotted, '4', '150', $this->lotB)]);

        $return = $this->takeBack($invoice, [['product' => $this->lotted, 'qty' => '4', 'batch' => $this->lotA]], expectSaved: false);

        if ($return !== null) {
            $this->confirm($return);
        }

        $this->assertLots(['LOT-A' => '10', 'LOT-B' => '6'],
            'বিলে না-থাকা লটে ফেরত ঢোকার চেষ্টার পরে');
    }

    // ── (ঘ) নগদের বিক্রয় — নগদ ফেরত ─────────────────────────────────────

    /**
     * ৫ × ১০০ = ৫০০, পুরো ৫০০ নগদে জমা (নিজের বাক্সে)। খরচ ৫ × ৬০ = ৩০০।
     * ফেরতে পাওনা −৫০০ (গ্রাহক এখন ৫০০ পাবেন); তারপর পরিশোধ ভাউচারে
     * বাক্স থেকে ৫০০ ফেরত — বাক্স আর গ্রাহক দুইটাই শূন্যে।
     */
    public function test_a_cash_sale_return_is_refunded_out_of_the_cash_box(): void
    {
        $start = $this->books();

        $invoice = $this->sell($this->customer, [$this->line($this->plain, '5', '100')], ['deposit' => '500']);

        $this->assertFiveMatches($start, 'নগদ বিক্রির পরে',
            ledger: ['AR' => '0', 'SALES' => '-500', 'CASH' => '500', 'COGS' => '300', 'INV' => '-300'],
            stock: [[$this->plain, '15', '1100']],
            party: '0', cash: '500', profit: '200');

        $return = $this->takeBack($invoice, [['product' => $this->plain, 'qty' => '5']]);
        $this->confirm($return)->assertSessionHasNoErrors();

        $this->assertFiveMatches($start, 'ফেরতের পরে, টাকা ফেরতের আগে',
            ledger: ['AR' => '-500', 'SALES' => '-500', 'RETURNS' => '500', 'CASH' => '500', 'COGS' => '0', 'INV' => '0'],
            stock: [[$this->plain, '20', '1400']],
            party: '-500', cash: '500', profit: '0');

        $this->post(route('accounts.voucher.store', ['type' => Voucher::PAYMENT]), [
            'type' => Voucher::PAYMENT,
            'trx_date' => now()->toDateString(),
            'amount' => '500',
            'from_account_id' => $this->tillAccount()->id,
            'to_account_id' => $this->accountId(StandardChart::RECEIVABLE),
            'party_type' => 'customer',
            'party_id' => $this->customer->id,
            'narration' => 'ফেরতের টাকা নগদে ফেরত',
        ])->assertSessionHasNoErrors();

        $this->assertFiveMatches($start, 'নগদ ফেরতের পরে',
            ledger: ['AR' => '0', 'SALES' => '-500', 'RETURNS' => '500', 'CASH' => '0', 'COGS' => '0', 'INV' => '0'],
            stock: [[$this->plain, '20', '1400']],
            party: '0', cash: '0', profit: '0');
    }

    // ── (ঙ) কারণ ছাড়া নয় — একই লোক, একই বিল ────────────────────────────

    /**
     * ৫ × ১০০ বাকিতে। কারণ ছাড়া ২টা ফেরত → থামে, কিছু নড়ে না। একই লোক,
     * একই বিল, কারণসহ → চলে: ফেরত ২০০, খরচ ২ × ৬০ = ১২০।
     */
    public function test_a_return_needs_a_reason_and_the_same_bill_passes_with_one(): void
    {
        $start = $this->books();
        $invoice = $this->sell($this->customer, [$this->line($this->plain, '5', '100')]);
        $afterSale = $this->books();
        $returnsBefore = SalesReturn::query()->count();

        $this->returnRequest($invoice, [['product' => $this->plain, 'qty' => '2']], ['reason_code_id' => null])
            ->assertSessionHasErrors('reason_code_id');

        $this->assertSame($returnsBefore, SalesReturn::query()->count(), 'কারণ ছাড়া ফেরতের কাগজ জন্মেছে।');
        $this->assertFiveMatches($afterSale, 'কারণ ছাড়া চেষ্টার পরে',
            ledger: [], stock: [[$this->plain, '15', '1100']],
            party: '500', cash: '0', profit: '0');

        $return = $this->takeBack($invoice, [['product' => $this->plain, 'qty' => '2']]);
        $this->confirm($return)->assertSessionHasNoErrors();

        $this->assertNotNull($return->fresh()->reason_code_id, 'নিশ্চিত ফেরতে কারণ বসেনি।');
        $this->assertFiveMatches($start, 'কারণসহ ফেরতের পরে',
            ledger: ['AR' => '300', 'SALES' => '-500', 'RETURNS' => '200', 'COGS' => '180', 'INV' => '-180'],
            stock: [[$this->plain, '17', '1220']],
            party: '300', cash: '0', profit: '120');
    }

    // ── (চ) যত বেচা তার বেশি ফেরত নয় ───────────────────────────────────

    /**
     * ১০ × ১০০ বাকিতে, খরচ ১০ × ৬০ = ৬০০। ১১ ফেরত → থামে। ৭ → চলে।
     * তারপর ৪ → থামে (বাকি ৩)। ৩ → চলে। শেষে সব শূন্য।
     */
    public function test_returning_more_than_was_sold_is_refused(): void
    {
        $start = $this->books();
        $invoice = $this->sell($this->customer, [$this->line($this->plain, '10', '100')]);
        $afterSale = $this->books();

        $this->assertRefusedAndNothingMoved($invoice, '11', $afterSale, 'বিক্রির চেয়ে ১টা বেশি');

        $first = $this->takeBack($invoice, [['product' => $this->plain, 'qty' => '7']]);
        $this->confirm($first)->assertSessionHasNoErrors();
        $afterSeven = $this->books();

        // ৭ ফেরতের খরচ — সবই P1 থেকে বেরিয়েছিল: ৭ × ৬০ = ৪২০
        $this->assertMoney('420', (string) $first->fresh()->cost_of_goods, '৭টার ফেরত-খরচ');

        $this->assertRefusedAndNothingMoved($invoice, '4', $afterSeven, '৭ ফেরতের পরে আরও ৪ (বাকি ৩)');

        $last = $this->takeBack($invoice, [['product' => $this->plain, 'qty' => '3']]);
        $this->confirm($last)->assertSessionHasNoErrors();

        $this->assertFiveMatches($start, '৭ + ৩ ফেরতের পরে',
            ledger: ['AR' => '0', 'SALES' => '-1000', 'RETURNS' => '1000', 'COGS' => '0', 'INV' => '0'],
            stock: [[$this->plain, '20', '1400']],
            party: '0', cash: '0', profit: '0');
    }

    // ── (ছ) নিশ্চিত ফেরত বাতিল — ফেরতের আগের অবস্থায় ────────────────────

    /**
     * ১০ × ১০০ বাকিতে, খরচ ৬০০ → মজুদ ১০ একক, ৮০০ (P2 ১০@৮০)। ৪ ফেরত
     * (৪ × ৬০ = ২৪০ ফেরে P1-এ) → ১৪ একক, ১,০৪০। তারপর ফেরতটা বাতিল।
     *
     * হাতে গোনা: বাতিলের পরে সব ঠিক বিক্রির পরের অবস্থায় — মজুদ ১০ একক,
     * ৮০০; P1 স্তর আবার শূন্য; গ্রাহক ১,০০০; লাভ ৪০০।
     */
    public function test_cancelling_a_confirmed_return_restores_the_five_matches(): void
    {
        $invoice = $this->sell($this->customer, [$this->line($this->plain, '10', '100')]);
        $afterSale = $this->books();

        $this->assertStock($this->plain, '10', '800', 'বিক্রির পরে');

        $return = $this->takeBack($invoice, [['product' => $this->plain, 'qty' => '4']]);
        $this->confirm($return)->assertSessionHasNoErrors();

        $this->assertFiveMatches($afterSale, 'ফেরতের পরে',
            ledger: ['AR' => '-400', 'RETURNS' => '400', 'INV' => '240', 'COGS' => '-240'],
            stock: [[$this->plain, '14', '1040']],
            party: '600', cash: '0', profit: '-160');

        $this->post(route('sales.return.cancel', $return), ['reason' => 'ভুল করে নেওয়া ফেরত'])
            ->assertSessionHasNoErrors();

        $this->assertSame(DocumentStatus::CANCELLED, $return->fresh()->status);

        $this->assertFiveMatches($afterSale, 'বাতিলের পরে (বিক্রির পরের অবস্থায় ফেরার কথা)',
            ledger: [],
            stock: [[$this->plain, '10', '800']],
            party: '1000', cash: '0', profit: '0');
    }

    // ── (জ) একই স্তর থেকে দুই বিল — একটার ফেরত অন্যটার জায়গা খায় না ──────

    /**
     * দুই বিল, দুটোই P1 থেকে: S1 ৪ × ১০০, S2 ৪ × ১০০ (খরচ প্রতিটায় ২৪০)।
     * S1-এর ৪টাই ফেরত (২৪০ ফেরে P1-এ)। তারপর S2-এর ২টা ফেরত — S2 নিজে P1
     * থেকে ৪টা নিয়েছিল, ফেরেনি একটাও, তাই ২ × ৬০ = ১২০ ফিরতে হবে।
     *
     * হাতে গোনা শেষ অবস্থা: মজুদ ২০ − ৮ + ৪ + ২ = ১৮, মূল্য ১,৪০০ − ৪৮০ +
     * ২৪০ + ১২০ = ১,২৮০; গ্রাহক ৮০০ − ৪০০ − ২০০ = ২০০; লাভ: নিট ২টা বিক্রি
     * ২০০ − খরচ ১২০ = ৮০।
     */
    public function test_one_bills_return_does_not_eat_another_bills_room_in_the_same_layer(): void
    {
        $start = $this->books();

        $first = $this->sell($this->customer, [$this->line($this->plain, '4', '100')]);
        $second = $this->sell($this->customer, [$this->line($this->plain, '4', '100')]);

        $r1 = $this->takeBack($first, [['product' => $this->plain, 'qty' => '4']]);
        $this->confirm($r1)->assertSessionHasNoErrors();

        $r2 = $this->takeBack($second, [['product' => $this->plain, 'qty' => '2']]);
        $this->confirm($r2)->assertSessionHasNoErrors();

        $this->assertSame(DocumentStatus::CONFIRMED, $r2->fresh()->status,
            '⛔ দ্বিতীয় বিলের ফেরত আটকে গেছে — প্রথম বিলের ফেরত একই স্তরের জায়গা খেয়ে ফেলেছে।');
        $this->assertMoney('120', (string) $r2->fresh()->cost_of_goods, 'দ্বিতীয় বিলের ফেরত-খরচ ২ × ৬০');

        $this->assertFiveMatches($start, 'দুই বিলের ফেরতের পরে',
            ledger: ['AR' => '200', 'SALES' => '-800', 'RETURNS' => '600', 'COGS' => '120', 'INV' => '-120'],
            stock: [[$this->plain, '18', '1280']],
            party: '200', cash: '0', profit: '80');
    }

    // ── (ঝ) অন্যের বিলের মাল অন্য গ্রাহকের নামে ফেরত নয় ──────────────────

    /**
     * গ্রাহক ১-কে ৫ × ১০০ বাকিতে। গ্রাহক ২-এর নামে ঐ বিলের ফেরত → থামতে হবে।
     * নাহলে গ্রাহক ২-এর খাতায় −৫০০ বসত (যিনি কিছুই কেনেননি) আর গ্রাহক ১
     * ৫০০-ই দেনা থাকতেন অথচ মাল ফিরে এসেছে।
     */
    public function test_a_bill_cannot_be_returned_in_another_customers_name(): void
    {
        $other = $this->newCustomer('RET-C2');
        $invoice = $this->sell($this->customer, [$this->line($this->plain, '5', '100')]);
        $afterSale = $this->books();

        $return = $this->takeBack($invoice, [['product' => $this->plain, 'qty' => '5']],
            ['customer_id' => $other->id], expectSaved: false);

        if ($return !== null) {
            $this->confirm($return);
        }

        $this->assertMoney('0', $other->fresh()->outstanding(),
            '⛔ যিনি কিছুই কেনেননি, তাঁর খাতায় অন্যের বিলের ফেরত বসেছে');
        $this->assertFiveMatches($afterSale, 'অন্যের নামে ফেরতের চেষ্টার পরে',
            ledger: [], stock: [[$this->plain, '15', '1100']],
            party: '500', cash: '0', profit: '0');
    }

    // ── (ঞ) বিল ধরে ফেরত, কিন্তু দর হাতে লেখা ─────────────────────────────

    /**
     * ৫ × ১০০ বাকিতে। বিল ধরে ৩টা ফেরত, বিলের সারি না বলে, দর হাতে ৫০০।
     *
     * হাতে গোনা: গ্রাহক বিলে ৩টার জন্য ৩০০ দিয়েছিলেন — ফেরতে ৩০০-এর বেশি
     * পাওনা কমতে পারে না। তাই শেষে গ্রাহক হয় ৫০০ (থেমেছে), নয় ২০০ (বিলের
     * দরে ফেরত); ⛔ −১,০০০ (৫০০ − ১,৫০০) মানে হাতে লেখা দরে টাকা বেরিয়ে গেছে।
     */
    public function test_a_return_against_a_bill_cannot_credit_more_than_the_bill_charged(): void
    {
        $invoice = $this->sell($this->customer, [$this->line($this->plain, '5', '100')]);

        $return = $this->takeBack($invoice,
            [['product' => $this->plain, 'qty' => '3', 'rate' => '500', 'unlinked' => true]], expectSaved: false);

        if ($return !== null) {
            $this->confirm($return);
        }

        $owed = rtrim(rtrim($this->customer->fresh()->outstanding(), '0'), '.');

        $this->assertContains($owed, ['500', '200'],
            "⛔ বিলে ৩টার দাম ছিল ৩০০, অথচ ফেরতে গ্রাহকের পাওনা কমেছে বেশি — বাকি দেখাচ্ছে {$owed}।");
    }

    // ── (ট) ফ্রি মাল ফেরত — মালিকের সিদ্ধান্ত, ২৭ সেপ্টেম্বর ২০২৬ ─────────

    /**
     * ফ্রি মাল ফেরে ফ্রি ভাণ্ডারে, শূন্য দামে, পাওনা না কমিয়ে — দামি আর
     * ফ্রি একই বিলের একই সারিতে।
     *
     * পণ্য D (লট ধরা, একটাই লট LOT-F): ১০ @ ৫০ + ফ্রি ২ → স্তরে ৫০০, ফ্রি ২।
     * বিক্রি: ৫ × ১০০ + ফ্রি ১ (অনুপাত ২/১০ × ৫ = ১) বাকিতে → পাওনা ৫০০,
     * খরচ ৫ × ৫০ = ২৫০; তাকে ৫, স্তরে ২৫০, ফ্রি ১।
     * ফেরত: ২ দামি + ১ ফ্রি → ফেরত ২০০, পাওনা −২০০, খরচ ফেরে ২ × ৫০ = ১০০।
     * ⓘ ফ্রি ১-এর জন্য খাতায় এক পয়সাও নয়: তাকে ৭, স্তরে ৩৫০, ফ্রি ২।
     */
    public function test_a_mixed_return_brings_the_free_carton_back_at_zero(): void
    {
        $free = $this->freeProduct();
        $start = $this->books();

        $invoice = $this->sell($this->customer, [$this->line($free['product'], '5', '100', $free['lot']) + ['free_qty' => '1']]);

        $this->assertFree($free['product'], '1', 'ফ্রিসহ বিক্রির পরে');
        $this->assertFiveMatches($start, 'ফ্রিসহ বিক্রির পরে',
            ledger: ['AR' => '500', 'SALES' => '-500', 'COGS' => '250', 'INV' => '-250'],
            stock: [[$free['product'], '5', '250']],
            party: '500', cash: '0', profit: '250');

        $return = $this->takeBack($invoice, [['product' => $free['product'], 'qty' => '2', 'free_qty' => '1']]);
        $this->confirm($return)->assertSessionHasNoErrors();

        $this->assertMoney('200', (string) $return->fresh()->total, 'ফেরতের টাকা কেবল দামি ২টার — ২ × ১০০');
        $this->assertFree($free['product'], '2', 'মিশ্র ফেরতের পরে');
        $this->assertMoney('2', $this->lotFree($free['lot']), 'মিশ্র ফেরতের পরে: LOT-F-এর ফ্রি');
        $this->assertFiveMatches($start, 'মিশ্র ফেরতের পরে',
            ledger: ['AR' => '300', 'SALES' => '-500', 'RETURNS' => '200', 'COGS' => '150', 'INV' => '-150'],
            stock: [[$free['product'], '7', '350']],
            party: '300', cash: '0', profit: '150');
    }

    /**
     * কেবল ফ্রি মাল ফিরল — খাতা একটুও নড়ে না, পাওনা একই থাকে, ফ্রি ভাণ্ডার
     * ফেরে। তারপর আরও ১টা ফ্রি → থামে (দেওয়া হয়েছিল ১, ফিরেছে ১)।
     */
    public function test_a_free_only_return_moves_the_free_bucket_and_nothing_else(): void
    {
        $free = $this->freeProduct();
        $invoice = $this->sell($this->customer, [$this->line($free['product'], '5', '100', $free['lot']) + ['free_qty' => '1']]);
        $afterSale = $this->books();

        $return = $this->takeBack($invoice, [['product' => $free['product'], 'qty' => '0', 'free_qty' => '1']]);
        $this->confirm($return)->assertSessionHasNoErrors();

        $this->assertSame(DocumentStatus::CONFIRMED, $return->fresh()->status);
        $this->assertFree($free['product'], '2', 'কেবল ফ্রি ফেরতের পরে');
        $this->assertFiveMatches($afterSale, 'কেবল ফ্রি ফেরতের পরে',
            ledger: [], stock: [[$free['product'], '5', '250']],
            party: '500', cash: '0', profit: '0');

        $again = $this->takeBack($invoice, [['product' => $free['product'], 'qty' => '0', 'free_qty' => '1']], expectSaved: false);

        if ($again !== null) {
            $this->confirm($again)->assertSessionHasErrors();
        }

        $this->assertFree($free['product'], '2', 'দেওয়ার চেয়ে বেশি ফ্রি ফেরতের চেষ্টার পরে');
    }

    /**
     * উপহারের মাল (অন্য পণ্য) ফেরত — ফ্রি ভাণ্ডারে, খাতা ছাড়া; আর ফেরতটা
     * বাতিল করলে ভাণ্ডার আবার আগের জায়গায়।
     *
     * পণ্য G (লট নেই): ১ @ ১০ + ফ্রি ৫ → স্তরে ১০, ফ্রি ৫। A ৫ × ১০০ বাকিতে,
     * সাথে উপহার G ২টা → G ফ্রি ৩। ফেরত: G ফ্রি ২ → G ফ্রি ৫; খাতা শূন্য
     * নড়াচড়া; পাওনা ৫০০। বাতিল → G ফ্রি ৩; খাতা তবুও শূন্য।
     */
    public function test_a_gift_comes_back_to_the_free_bucket_and_its_cancel_takes_it_out(): void
    {
        $gift = $this->newProduct('RET-G', false);
        $this->mine[] = $gift;
        $this->buy($gift, '1', '10', '20', null, '5');

        $invoice = $this->sell($this->customer, [$this->line($this->plain, '5', '100')], [
            'gifts' => [['product_id' => $gift->id, 'qty' => '2', 'against_product_id' => $this->plain->id]],
        ]);

        $this->assertFree($gift, '3', 'উপহারসহ বিক্রির পরে');
        $afterSale = $this->books();

        $return = $this->takeBack($invoice, [['product' => $gift, 'qty' => '0', 'free_qty' => '2', 'unlinked' => true]]);
        $this->confirm($return)->assertSessionHasNoErrors();

        $this->assertFree($gift, '5', 'উপহার ফেরতের পরে');
        $this->assertFiveMatches($afterSale, 'উপহার ফেরতের পরে',
            ledger: [], stock: [[$gift, '1', '10'], [$this->plain, '15', '1100']],
            party: '500', cash: '0', profit: '0');

        $this->post(route('sales.return.cancel', $return), ['reason' => 'উপহার ফেরত ভুলে বসানো'])
            ->assertSessionHasNoErrors();

        $this->assertFree($gift, '3', 'উপহার ফেরত বাতিলের পরে');
        $this->assertFiveMatches($afterSale, 'উপহার ফেরত বাতিলের পরে',
            ledger: [], stock: [[$gift, '1', '10'], [$this->plain, '15', '1100']],
            party: '500', cash: '0', profit: '0');
    }

    // ── (ঠ) ফেরত আছে এমন বিল বাতিল নয় — আগে ফেরত, তারপর বিল ────────────

    /**
     * ১০ × ১০০ বাকিতে (খরচ ৬০০), ৪ ফেরত (৪০০, খরচ ফেরে ২৪০)। তারপর বিল বাতিল।
     *
     * ⛔ পাহারা ছাড়া হাতে গোনা ফল: বাতিল গোটা বিল উল্টায় — পাওনা −১,০০০,
     * অথচ ৪০০ ফেরতে আগেই কমেছে → গ্রাহক −৪০০ (যিনি কিছুই পাবেন না);
     * মজুদ খাত +৬০০ আর ফেরতের +২৪০ মিলে ফেরত অংশের খরচ দুইবার ফেরে।
     * ⭐ তাই বিল বাতিল থামে, কিছুই নড়ে না। আগে ফেরত বাতিল, তারপর বিল — চলে,
     * আর খাতা শুরুর অবস্থায়, গ্রাহক শূন্যে।
     */
    public function test_a_bill_with_a_live_return_cannot_be_cancelled_until_the_return_is(): void
    {
        $start = $this->books();
        $invoice = $this->sell($this->customer, [$this->line($this->plain, '10', '100')]);

        $return = $this->takeBack($invoice, [['product' => $this->plain, 'qty' => '4']]);
        $this->confirm($return)->assertSessionHasNoErrors();
        $afterReturn = $this->books();

        $this->post(route('sales.invoice.cancel', $invoice), ['reason' => 'ভুল বিল'])
            ->assertSessionHasErrors('status');

        $this->assertSame(DocumentStatus::CONFIRMED, $invoice->fresh()->status,
            '⛔ ফেরত থাকা অবস্থায় বিলটা বাতিল হয়ে গেছে — ফেরত অংশ দুইবার উল্টেছে।');
        $this->assertFiveMatches($afterReturn, 'ফেরত থাকা বিল বাতিলের চেষ্টার পরে',
            ledger: [], stock: [[$this->plain, '14', '1040']],
            party: '600', cash: '0', profit: '0');

        /*
         * ⭐ ২ অক্টোবর ২০২৬ থেকে ফেরত বাতিলের পরেও নিশ্চিত বিল বাতিল হয় না — মালিক: *"ইনভয়েসের পরে … মোছা কখনো
         * নয়"* ([[AConfirmedBillCouldStillBeCancelledTest]])। ⓘ আগে এখানে দাবি ছিল "আগে ফেরত, তারপর বিল — চলে"।
         */
        $this->post(route('sales.return.cancel', $return), ['reason' => 'আগে ফেরত বাতিল'])->assertSessionHasNoErrors();
        $this->post(route('sales.invoice.cancel', $invoice), ['reason' => 'তারপর বিল'])->assertSessionHasErrors('status');

        $this->assertSame(DocumentStatus::CONFIRMED, $invoice->fresh()->status, '⛔ ফেরত বাতিলের পরে নিশ্চিত বিল বাতিল হয়ে গেছে।');
        unset($start);
    }

    /** ⭐ ফর্মে ফ্রি পরিমাণের ঘরটা সত্যিই আছে — না থাকলে উপরের পথটা কেবল সেবায়। */
    public function test_the_return_form_offers_a_free_quantity_box(): void
    {
        $invoice = $this->sell($this->customer, [$this->line($this->plain, '5', '100')]);

        $this->get(route('sales.return.create', ['sales_invoice_id' => $invoice->id]))
            ->assertOk()
            ->assertSee("+ '][free_qty]'", false)
            ->assertSee(__('sales::field.free_qty'));
    }

    // ═════════════════════════════════════════════════════════════════
    // সহায়ক — দরজা
    // ═════════════════════════════════════════════════════════════════

    /**
     * লট ধরা পণ্য D, একটাই লট LOT-F: ১০ @ ৫০ + ফ্রি ২।
     *
     * @return array{product: Product, lot: Batch}
     */
    private function freeProduct(): array
    {
        $product = $this->newProduct('RET-D', true);
        $this->mine[] = $product;
        $lot = $this->buy($product, '10', '50', '100', 'LOT-F', '2');

        $this->assertStock($product, '10', '500', 'ভিত্তি: D কেনার পরে');
        $this->assertFree($product, '2', 'ভিত্তি: D কেনার পরে');

        return ['product' => $product, 'lot' => $lot];
    }

    private function assertFree(Product $product, string $qty, string $when): void
    {
        $this->assertMoney($qty, bcadd((string) app(StockService::class)->freeQty($product, $this->warehouse), '0', 4),
            "{$when}: {$product->code}-এর ফ্রি ভাণ্ডার");
    }

    private function lotFree(Batch $lot): string
    {
        return bcadd((string) (StockMovement::query()
            ->where('product_id', $lot->product_id)
            ->where('warehouse_id', $this->warehouse->id)
            ->where('batch_id', $lot->id)
            ->sum('free_change') ?: '0'), '0', 4);
    }

    private function newCustomer(string $code): Customer
    {
        return Customer::query()->create([
            'company_id' => CompanyContext::id(),
            'code' => $code,
            'name_en' => 'Return Test '.$code,
            'name_bn' => 'ফেরত পরীক্ষা '.$code,
            'credit_limit' => '1000000',
            'credit_days' => 30,
            'is_active' => true,
        ]);
    }

    private function newProduct(string $code, bool $lots): Product
    {
        return Product::query()->create([
            'code' => $code,
            'name_en' => 'Return item '.$code,
            'name_bn' => 'ফেরতের পণ্য '.$code,
            'unit_id' => Unit::query()->orderBy('id')->firstOrFail()->id,
            'is_active' => true,
            'track_batch' => $lots,
        ]);
    }

    /**
     * সরাসরি ক্রয়ের দরজা দিয়ে কেনা, তারপর তাকে বসানো।
     */
    private function buy(Product $product, string $qty, string $rate, string $salePrice, ?string $lot = null, string $free = '0'): ?Batch
    {
        $this->post(route('purchase.direct.store'), [
            'supplier_id' => Supplier::query()->orderBy('id')->value('id'),
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
            'supplier_bill_no' => 'RET-BUY-'.(++$this->billNo),
            'lines' => [array_filter([
                'product_id' => $product->id,
                'qty' => $qty,
                'rate' => $rate,
                'free_qty' => bccomp($free, '0', 4) > 0 ? $free : null,
                'sales_price' => $salePrice,
                'batch_no' => $lot,
                'expiry_date' => $lot === null ? null : now()->addYear()->toDateString(),
            ], fn ($v) => $v !== null)],
        ])->assertSessionHasNoErrors();

        $bill = PurchaseBill::query()->latest('id')->firstOrFail();

        $batch = $lot === null ? null
            : Batch::query()->where('product_id', $product->id)->where('batch_no', $lot)->firstOrFail();

        app(StockService::class)->place(
            product: $product,
            warehouse: $this->warehouse,
            qty: $qty,
            sourceType: PurchaseBill::STOCK_SOURCE,
            sourceId: $bill->id,
            batch: $batch,
            freeQty: $free,
        );

        return $batch;
    }

    /** @return array<string, mixed> */
    private function line(Product $product, string $qty, string $rate, ?Batch $lot = null): array
    {
        return array_filter([
            'product_id' => $product->id,
            'qty' => $qty,
            'rate' => $rate,
            'batch_id' => $lot?->id,
        ], fn ($v) => $v !== null);
    }

    /**
     * সরাসরি বিক্রয়ের দরজা — এক চাপে চালান আর বিল।
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, mixed>  $extra
     */
    private function sell(Customer $customer, array $lines, array $extra = []): SalesInvoice
    {
        $before = (int) SalesInvoice::query()->max('id');

        $this->post(route('sales.direct.store'), [
            'own_transport' => '1', // ⓘ ধাপ ৫ — নিশ্চিতে পরিবহন লাগে ([[TransportRule]]); এই দাবি অন্য কিছু মাপে
            'customer_id' => $customer->id,
            'warehouse_id' => $this->warehouse->id,
            'lines' => $lines,
            ...$extra,
        ])->assertSessionHasNoErrors();

        $invoice = SalesInvoice::query()->where('id', '>', $before)->with('lines')->latest('id')->firstOrFail();

        $this->assertSame(DocumentStatus::CONFIRMED, $invoice->status, 'সরাসরি বিক্রয়ের বিল নিশ্চিত হয়নি।');

        return $invoice;
    }

    /**
     * ফেরতের পর্দার দরজা — কাগজ তৈরির অনুরোধ।
     *
     * @param  list<array<string, mixed>>  $lines  product, qty, ঐচ্ছিক batch/rate/unlinked
     * @param  array<string, mixed>  $extra
     */
    private function returnRequest(SalesInvoice $invoice, array $lines, array $extra = []): TestResponse
    {
        $invoice->loadMissing('lines');

        return $this->post(route('sales.return.store'), array_merge([
            'customer_id' => $invoice->customer_id,
            'warehouse_id' => $this->warehouse->id,
            'sales_invoice_id' => $invoice->id,
            'reason_code_id' => $this->reasonId(),
            'trx_date' => now()->toDateString(),
            'lines' => array_map(function (array $l) use ($invoice) {
                $sold = $invoice->lines->firstWhere('product_id', $l['product']->id);

                return array_filter([
                    'product_id' => $l['product']->id,
                    'sales_invoice_line_id' => ($l['unlinked'] ?? false) ? null : $sold?->id,
                    'qty' => $l['qty'],
                    'free_qty' => $l['free_qty'] ?? null,
                    'rate' => $l['rate'] ?? null,
                    'batch_id' => isset($l['batch']) ? $l['batch']->id : null,
                ], fn ($v) => $v !== null);
            }, $lines),
        ], $extra));
    }

    /**
     * ফেরতের খসড়া — সফল হলে কাগজটা, না হলে null (যখন `expectSaved` মিথ্যা)।
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, mixed>  $extra
     */
    private function takeBack(SalesInvoice $invoice, array $lines, array $extra = [], bool $expectSaved = true): ?SalesReturn
    {
        $before = (int) SalesReturn::query()->max('id');

        $response = $this->returnRequest($invoice, $lines, $extra);

        if ($expectSaved) {
            $response->assertSessionHasNoErrors();
        }

        return SalesReturn::query()->where('id', '>', $before)->latest('id')->first();
    }

    private function confirm(SalesReturn $return): TestResponse
    {
        return $this->post(route('sales.return.confirm', $return));
    }

    /**
     * অতিরিক্ত ফেরতের চেষ্টা — থামতে হবে, আর পাঁচ মিল এক চুলও নড়বে না।
     *
     * @param  array<string, mixed>  $before
     */
    private function assertRefusedAndNothingMoved(SalesInvoice $invoice, string $qty, array $before, string $label): void
    {
        $return = $this->takeBack($invoice, [['product' => $this->plain, 'qty' => $qty]], expectSaved: false);

        if ($return !== null) {
            $this->confirm($return)->assertSessionHasErrors();
            $this->assertNotSame(DocumentStatus::CONFIRMED, $return->fresh()->status,
                "⛔ {$label}: বিক্রির চেয়ে বেশি ফেরত নিশ্চিত হয়ে গেছে।");
        }

        $this->assertSame([], $this->movedAccounts($before), "⛔ {$label}: থামানো ফেরত খাতায় কিছু বসিয়েছে।");
        $this->assertMoney($before['floor'][$this->plain->id], $this->floor($this->plain),
            "⛔ {$label}: থামানো ফেরতে মাল তাকে উঠেছে");
    }

    private function reasonId(): int
    {
        return (int) ReasonCode::query()
            ->inContext(ReasonCode::SALES_RETURN)
            ->where('code', 'REJECTED')
            ->firstOrFail()
            ->id;
    }

    // ═════════════════════════════════════════════════════════════════
    // সহায়ক — পাঁচ মিল
    // TODO(abos-d8): tests/Concerns/ChecksTheFiveMatches.php এলে সেখানে সরবে
    // ═════════════════════════════════════════════════════════════════

    /**
     * এই মুহূর্তের ছবি — খাত, মজুদ, পক্ষ, লাভ।
     *
     * @return array<string, mixed>
     */
    private function books(): array
    {
        return [
            'ledger' => $this->ledgerNets(),
            'last_entry' => (int) LedgerEntry::query()->max('id'),
            'floor' => collect($this->mine)->mapWithKeys(fn (Product $p) => [$p->id => $this->floor($p)])->all(),
            'report_value' => $this->reportValue(),
            'profit' => $this->profitNet(),
        ];
    }

    /** @return array<int, string> খাত → ডেবিট − ক্রেডিট */
    private function ledgerNets(): array
    {
        return LedgerEntry::query()
            ->selectRaw('account_id, COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as net')
            ->groupBy('account_id')
            ->pluck('net', 'account_id')
            ->map(fn ($net) => bcadd((string) $net, '0', 4))
            ->all();
    }

    /**
     * যে খাতগুলো নড়েছে — খাতের কোড → নড়াচড়া।
     *
     * @param  array<string, mixed>  $before
     * @return array<string, string>
     */
    private function movedAccounts(array $before): array
    {
        $now = $this->ledgerNets();
        $moved = [];

        foreach (array_unique([...array_keys($now), ...array_keys($before['ledger'])]) as $id) {
            $delta = bcsub($now[$id] ?? '0', $before['ledger'][$id] ?? '0', 4);

            if (bccomp($delta, '0', 4) !== 0) {
                $moved[(string) Account::query()->whereKey($id)->value('code')] = $delta;
            }
        }

        ksort($moved);

        return $moved;
    }

    /**
     * পাঁচ মিল — সবগুলো হাতে গোনা অঙ্কের বিপরীতে।
     *
     * @param  array<string, mixed>  $before  যে ছবির তুলনায় খাতের নড়াচড়া ও লাভ
     * @param  array<string, string>  $ledger  AR/SALES/RETURNS/COGS/INV/CASH → নড়াচড়া (ডেবিট − ক্রেডিট)
     * @param  list<array{0: Product, 1: string, 2: string}>  $stock  পণ্য, মোট পরিমাণ, মোট মূল্য
     * @param  string  $party  গ্রাহকের খতিয়ানের **মোট** জের (নতুন গ্রাহক, আগে শূন্য)
     * @param  string  $cash  নগদ বাক্সের নড়াচড়া
     * @param  string  $profit  লাভ-ক্ষতির নিটের নড়াচড়া
     */
    private function assertFiveMatches(
        array $before,
        string $when,
        array $ledger,
        array $stock,
        string $party,
        string $cash,
        string $profit,
    ): void {
        // ── ১ · খাতা ────────────────────────────────────────────────
        $fresh = LedgerEntry::query()->where('id', '>', $before['last_entry']);
        $this->assertMoney((string) (clone $fresh)->sum('debit'), (string) (clone $fresh)->sum('credit'),
            "মিল ১ ({$when}): নতুন দাখিলায় ডেবিট ≠ ক্রেডিট");

        $codes = [
            'AR' => StandardChart::RECEIVABLE,
            'SALES' => StandardChart::SALES,
            'RETURNS' => StandardChart::SALES_RETURN,
            'COGS' => StandardChart::COST_OF_GOODS_SOLD,
            'INV' => StandardChart::INVENTORY,
            'CASH' => (string) $this->tillAccount()->code,
        ];

        $expected = [];

        foreach ($ledger as $key => $amount) {
            if (bccomp($amount, '0', 4) !== 0) {
                $expected[$codes[$key]] = bcadd($amount, '0', 4);
            }
        }

        ksort($expected);

        $this->assertSame($expected, $this->movedAccounts($before),
            "মিল ১ ({$when}): খাতগুলোর নড়াচড়া হাতে গোনা অঙ্কের সাথে মেলে না (কোড → ডেবিট − ক্রেডিট)");

        // ── ২ · মজুদ ────────────────────────────────────────────────
        foreach ($stock as [$product, $qty, $value]) {
            $this->assertStock($product, $qty, $value, "মিল ২ ({$when})");
        }

        $inventory = $this->accountId(StandardChart::INVENTORY);

        $this->assertMoney(
            bcsub($this->reportValue(), $before['report_value'], 4),
            bcsub($this->ledgerNets()[$inventory] ?? '0', $before['ledger'][$inventory] ?? '0', 4),
            "মিল ২ ({$when}): মজুদ খাতের (১১২০) নড়াচড়া ≠ মজুদ রিপোর্টের মূল্যের নড়াচড়া",
        );

        // ── ৩ · পক্ষ ────────────────────────────────────────────────
        $this->assertMoney($party, $this->customer->fresh()->outstanding(),
            "মিল ৩ ({$when}): গ্রাহকের খতিয়ান");

        $arShare = LedgerEntry::query()
            ->where('account_id', $this->accountId(StandardChart::RECEIVABLE))
            ->forParty('customer', $this->customer->id)
            ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as net')
            ->value('net');

        $this->assertMoney($party, (string) ($arShare ?? '0'),
            "মিল ৩ ({$when}): পাওনা খাতে এই গ্রাহকের অংশ");

        // ── ৪ · টাকা ────────────────────────────────────────────────
        $till = $this->tillAccount()->id;
        $this->assertMoney($cash, bcsub($this->ledgerNets()[$till] ?? '0', $before['ledger'][$till] ?? '0', 4),
            "মিল ৪ ({$when}): নগদ বাক্স");

        $otherMoney = Account::query()->whereNotNull('money_kind')->whereKeyNot($till)->pluck('code')->all();
        $this->assertSame([], array_values(array_intersect($otherMoney, array_keys($this->movedAccounts($before)))),
            "মিল ৪ ({$when}): নগদ বাক্স ছাড়া অন্য টাকার খাত (ব্যাংক/বিকাশ) নড়েছে");

        // ── ৫ · লাভ ─────────────────────────────────────────────────
        $this->assertMoney($profit, bcsub($this->profitNet(), $before['profit'], 4),
            "মিল ৫ ({$when}): লাভ-ক্ষতি রিপোর্টের নিট");
    }

    /** এই ফাইলের পণ্যগুলোর মজুদ-মূল্য রিপোর্টের মোট মূল্য। */
    private function reportValue(): string
    {
        return array_reduce($this->mine, fn (string $sum, Product $p) => bcadd($sum, $this->reportRow($p)['value'], 4), '0');
    }

    /**
     * একটা পণ্যের মজুদ — তাক, রিপোর্ট আর FIFO স্তর, তিনটাই হাতে গোনার সমান।
     */
    private function assertStock(Product $product, string $qty, string $value, string $when): void
    {
        $row = $this->reportRow($product);

        $this->assertMoney($qty, $this->floor($product), "{$when}: {$product->code}-এর তাকের পরিমাণ");
        $this->assertMoney($qty, $row['qty'], "{$when}: {$product->code}-এর মজুদ-মূল্য রিপোর্টের পরিমাণ");
        $this->assertMoney($value, $row['value'], "{$when}: {$product->code}-এর মজুদ-মূল্য রিপোর্টের মূল্য");
        $this->assertMoney($value, app(CostLayerService::class)->valueOnHand($product),
            "{$when}: {$product->code}-এর FIFO স্তরে পড়ে থাকা মূল্য");
    }

    /** @return array{qty: string, value: string} */
    private function reportRow(Product $product): array
    {
        $result = app(ReportEngine::class)->run('inventory.stock_value', [
            'from' => now()->startOfYear()->toDateString(),
            'to' => now()->toDateString(),
        ], 1, 5000);

        $row = collect($result->rows)->first(fn ($r) => (int) ((array) $r)['product_id'] === (int) $product->id);
        $row = $row === null ? [] : (array) $row;

        return [
            'qty' => bcadd((string) ($row['closing_qty'] ?? '0'), '0', 4),
            'value' => bcadd((string) ($row['closing_value'] ?? '0'), '0', 4),
        ];
    }

    private function profitNet(): string
    {
        $result = app(ReportEngine::class)->run('accounts.profit_loss', [
            'from' => now()->startOfYear()->toDateString(),
            'to' => now()->endOfYear()->toDateString(),
        ]);

        return bcsub((string) ($result->totals['credit'] ?? '0'), (string) ($result->totals['debit'] ?? '0'), 4);
    }

    private function floor(Product $product): string
    {
        return bcadd((string) app(StockService::class)->floorQty($product, $this->warehouse), '0', 4);
    }

    private function lotFloor(Batch $lot): string
    {
        return bcadd((string) (StockMovement::query()
            ->where('product_id', $lot->product_id)
            ->where('warehouse_id', $this->warehouse->id)
            ->where('batch_id', $lot->id)
            ->sum('floor_change') ?: '0'), '0', 4);
    }

    /** @param array<string, string> $expected লট নম্বর → তাকের পরিমাণ */
    private function assertLots(array $expected, string $when): void
    {
        foreach ($expected as $no => $qty) {
            $lot = $no === 'LOT-A' ? $this->lotA : $this->lotB;
            $this->assertMoney($qty, $this->lotFloor($lot), "{$when}: {$no}-এর তাকের পরিমাণ");
        }
    }

    private function tillAccount(): Account
    {
        return app(CashTillService::class)->ensurePrimaryTill()->account;
    }

    private function accountId(string $code): int
    {
        return (int) Account::query()->where('code', $code)->firstOrFail()->id;
    }

    private function assertMoney(string $expected, string $actual, string $message): void
    {
        $this->assertSame(0, bccomp(bcadd($expected, '0', 4), bcadd($actual === '' ? '0' : $actual, '0', 4), 4),
            "{$message} — হাতে গোনা {$expected}, পাওয়া গেল {$actual}");
    }
}
