<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\ChecksTheFiveMatches;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * লট ধরা পণ্য সরাসরি ক্রয়ে কেনাই যেত না — ২৭ সেপ্টেম্বর ২০২৬, চালুর আগের ১ নম্বর বাধা।
 *
 * ── কী ভাঙা ছিল ──────────────────────────────────────────────────────
 * মালিকের সিদ্ধান্ত: নতুন পণ্য লট ধরে চলে (`track_batch`)। ⓘ আর লট ধরা
 * পণ্যে মাল ঢোকার সময় লট নম্বর বাধ্যতামূলক ([[BatchService::receive]])।
 * ⛔ অথচ সরাসরি ক্রয়ের পর্দায় লট বা মেয়াদের **কোনো ঘরই ছিল না** — তাই
 * ঐ পণ্য কাউন্টার দিয়ে কেনার কোনো পথই ছিল না।
 *
 * ⚠️ আর ব্যর্থতাটাও নোংরা ছিল: বার্তা বসত `lines` নামে (কোন সারি, বলা
 * নেই), আর ততক্ষণে বিলটা খসড়া হয়ে লেখা হয়ে গেছে — প্রতি চেষ্টায় একটা।
 *
 * ── ⭐ এখানে যা দাবি করা হয় ───────────────────────────────────────────
 *   ক · লট ছাড়া পাঠালে কাগজের লট নম্বর নিজে বসে — `DDMMYY/01-LOT` (মালিক, ৫ অক্টোবর ২০২৬; আগে ফেরানো হত)
 *   খ · লট ও মেয়াদসহ পাঠালে মাল ঐ লটে ঢোকে, আর পাঁচ মিল মেলে
 *   গ · পর্দায় লটের ঘর আছে, কেবল লট ধরা সারির জন্য; তালিকা বলে কে লট ধরে, আর ঘরে পরের লট নম্বরের প্রস্তাব
 *
 * ⓘ "গতবারের লট" প্রস্তাব (আগের ঘ) ৫ অক্টোবর ২০২৬-এ উঠে গেছে — তার জায়গায় নম্বর-ক্রমের লট ([[PurchaseLots]])।
 *
 * ⓘ ব্যবহারকারী ভূমিকাহীন, হাতে কেবল একটা চাবি — `purchase.bill.create`।
 */
final class ALotTrackedProductCouldNotBeBoughtDirectlyTest extends TestCase
{
    use ChecksTheFiveMatches;
    use PutsMoneyInTheTill;
    use RefreshDatabase;

    private const KEY = 'purchase.bill.create';

    private Company $depot;

    private User $buyer;

    private Supplier $supplier;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->depot = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->depot->id, $this->depot->defaultBranch()?->id);

        app(StandardChart::class)->install();
        app(CashTillService::class)->ensurePrimaryTill();

        $this->buyer = User::factory()->create([
            'name' => 'Counter Buyer',
            'is_active' => true,
            'locale' => 'bn',
            'current_company_id' => $this->depot->id,
        ]);
        $this->buyer->companies()->attach($this->depot->id, ['is_active' => true]);
        CompanyContext::forCompany($this->depot->id, fn () => $this->buyer->givePermissionTo(self::KEY));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->buyer = $this->buyer->fresh();

        $this->assertCount(0, $this->buyer->getRoleNames(), 'ক্রেতার কোনো ভূমিকা থাকার কথা নয় — চাবি একটাই।');

        $this->actingAs($this->buyer);

        $this->supplier = Supplier::query()->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();

        /*
         * ⓘ এমন একটা পণ্য যার আগে কোনো লট নেই — মালিকের "নতুন পণ্য লট
         * ধরে" নিয়মের হুবহু ছবি।
         */
        $this->product = Product::query()
            ->active()
            ->whereNotIn('id', Batch::query()->select('product_id'))
            ->orderBy('id')
            ->firstOrFail();
        $this->product->forceFill(['track_batch' => true])->save();
    }

    // ── ক · লট ছাড়া — কাগজের লট নম্বর নিজে বসে (মালিক, ৫ অক্টোবর ২০২৬) ──────

    public function test_without_a_lot_the_line_gets_the_papers_lot_number(): void
    {
        $this->buy(['batch_no' => '', 'expiry_date' => ''])->assertSessionHasNoErrors()->assertRedirect();

        $bill = PurchaseBill::query()->latest('id')->with('lines')->firstOrFail();
        $this->assertSame('confirmed', $bill->status, 'খালি লটের বিলটা নিশ্চিত হয়নি।');

        $lot = (string) $bill->lines->first()->batch_no;
        $this->assertSame(now()->format('dmy').'/01-LOT', $lot, 'খালি লটে কাগজের লট নম্বর বসেনি।');
        $this->assertTrue(Batch::query()->where('product_id', $this->product->id)->where('batch_no', $lot)->exists(),
            'নম্বরটা সারিতে আছে, কিন্তু মাল ঐ লটে ঢোকেনি।');
    }

    // ── খ · লট ও মেয়াদসহ — মাল ঐ লটে, আর পাঁচ মিল ────────────────────────

    public function test_with_a_lot_and_expiry_the_goods_enter_that_lot_and_the_five_matches_hold(): void
    {
        $expiry = now()->addYear()->toDateString();
        $till = app(CashTillService::class)->ensurePrimaryTill();

        /*
         * ⓵ ২৮ সেপ্টেম্বর ২০২৬: খালি নগদ বাক্স থেকে টাকা বেরোয় না (CashOnHand)।
         * ২০০ নগদে যায় এই টিল থেকে, আজকের তারিখে; টাকা আসে ৩১০০ মূলধন থেকে।
         * ⓘ মাপের ছবির **আগে** রাখা — পাঁচ মিল আগে-পরের তফাত মাপে, তাই অক্ষত।
         */
        $this->putMoneyIn(Account::query()->findOrFail($till->account_id), '1000', now()->toDateString());

        $snap = $this->snapshot();

        $this->buy(['batch_no' => 'LOT-A1', 'expiry_date' => $expiry, 'mrp' => '95'], [
            'paid_now' => '200',
            'paid_from_account_id' => $till->account_id,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $bill = PurchaseBill::query()->latest('id')->firstOrFail();
        $this->assertSame('confirmed', $bill->status, 'বিলটা নিশ্চিত হয়নি।');

        $batch = Batch::query()->where('product_id', $this->product->id)->where('batch_no', 'LOT-A1')->first();

        $this->assertNotNull($batch, 'লট নম্বরটা পর্দা থেকে লট সারিতে পৌঁছায়নি।');
        $this->assertSame($expiry, $batch->expiry_date?->toDateString(), 'মেয়াদটা লটে বসেনি।');
        $this->assertSame(0, bccomp((string) $batch->mrp, '95', 4), 'ছাপা দামটা লটে বসেনি।');

        // ⓘ মাল ঐ লটেই ঢুকেছে — ১০ একক
        $inLot = (string) DB::table('inv_stock_movements')
            ->where('batch_id', $batch->id)
            ->selectRaw('COALESCE(SUM(floor_change + unplaced_change), 0) as q')
            ->value('q');
        $this->assertSame(0, bccomp($inLot, '10', 4), 'মাল ঐ লটে ঢোকেনি।');

        // ⭐ পাঁচ মিল — ১০ × ৬০ = ৬০০ মজুদে, ৪০০ বাকি, ২০০ নগদে গেল, লাভ অনড়
        $this->assertTheFiveMatches([
            'books' => [
                StandardChart::INVENTORY => bcadd($snap['inventory'], '600', 4),
                StandardChart::PAYABLE => bcsub($snap['payable'], '400', 4),
            ],
            'stock' => [
                $this->product->id => [
                    'qty' => bcadd($snap['qty'], '10', 4),
                    'value' => bcadd($snap['value'], '600', 4),
                ],
            ],
            'party' => [[
                'type' => 'supplier',
                'id' => (int) $this->supplier->id,
                'code' => StandardChart::PAYABLE,
                'amount' => bcsub($snap['supplier'], '400', 4),
            ]],
            'money' => ['cash' => bcsub($snap['cash'], '200', 4)],
            'profit' => $snap['profit'],
        ]);

        // ⓘ এই বিলের নিজের দাখিলাও নিজে নিজে সমান
        $this->assertTheBooksMatch([], PurchaseBill::STOCK_SOURCE, (int) $bill->id);
    }

    // ── গ · পর্দা — ঘরগুলো আছে, আর তালিকা বলে কে লট ধরে ─────────────────

    public function test_the_screen_carries_lot_fields_only_for_lot_tracked_lines(): void
    {
        $this->buy(['batch_no' => 'LOT-SEEN', 'expiry_date' => now()->addMonths(6)->toDateString()])
            ->assertSessionHasNoErrors();

        $plain = Product::query()->active()->whereKeyNot($this->product->id)->orderBy('id')->firstOrFail();
        $plain->forceFill(['track_batch' => false])->save();

        $html = $this->decoded($this->get(route('purchase.direct.create'))->assertOk()->getContent());

        // ⓘ সারির লট-ঘর — কেবল লট ধরা সারিতে (শর্তটা JS-এর `tracksLot`, পরীক্ষা JS-এ)
        $this->assertStringContainsString('x-if="tracksLot(line)"', $html, 'লটের ঘরগুলো শর্তসাপেক্ষ নয়।');
        $this->assertStringContainsString("'lines[' + (index) + '][batch_no]'", $html, 'লট নম্বরের ঘর নেই।');
        $this->assertStringContainsString("'lines[' + (index) + '][expiry_date]'", $html, 'মেয়াদের ঘর নেই।');
        $this->assertStringContainsString("'lines[' + (index) + '][mrp]'", $html, 'ছাপা দামের ঘর নেই।');
        $this->assertStringContainsString('data-lot-fields', $html);

        // ⓘ Enter পরের ঘরে যায় — ফর্ম জমা হয় না
        $this->assertStringContainsString('lotNext($event)', $html, 'লটের ঘরে Enter-এর পথ নেই।');

        // ⓘ তালিকা বলে এই পণ্য লট ধরে — আর "গতবারের লট" আর যায় না (৫ অক্টোবর ২০২৬)
        $this->assertMatchesRegularExpression(
            '/"id":'.$this->product->id.',[^{}]*"track_batch":true/',
            $html,
            'পর্দার তালিকায় লট ধরা পণ্যের চিহ্ন নেই।',
        );
        $this->assertStringNotContainsString('"last_lot"', $html, 'গতবারের লট এখনো প্রস্তাব হয়ে পর্দায় যায়।');

        // ⓘ লট না-ধরা পণ্যের সারি লট চায় না
        $this->assertMatchesRegularExpression(
            '/"id":'.$plain->id.',[^{}]*"track_batch":false/',
            $html,
            'লট না-ধরা পণ্যকেও তালিকা লট ধরা বলছে।',
        );

        // ⭐ ঘরে পরের লট নম্বরের প্রস্তাব — আজকের দ্বিতীয়টা, কারণ উপরে প্রথমটা "LOT-SEEN" হাতে লেখা (সিরিজ ছোঁয়নি)
        $this->assertStringContainsString('lotHint: "'.now()->format('dmy').'/01-LOT"', $html, 'পর্দায় লট নম্বরের প্রস্তাব নেই।');
        $this->assertStringContainsString(':placeholder="lotHint"', $html, 'প্রস্তাবটা লট-ঘরে বাঁধা নয়।');
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────────

    /**
     * পর্দার পথেই কেনা — `purchase.direct.store`।
     *
     * @param  array<string, string>  $lot
     * @param  array<string, mixed>  $extra
     */
    private function buy(array $lot, array $extra = [], bool $follow = false): TestResponse
    {
        $client = $this->from(route('purchase.direct.create'));

        if ($follow) {
            $client = $client->followingRedirects();
        }

        return $client->post(route('purchase.direct.store'), [
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
            'supplier_bill_no' => 'LOT-'.fake()->unique()->numberBetween(10000, 99999),
            'payment_term' => 'cash',
            ...$extra,
            'lines' => [[
                'product_id' => $this->product->id,
                'qty' => '10',
                'rate' => '60', 'sales_price' => '60',
                'tax' => '0',
                ...$lot,
            ]],
        ]);
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        return [
            'bills' => PurchaseBill::query()->withTrashed()->count(),
            'movements' => DB::table('inv_stock_movements')->count(),
            'ledger' => DB::table('ledger_entries')->count(),
            'batches' => DB::table('inv_batches')->count(),
            'vouchers' => DB::table('vouchers')->count(),
        ];
    }

    /** @return array<string, string> */
    private function snapshot(): array
    {
        return [
            'inventory' => $this->fiveMatchMovementOf(StandardChart::INVENTORY),
            'payable' => $this->fiveMatchMovementOf(StandardChart::PAYABLE),
            'qty' => $this->fiveMatchStockOnHand((int) $this->product->id),
            'value' => $this->fiveMatchLayerValueOf((int) $this->product->id),
            'supplier' => $this->fiveMatchLedgerMovement(
                $this->fiveMatchAccountFamily(StandardChart::PAYABLE),
                fn ($q) => $q->where('party_type', 'supplier')->where('party_id', $this->supplier->id),
            ),
            'cash' => $this->fiveMatchLedgerMovement(
                $this->fiveMatchMoneyAccounts('cash', StandardChart::CASH_IN_HAND),
            ),
            'profit' => bcsub(
                bcadd(
                    bcmul($this->fiveMatchMovementOf(StandardChart::SALES), '-1', 4),
                    bcmul($this->fiveMatchMovementOf(StandardChart::SALES_RETURN), '-1', 4),
                    4,
                ),
                $this->fiveMatchMovementOf(StandardChart::COST_OF_GOODS_SOLD),
                4,
            ),
        ];
    }

    /** পাতায় JSON এনকোড হয়ে বসে — HTML এসকেপ খুলে পড়া। */
    private function decoded(string $html): string
    {
        return html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
