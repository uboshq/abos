<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\CostLayerService;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Services\PurchaseBillService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ট্রাকের ভাড়াটা কোনোদিন মালের দামে পৌঁছাত না।
 *
 * ── ⛔ ফাঁকটা কোডেই লেখা ছিল ─────────────────────────────────────────
 * [[PurchaseBillService::create()]]-এ গাড়ির ঘরগুলোর পাশে লেখা ছিল:
 * *"ভাড়াটা এখানে **কেবল রাখা হয়**, এখনো ক্রয়মূল্যে ঢোকে না। ওটা আলাদা
 * কাজ"*। ⓘ অর্থাৎ সংখ্যাটা কাগজে বসত, আর হিসাবের কোথাও যেত না।
 *
 * ⚠️ ফলে চট্টগ্রাম থেকে আসা মালের ক্রয়মূল্য ভাড়ার পরিমাণ **কম** দেখাত,
 * আর বেচার দিন মুনাফা ঠিক ততটাই **বেশি** — প্রতিটা বিক্রিতে, নীরবে।
 *
 * ── ⭐ সবচেয়ে বিপজ্জনক অংশটা হিসাবের দিক ─────────────────────────────
 * ⛔ ভাড়াটা কেবল মজুদের স্তরে বসালে গুদামের খাতায় মালের দাম বাড়ত আর
 * খতিয়ানের মজুদের ঘরে বাড়ত না। ⚠️ দুই খাতা আলাদা হয়ে যেত — আর ফারাকটা
 * ধরা পড়ত মাস শেষে, যখন কেউ আর মনে করতে পারেন না কোন বিলে।
 *
 * ── ⚠️ এই ফাইল যা পাহারা দেয় ────────────────────────────────────────
 *   ১. ভাড়াটা সত্যিই মালের দামে ঢোকে
 *   ২. ভাগটা **টাকার অনুপাতে**, পরিমাণের নয়
 *   ৩. ভাগগুলো যোগ করলে **হুবহু** ভাড়াটাই হয় — এক পয়সাও হারায় না
 *   ৪. খতিয়ানে ঠিক ততটাই মজুদে ডেবিট হয়, যতটা স্তরে বসেছে
 *   ৫. ক্রেডিটটা পরিবহনের নিজের ঘরে, সরবরাহকারীর প্রদেয়ে নয়
 *   ৬. ভাড়া থাকলেও বিলটা "মেলেনি" বলে দাগ খায় না
 *   ৭. ভাড়া শূন্য হলে খতিয়ানে কিছুই বসে না
 *
 * ⓘ (৩) আর (৪) একসাথেই আসল পাহারা: ওই দুইটা সবুজ থাকলে মজুদের খাতা আর
 * হিসাবের খাতার ফারাক গাণিতিকভাবেই অসম্ভব।
 */
final class TheLorryFareNeverReachedTheCostOfTheGoodsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Supplier $supplier;

    private Warehouse $warehouse;

    /** সস্তা পণ্য — ভাগের ছোট দিক। */
    private Product $cheap;

    /** দামি পণ্য — একই পরিমাণ, তবু ভাড়ার বড় অংশ এর ঘাড়ে। */
    private Product $dear;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        app(StandardChart::class)->install();

        /*
         * ⓘ সুইচ দুইটা স্পষ্ট করে বসানো হয় — ⚠️ ডেমোর সেটিং একদিন
         * বদলালে পরীক্ষাটা অন্য পথ মাপত, আর কেউ টের পেত না।
         *
         * ⛔ `receipt_needs_order` চালু থাকলে সরাসরি বিলটা `exception`
         * হয়ে যেত, আর "ভাড়া মিলকরণ নষ্ট করে না" পাহারাটা তখন অন্য
         * কারণে লাল হত — ভুল কারণে লাল হওয়া সবুজ হওয়ার চেয়েও খারাপ।
         */
        app(SettingsService::class)->set('purchase.receipt_needs_order', false);
        app(SettingsService::class)->set('purchase.block_price_mismatch', true);

        $this->supplier = Supplier::query()->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();

        [$this->cheap, $this->dear] = Product::query()->orderBy('id')->take(2)->get()->all();
    }

    // ── ১ ও ২ · ভাড়াটা দামে ঢোকে, টাকার অনুপাতে ──────────────────────

    public function test_the_fare_goes_into_the_cost_of_the_goods(): void
    {
        $before = $this->valueOnHand($this->cheap);

        $this->aBillWithFare('300');

        $after = $this->valueOnHand($this->cheap);

        /*
         * ⓘ সস্তা সারিটার মালমূল্য ১০০০, গোটা বিলের ৫০০০। তাই ৩০০ টাকা
         * ভাড়ার এক-পঞ্চমাংশ — ৬০ — এর ঘাড়ে।
         */
        $this->assertSame(0, bccomp(bcsub($after, $before, 4), '1060', 4),
            'ভাড়াটা মালের দামে ঢোকেনি — গুদামের মাল ঠিক ভাড়ার পরিমাণ সস্তা '
            .'দেখাচ্ছে, আর বেচার দিন মুনাফা ততটাই বেশি দেখাবে।');
    }

    public function test_the_dearer_goods_carry_the_bigger_share(): void
    {
        /*
         * ⛔ পরিমাণ ধরে ভাগ করলে দুইটাই ১৫০ করে পেত — অথচ পরিমাণ সমান,
         * দাম চারগুণ। ⚠️ তখন সস্তা মালের ক্রয়মূল্য লাফিয়ে উঠত (১০০ →
         * ১১৫), আর দামি মালেরটা প্রায় বদলাত না (৪০০ → ৪১৫)।
         *
         * ⓘ টাকার অনুপাতও নিখুঁত নয় — ন্যায্য ভাগ ওজন ধরে। কিন্তু ওজন
         * ABOS জানে না, আর যে সংখ্যাটা আছে তার উপর ভাগ করা না-করার
         * চেয়ে ভালো।
         */
        $before = $this->valueOnHand($this->dear);

        $this->aBillWithFare('300');

        $this->assertSame(0, bccomp(bcsub($this->valueOnHand($this->dear), $before, 4), '4240', 4),
            'দামি সারিটা ভাড়ার চার-পঞ্চমাংশ পায়নি — ভাগটা তাহলে টাকার '
            .'অনুপাতে হচ্ছে না।');
    }

    // ── ৩ · এক পয়সাও হারায় না ────────────────────────────────────────

    public function test_the_shares_add_back_up_to_exactly_the_fare(): void
    {
        /*
         * ⭐ ১০০ টাকা তিন ভাগ — ৩৩.৩৩৩৩ করে, যোগ করলে ৯৯.৯৯৯৯।
         *
         * ⛔ বাকি এক পয়সা কোথাও না বসালে মজুদের স্তরে ৯৯.৯৯৯৯ ঢুকত আর
         * খতিয়ানে ১০০ — ⚠️ প্রতিটা ভাড়াওয়ালা বিলে এক পয়সা করে, আর
         * বছরে হাজারটা বিলে দশ টাকা, যার উৎস কেউ কোনোদিন খুঁজে পেত না।
         */
        $products = Product::query()->orderBy('id')->take(3)->get();

        $before = $products->mapWithKeys(fn (Product $p) => [$p->id => $this->valueOnHand($p)]);

        $bill = app(PurchaseBillService::class)->create(
            $this->header('100'),
            $products->map(fn (Product $p) => [
                'product_id' => $p->id,
                'qty' => '1',
                'rate' => '10',
                'tax' => '0',
            ])->all(),
        );

        app(PurchaseBillService::class)->confirm($bill);

        $added = $products->reduce(
            fn (string $sum, Product $p) => bcadd($sum, bcsub($this->valueOnHand($p), $before[$p->id], 4), 4),
            '0',
        );

        $this->assertSame(0, bccomp($added, '130', 4),
            'তিনটা সারিতে বসা মোট দাম ১৩০ নয় — অর্থাৎ ১০০ টাকা ভাড়ার '
            .'কিছুটা ভাগ করতে গিয়ে হারিয়ে গেছে।');
    }

    // ── ৪ ও ৫ · খতিয়ানের দিক ─────────────────────────────────────────

    public function test_the_ledger_debits_inventory_by_exactly_what_went_into_the_layers(): void
    {
        /*
         * ⭐ এই একটা পাহারাই গোটা কাজটার কারণ।
         *
         * ⛔ মজুদে ডেবিট না বসালে গুদামের খাতায় মাল দামি হত আর হিসাবের
         * খাতায় হত না — আর ঠিক ওই ফারাকটাই এই কোডবেসের সবচেয়ে ভয়ংকর
         * রোগ, কারণ কোথাও লাল হয় না।
         */
        $bill = $this->aBillWithFare('300');

        $this->assertSame(0, bccomp($this->debitOn(StandardChart::INVENTORY, $bill), '5300', 4),
            'খতিয়ানে মজুদের ডেবিট ৫৩০০ নয় — অর্থাৎ মালের দামে যা ঢুকেছে '
            .'আর হিসাবে যা বসেছে, দুইটা আলাদা।');
    }

    public function test_the_fare_is_owed_to_the_carrier_not_to_the_supplier(): void
    {
        /*
         * ⓘ ভাড়ার টাকা গাড়িওয়ালার পাওনা, মিলের নয় — বিলের মোটেও ওটা নেই।
         * ⛔ সাধারণ প্রদেয়ে মিশিয়ে দিলে *"এই মাসে পরিবহনে কত বাকি"*
         * প্রশ্নের উত্তর আর বের করা যেত না, অথচ ডিপোতে ওটা রোজকার প্রশ্ন।
         */
        $bill = $this->aBillWithFare('300');

        $this->assertSame(0, bccomp($this->creditOn(StandardChart::TRANSPORT_PAYABLE, $bill), '300', 4),
            'ভাড়াটা পরিবহনের নিজের ঘরে (২১১৬) জমেনি।');

        $this->assertSame(0, bccomp($this->creditOn(StandardChart::PAYABLE, $bill), '5000', 4),
            'সরবরাহকারীর প্রদেয়ে ভাড়াটাও ঢুকে গেছে — অথচ মিল ভাড়ার টাকা '
            .'কোনোদিন দাবি করেনি।');
    }

    public function test_the_carrier_gets_the_row_in_their_own_name(): void
    {
        $bill = $this->aBillWithFare('300', carrier: $this->supplier->id);

        $row = $this->entriesOf($bill)
            ->firstWhere('account_id', $this->accountId(StandardChart::TRANSPORT_PAYABLE));

        $this->assertSame($this->supplier->id, (int) $row->party_id,
            'বাহক বাছা ছিল, তবু সারিটা পক্ষ ছাড়া বসেছে — তাহলে "করিম '
            .'ট্রান্সপোর্টকে এখন কত দিতে হবে" প্রশ্নের উত্তর নেই।');
    }

    // ── ৬ · ভাড়া থাকলেও বিলটা মেলে ───────────────────────────────────

    public function test_a_bill_with_a_fare_is_not_marked_as_a_mismatch(): void
    {
        /*
         * ⛔ ভাড়ার জোড়াটা `$difference` গোনার **আগে** বসালে ডেবিটের
         * যোগফল বেড়ে যেত, আর ভাড়ার সমান একটা "মূল্য-পার্থক্য" জন্মাত।
         * ⚠️ অর্থাৎ প্রতিটা ভাড়াওয়ালা বিল ব্যতিক্রমের তালিকায় উঠত, আর
         * যে তিনটা সত্যিই দেখার দরকার সেগুলো ভিড়ে হারাত।
         */
        $bill = $this->aBillWithFare('300');

        $this->assertSame(PurchaseBill::MATCH_MATCHED, $bill->fresh()->match_state,
            'ভাড়া বসানো বিলটা "মেলেনি" বলে দাগ খেয়েছে।');

        $this->assertSame(0, bccomp((string) $bill->fresh()->match_difference, '0', 4));
    }

    // ── ৭ · ভাড়া না থাকলে কিছুই বসে না ───────────────────────────────

    public function test_no_fare_writes_no_transport_row(): void
    {
        /*
         * ⚠️ উপরের পাহারাগুলো উল্টো দিকেও সবুজ থাকত: কোড যদি ভাড়ার ঘর
         * খালি থাকলেও একটা শূন্য সারি বসাত, খতিয়ান ভরে যেত অর্থহীন
         * সারিতে — আর পরিবহনের খতিয়ান পড়া যেত না।
         */
        $bill = $this->aBillWithFare('0');

        $this->assertSame(0, bccomp($this->creditOn(StandardChart::TRANSPORT_PAYABLE, $bill), '0', 4),
            'ভাড়া শূন্য, তবু পরিবহনের ঘরে একটা সারি বসেছে।');

        $this->assertSame(0, bccomp($this->debitOn(StandardChart::INVENTORY, $bill), '5000', 4),
            'ভাড়া নেই, তবু মজুদের ডেবিট মালমূল্যের চেয়ে আলাদা।');
    }

    public function test_the_voucher_still_balances(): void
    {
        /*
         * ⓘ জোড়াটা নিজেই মেলে (ডেবিট মজুদ · ক্রেডিট পরিবহন), তাই
         * ভাউচারের ভারসাম্য বদলানোর কথা নয়। ⛔ কিন্তু "বদলানোর কথা নয়"
         * আর "বদলায় না" এক জিনিস নয়, আর এটাই একমাত্র ঘর যেখানে ভুলটা
         * সত্যিই সব টাকা নিয়ে যেত।
         */
        $entries = $this->entriesOf($this->aBillWithFare('300'));

        $debit = $entries->reduce(fn (string $s, $e) => bcadd($s, (string) $e->debit, 4), '0');
        $credit = $entries->reduce(fn (string $s, $e) => bcadd($s, (string) $e->credit, 4), '0');

        $this->assertSame(0, bccomp($debit, $credit, 4),
            "ভাউচারটা মেলেনি — ডেবিট {$debit}, ক্রেডিট {$credit}।");
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    /**
     * দুই সারির একটা সরাসরি বিল — ১০০০ + ৪০০০, সাথে ভাড়া।
     */
    private function aBillWithFare(string $fare, ?int $carrier = null): PurchaseBill
    {
        $bill = app(PurchaseBillService::class)->create(
            $this->header($fare, $carrier),
            [
                ['product_id' => $this->cheap->id, 'qty' => '10', 'rate' => '100', 'tax' => '0'],
                ['product_id' => $this->dear->id, 'qty' => '10', 'rate' => '400', 'tax' => '0'],
            ],
        );

        return app(PurchaseBillService::class)->confirm($bill);
    }

    /** @return array<string, mixed> */
    private function header(string $fare, ?int $carrier = null): array
    {
        return [
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
            'transport_cost' => $fare,
            'carrier_id' => $carrier,
        ];
    }

    private function valueOnHand(Product $product): string
    {
        return app(CostLayerService::class)->valueOnHand($product->fresh());
    }

    /** @return \Illuminate\Support\Collection<int, LedgerEntry> */
    private function entriesOf(PurchaseBill $bill)
    {
        return LedgerEntry::query()
            ->where('source_type', PurchaseBill::drillSourceType())
            ->where('source_id', $bill->id)
            ->get();
    }

    private function accountId(string $code): int
    {
        return (int) Account::query()->where('code', $code)->value('id');
    }

    private function debitOn(string $code, PurchaseBill $bill): string
    {
        return $this->entriesOf($bill)
            ->where('account_id', $this->accountId($code))
            ->reduce(fn (string $s, $e) => bcadd($s, (string) $e->debit, 4), '0');
    }

    private function creditOn(string $code, PurchaseBill $bill): string
    {
        return $this->entriesOf($bill)
            ->where('account_id', $this->accountId($code))
            ->reduce(fn (string $s, $e) => bcadd($s, (string) $e->credit, 4), '0');
    }
}
