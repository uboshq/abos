<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\CashTill;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Inventory\Services\StockTransferService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * এক শাখার গুদাম, মজুদ আর টিল আরেক শাখায় দেখাত — ১ অক্টোবর ২০২৬।
 *
 * ── ⛔ মালিকের নির্দেশ ──────────────────────────────────────────────────
 * *"প্রতিটা শাখা পুরোপুরি আলাদা — এক শাখার কিছু আরেক শাখায় নয়; একসাথে কেবল
 * মালিক দেখবেন, 'সব শাখা'-য়।"* পক্ষ আর টাকার পিকার আগেই আলাদা হয়েছিল
 * ([[EachBranchSeesOnlyItsOwnPartiesTest]], [[AMoneyPickerShowedAnotherBranchsTillTest]]);
 * গুদাম, মজুদের সংখ্যা, টিল আর টিলের খাত তখনো সবার দেখাত।
 *
 * ── ⭐ মাপ: মালিক যেভাবে মাপেন ────────────────────────────────────────────
 * একই মানুষ, একই পাতা — হেডারে ময়মনসিংহ, তারপর "সব শাখা"। ময়মনসিংহে নেত্রকোনার আর
 * শাখাহীন কিছু নেই, নিজেরটা আছে; "সব শাখা"-য় তিনটাই।
 *
 * ⚠️ প্রতিটা পাতা আগে "সব শাখা"-য় দেখা হয় — যে পাতা ওখানেও জিনিসটা দেখায় না, তার
 * "দেখায় না" কিছুই প্রমাণ করে না। তাই কোন পাতাগুলো সত্যিই তাকিয়েছে তার একটা ন্যূনতম
 * তালিকাও মেলানো হয়।
 */
final class EachBranchIsFullySeparateTest extends TestCase
{
    use RefreshDatabase;

    private const PLACES = ['mms', 'ntk', 'none'];

    private Company $company;

    private User $owner;

    private Branch $mymensingh;

    private Branch $netrakona;

    /** @var array<string, Warehouse> */
    private array $warehouse = [];

    /** @var array<string, int> */
    private array $till = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->mymensingh = $this->branch('MMS');
        $this->netrakona = $this->branch('NTK');
        CompanyContext::set($this->company->id, $this->mymensingh->id);
        $this->actingAs($this->owner);

        $places = ['mms' => $this->mymensingh->id, 'ntk' => $this->netrakona->id, 'none' => null];

        // ⓘ RFQ-র ফর্ম সরবরাহকারী না থাকলে খোলেই না — ময়মনসিংহকে একজন দেওয়া
        Supplier::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('is_active', true)
            ->orderBy('id')->firstOrFail()->forceFill(['branch_id' => $this->mymensingh->id])->saveQuietly();

        foreach ($places as $where => $branch) {
            $marker = strtoupper($where);

            $this->warehouse[$where] = Warehouse::query()->withoutGlobalScopes()->create([
                'company_id' => $this->company->id, 'branch_id' => $branch,
                'code' => 'ZQW'.$marker, 'name_en' => 'ZQWH'.$marker, 'is_active' => true,
            ]);

            $till = app(CashTillService::class)->create(['code' => 'ZQ'.$marker, 'name_en' => 'ZQTILL'.$marker]);
            CashTill::query()->withoutGlobalScopes()->whereKey($till->id)->update(['branch_id' => $branch]);
            $this->till[$where] = (int) $till->id;
        }
    }

    public function test_each_page_shows_only_the_picked_branchs_warehouses_and_tills(): void
    {
        $pages = [
            'গুদামের তালিকা' => route('inventory.warehouse.index'),
            'মজুদ' => route('inventory.stock.index', ['stock' => 'all']),
            'মজুদের সারাংশ' => route('inventory.stock.overview'),
            'মজুদ সমন্বয়' => route('inventory.stock.adjust'),
            'মাল ইস্যু' => route('inventory.stock.issue'),
            'স্থানান্তর' => route('inventory.transfer.create'),
            'মজুদ গণনা' => route('inventory.count.create'),
            'বিক্রয় অর্ডার' => route('sales.order.create'),
            'চালান' => route('sales.challan.create'),
            'শিপমেন্ট' => route('sales.shipment.create'),
            'বিক্রয় ফেরত' => route('sales.return.create'),
            'সরাসরি ক্রয়' => route('purchase.direct.create'),
            'RFQ' => route('purchase.rfq.create'),
            'ক্রয় অর্ডার' => route('purchase.order.create'),
            'মাল গ্রহণ' => route('purchase.receipt.create'),
            'ক্রয় ফেরত' => route('purchase.return.create'),
            'টিলের তালিকা' => route('accounts.till.index'),
            'টাকা গোনা' => route('accounts.count.create'),
            'টাকা হস্তান্তর' => route('accounts.transfer.create'),
            'হিসাবের ছক' => route('accounts.coa.index'),
            'ঋণ' => route('accounts.loan.create'),
            'কর' => route('master_data.tax.create'),
            'পরিশোধের উপায়' => route('master_data.payment_method.create'),
            'টাকার খাতের শ্রেণি' => route('master_data.money_category.create'),
            'মূলধন' => route('finance.capital.create'),
            'উত্তোলন' => route('finance.withdrawal.create'),
            'ব্যাংক সুবিধা' => route('finance.bank_facility.create'),
            'হাত-ঋণ' => route('finance.hand_loan.create'),
            'ভাড়া' => route('finance.rental.create'),
            'খাত বিশ্লেষণ' => route('finance.account_analysis.index'),
            'বেতনের খাত' => route('hr.salary_head.create'),
        ];

        $this->choose('all');
        $all = array_map(fn (string $url) => $this->markersOn($url), $pages);

        $this->choose($this->mymensingh->id);
        $mms = array_map(fn (string $url) => $this->markersOn($url), $pages);

        $looked = [];

        foreach ($pages as $name => $url) {
            foreach (['ZQWH', 'ZQTILL'] as $kind) {
                if (! in_array($kind.'NTK', $all[$name], true)) {
                    continue; // এই পাতা এই জিনিস দেখায়ই না
                }

                $looked[] = $name.'/'.$kind;

                foreach (self::PLACES as $where) {
                    $marker = $kind.strtoupper($where);
                    $this->assertContains($marker, $all[$name], "⛔ {$name} — \"সব শাখা\"-য় {$marker} নেই।");
                    $this->assertSame($where === 'mms', in_array($marker, $mms[$name], true), sprintf(
                        '⛔ %s — ময়মনসিংহ বেছে %s %s।', $name, $marker, $where === 'mms' ? 'দেখা যায়নি' : 'দেখা গেল',
                    ));
                }
            }
        }

        // ⚠️ পাহারা সত্যিই তাকিয়েছে — এই পাতাগুলোয় "সব শাখা"-য় জিনিসটা দেখা চাই
        foreach (['গুদামের তালিকা/ZQWH', 'মজুদ সমন্বয়/ZQWH', 'স্থানান্তর/ZQWH', 'বিক্রয় অর্ডার/ZQWH',
            'সরাসরি ক্রয়/ZQWH', 'টিলের তালিকা/ZQTILL', 'টাকা হস্তান্তর/ZQTILL', 'হিসাবের ছক/ZQTILL'] as $must) {
            $this->assertContains($must, $looked, "⛔ {$must} — \"সব শাখা\"-য়ও দেখা গেল না, দাবিটা কিছুই মাপেনি।");
        }
    }

    public function test_the_stock_list_counts_only_the_picked_branchs_warehouses(): void
    {
        $product = Product::query()->where('track_batch', false)->where('is_active', true)
            ->whereNotExists(fn ($q) => $q->from('inv_product_branches')->whereColumn('inv_product_branches.product_id', 'inv_products.id'))
            ->orderBy('id')->firstOrFail();

        foreach (['mms' => '5', 'ntk' => '7', 'none' => '11'] as $where => $qty) {
            app(StockService::class)->move(
                product: $product, warehouse: $this->warehouse[$where],
                sourceType: StockService::ADJUSTMENT, sourceId: $product->id, floor: $qty,
            );
        }

        $this->assertSame(5 + $this->demoStockIn($product, $this->mymensingh->id), $this->floorOf($product, $this->mymensingh->id), '⛔ ময়মনসিংহের মজুদে অন্য শাখার মাল যোগ হলো।');
        $this->assertSame(7 + $this->demoStockIn($product, $this->netrakona->id), $this->floorOf($product, $this->netrakona->id), '⛔ নেত্রকোনার মজুদে অন্য শাখার মাল যোগ হলো।');
        $this->assertSame(23 + $this->demoStockIn($product, null), $this->floorOf($product, 'all'), '⛔ "সব শাখা"-য় কোনো শাখার মাল বাদ পড়ল।');
    }

    public function test_the_overview_value_is_the_branchs_own_inventory_ledger(): void
    {
        $this->choose($this->mymensingh->id);

        $value = $this->actingAs($this->owner)->get(route('inventory.stock.overview'))->assertOk()->viewData('value');
        $ledger = StandardChart::find(StandardChart::INVENTORY)->balanceOn(null, $this->mymensingh->id);

        $this->assertSame(bcadd($ledger, '0', 2), $value, '⛔ এক শাখা বেছে মজুদের মূল্য গোটা কোম্পানির দেখাল।');
    }

    public function test_a_transfer_between_branches_is_only_set_up_under_all_branches(): void
    {
        $this->choose($this->mymensingh->id);
        $one = $this->idsOn(route('accounts.transfer.create'), 'tills');

        $this->choose('all');
        $every = $this->idsOn(route('accounts.transfer.create'), 'tills');

        foreach (self::PLACES as $where) {
            $this->assertSame($where === 'mms', in_array($this->till[$where], $one, true), "⛔ ময়মনসিংহ বেছে \"কোন টিলে\"-তে {$where}-এর টিল ভুল জায়গায়।");
            $this->assertContains($this->till[$where], $every, "⛔ \"সব শাখা\"-য় \"কোন টিলে\" থেকে {$where}-এর টিল হারাল — শাখা পেরোনো হস্তান্তর আর বসানো যেত না।");
        }
    }

    public function test_a_stock_transfer_to_another_branch_is_received_while_viewing_its_own_branch(): void
    {
        /*
         * ⚠️ দেয়ালটা দেখানোর — মজুদের ইঞ্জিনের নয়। "সব শাখা"-য় ময়মনসিংহ → নেত্রকোনা স্থানান্তর বসে,
         * আর গ্রহণ হয় কাগজের নিজের শাখা (ময়মনসিংহ) বেছে; তখন গন্তব্যের গুদাম খুঁজে না পেলে গ্রহণ
         * ভাঙত।
         */
        $product = Product::query()->where('track_batch', false)->where('is_active', true)->orderBy('id')->firstOrFail();

        $this->choose('all');
        app(StockService::class)->move(
            product: $product, warehouse: $this->warehouse['mms'],
            sourceType: StockService::ADJUSTMENT, sourceId: $product->id, floor: '9',
        );

        $transfer = app(StockTransferService::class)->create(
            ['from_warehouse_id' => $this->warehouse['mms']->id, 'to_warehouse_id' => $this->warehouse['ntk']->id, 'branch_id' => $this->mymensingh->id],
            [['product_id' => $product->id, 'qty' => '4']],
        );
        app(StockTransferService::class)->dispatch($transfer);

        $this->choose($this->mymensingh->id);
        app(StockTransferService::class)->receive($transfer->fresh());

        // ⓘ কাঁচা — চলাচলের নিজের শাখার দেয়াল ([[ScopedToUserBranch]]) নেত্রকোনার সারি ময়মনসিংহে লুকায়
        $floor = fn (string $where) => (string) DB::table('inv_stock_movements')
            ->where('product_id', $product->id)->where('warehouse_id', $this->warehouse[$where]->id)->sum('floor_change');

        $this->assertSame(0, bccomp('5', $floor('mms'), 4), '⛔ উৎসের গুদামে ভুল পরিমাণ রইল।');
        $this->assertSame(0, bccomp('4', $floor('ntk'), 4), '⛔ গন্তব্যের গুদামে মাল পৌঁছায়নি।');
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    /** @return list<string> */
    private function markersOn(string $url): array
    {
        $html = $this->actingAs($this->owner)->get($url)->assertOk()->getContent();
        preg_match_all('/ZQ(?:WH|TILL)(?:MMS|NTK|NONE)/', (string) $html, $m);

        return array_values(array_unique($m[0]));
    }

    private function floorOf(Product $product, int|string $branch): int
    {
        $this->choose($branch);

        $rows = $this->actingAs($this->owner)
            ->get(route('inventory.stock.index', ['stock' => 'all', 'q' => $product->code]))
            ->assertOk()->viewData('products');

        $row = collect($rows->items())->firstWhere('id', $product->id);
        $this->assertNotNull($row, "প্রস্তুতিটাই ভুল — {$product->code} তালিকায় নেই।");

        return (int) $row->floor_total;
    }

    /** ডেমোর নিজের গুদামে আগে থেকে যা ছিল — এই শাখার, বা (`null`) গোটা কোম্পানির; আমাদের তিন গুদাম বাদে। */
    private function demoStockIn(Product $product, ?int $branch): int
    {
        return (int) DB::table('inv_stock_movements as m')
            ->join('inv_warehouses as w', 'w.id', '=', 'm.warehouse_id')
            ->where('m.product_id', $product->id)
            ->when($branch !== null, fn ($q) => $q->where('w.branch_id', $branch))
            ->whereNotIn('w.id', array_map(fn (Warehouse $w) => $w->id, $this->warehouse))
            ->sum('m.floor_change');
    }

    /** @return list<int> */
    private function idsOn(string $url, string $key): array
    {
        return collect($this->actingAs($this->owner)->get($url)->assertOk()->viewData($key))
            ->map(fn ($r) => (int) $r->id)->values()->all();
    }

    private function choose(int|string $branch): void
    {
        $this->actingAs($this->owner->fresh())
            ->post(route('branch.switch'), ['branch_id' => (string) $branch])
            ->assertRedirect();

        $this->owner = $this->owner->fresh();
        CompanyContext::set($this->company->id, $this->owner->current_branch_id);
        app(DataScope::class)->forget();
        $this->app->forgetScopedInstances();
        $this->actingAs($this->owner);
    }

    private function branch(string $code): Branch
    {
        return Branch::query()->withoutGlobalScopes()
            ->where('company_id', $this->company->id)->where('code', $code)->firstOrFail();
    }
}
