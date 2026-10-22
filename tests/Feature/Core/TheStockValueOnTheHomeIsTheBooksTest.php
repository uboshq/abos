<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Core\Support\Money;
use App\Http\Controllers\Api\AuthController;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\CostLayer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockFacts;
use App\Modules\Purchase\Services\PurchaseReceiptService;
use App\Modules\Sales\Services\SalesInvoiceService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ হোমের টাকার বাক্সে "মজুদের মূল্য" — খাতা থেকে, কেনা দরে (মালিক, fe-র মারফত, ২১ অক্টোবর ২০২৬: "বাজারে বকেয়া"-র পাশে
 * মজুদের দাম, মালিক আর হিসাবরক্ষক এক নজরে চলতি পুঁজি দেখবেন — IAS 1-এর চলতি সম্পদ, IAS 2-এর কেনা দর)।
 *
 * দাবি (আসল সেবা দিয়ে মাল ঢোকানো — দুই শাখায় নিশ্চিত মাল-গ্রহণ; আসল পাতা আর ফোনের আসল দরজা):
 *  - গোটা কোম্পানির ঘর = মজুদের খাত ১১২০ আর তার সন্তানদের জের; হেডারে এক শাখা বাছা থাকলে সেই শাখার জের।
 *    ⛔ আগে গোটা কোম্পানির সংখ্যা খরচের স্তর থেকে আসত — তাই এখানে ১১২০-এ একটা স্তর-ছাড়া জার্নাল বসিয়ে দুটো আলাদা করা হয়,
 *    পুরনো সংজ্ঞায় দাবিটা লাল হত।
 *  - চাবি (inventory.cost.view) ছাড়া ঘরটাই নেই, ফোনে `stockValue` নামটাই নেই — বিক্রয়কর্মীরও নয়।
 *  - একটা বিক্রি মাল বের করলে সংখ্যাটা ঠিক সেই মালের কেনা দামে কমে।
 *  - ফোনের `stockValue.amount` আর ওয়েবের ঘর একই সংখ্যা (একই সেবা)।
 *  - সংখ্যাটা হোমে একবারই — বাক্সে, মূল সূচকের সারিতে নয়।
 */
final class TheStockValueOnTheHomeIsTheBooksTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Branch $mymensingh;

    private Branch $netrakona;

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

        // ⓘ নতুন পণ্য, কোনো স্তর নেই — দুই শাখার মাল কেবল এই দুই মাল-গ্রহণের
        $model = Product::query()->where('track_batch', false)->where('is_active', true)->orderBy('id')->firstOrFail();
        $product = $model->replicate(['public_id']);
        $product->forceFill(['code' => 'STKV-1', 'barcode' => null, 'name_en' => 'Stock value test', 'name_bn' => 'মজুদ-মূল্য পরীক্ষা'])->save();

        $this->receiveIn($this->mymensingh, $product, '10', '125.50');
        $this->receiveIn($this->netrakona, $product, '4', '80.25');

        /*
         * ⭐ খাতা আর স্তর আলাদা করা — ১১২০-এ একটা জার্নাল, স্তর ছাড়া (ময়মনসিংহে)। ⛔ পুরনো কোড গোটা কোম্পানির সংখ্যা স্তর থেকে
         * নিত, তাই এখানে সে এই ৭৭৭.৭৭ দেখত না আর দাবি লাল হত।
         */
        app(PostingEngine::class)->post(sourceType: 'test:stock-journal', sourceId: 1, trxDate: now(), branchId: $this->mymensingh->id, lines: [
            ['account_id' => StandardChart::find(StandardChart::INVENTORY)->id, 'debit' => '777.77'],
            ['account_id' => StandardChart::find(StandardChart::OWNER_CAPITAL)->id, 'credit' => '777.77'],
        ]);
    }

    public function test_the_tile_is_the_books_for_the_whole_company_and_for_the_chosen_branch(): void
    {
        // ── গোটা কোম্পানি ──
        $this->choose('all');
        $books = StandardChart::find(StandardChart::INVENTORY)->balanceOn(null, null);
        $this->assertNotSame(0, bccomp($books, $this->layerSum(), 4), 'প্রস্তুতিটাই ভুল — খাতা আর স্তর আলাদা হয়নি, দাবি পুরনো সংজ্ঞাও ধরত না।');
        $this->assertSame(Money::round($books), app(StockFacts::class)->value(), '⛔ গোটা কোম্পানির মজুদের মূল্য ১১২০-এর জের নয় (স্তরের যোগফল?)।');

        $page = $this->homePage();
        $company = $this->tile($page);
        $this->assertSame(Money::format($books), $company['value'], '⛔ হোমের ঘরে গোটা কোম্পানির মজুদ খাতার জের নেই।');
        $this->assertSame(__('inventory::overview.stock_value'), $company['label']);
        $this->assertSame(__('inventory::dashboard.kpi_stock_value_hint'), $company['hint'], '⛔ ঘরের নিচে "খরচ-দামে · খাতা অনুযায়ী" নেই।');
        $this->assertSame(route('inventory.stock.index'), $company['href'], '⛔ ঘরে চাপলে মজুদের পাতা খোলে না।');
        $this->assertOnce($page);

        // ── হেডারে এক শাখা ──
        $seen = [Money::format($books)];
        foreach ([$this->mymensingh, $this->netrakona] as $branch) {
            $this->choose($branch->id);
            $own = StandardChart::find(StandardChart::INVENTORY)->balanceOn(null, $branch->id);
            $this->assertSame(Money::round($own), app(StockFacts::class)->value(), "⛔ {$branch->code}: মজুদের মূল্য শাখার ১১২০-এর জের নয়।");

            $page = $this->homePage();
            $this->assertSame(Money::format($own), $this->tile($page)['value'], "⛔ {$branch->code} বাছা, অথচ ঘরে সেই শাখার মজুদ খাতার জের নেই।");
            $this->assertOnce($page);
            $seen[] = Money::format($own);
        }

        $this->assertSame($seen, array_values(array_unique($seen)),
            '⛔ গোটা কোম্পানি আর দুই শাখা একই সংখ্যা দেখাল — হেডারের শাখা ঘরে পৌঁছায়নি।');
    }

    public function test_the_phone_today_says_the_same_figure_as_the_tile_in_four_decimals(): void
    {
        foreach (['all', $this->netrakona->id] as $where) {
            $this->choose($where);
            $tile = $this->tile($this->homePage())['value'];
            $value = app(StockFacts::class)->value();

            $today = $this->todayAs($this->owner);
            $this->assertArrayHasKey('stockValue', $today, "{$where}: ⛔ চাবিওয়ালা মালিকের ফোনে মজুদের দাম নেই।");
            $this->assertSame(['amount' => bcadd($value, '0', 4), 'basis' => 'cost'], $today['stockValue'], "{$where}: ⛔ ফোনের চুক্তি মেলে না।");
            $this->assertMatchesRegularExpression('/^-?\d+\.\d{4}$/', $today['stockValue']['amount'], '⛔ টাকা চার ঘরের লেখা নয়।');
            $this->assertSame($tile, Money::format($today['stockValue']['amount']), "{$where}: ⛔ ফোন আর ওয়েবের ঘর দুই সংখ্যা বলে।");
        }
    }

    public function test_without_the_cost_key_there_is_no_tile_and_no_phone_key_and_the_salesman_sees_neither(): void
    {
        $keys = ['accounts.view', 'accounts.till.view'];
        $accountant = $this->person([...$keys, 'inventory.cost.view']);
        $clerk = $this->person($keys);

        // ⓘ চাবিওয়ালা — ঘর আছে, ফোনেও (দাবি অন্ধ নয়)
        $this->as($accountant);
        $page = $this->homePage();
        $this->assertSame(1, $page->query('//*[@data-home-stock]')->length, 'প্রস্তুতিটাই ভুল — চাবিওয়ালা হিসাবরক্ষকের বাক্সে মজুদের ঘর নেই।');
        $this->assertArrayHasKey('stockValue', $this->todayAs($accountant));

        // ⛔ চাবি নেই — বাক্স আছে, মজুদের ঘর নেই; সূচকের সারিতেও নেই; ফোনে নামটাই নেই (null বা "0" নয়)
        $this->as($clerk);
        $page = $this->homePage();
        $this->assertSame(1, $page->query('//*[@data-money-position]')->length, 'প্রস্তুতিটাই ভুল — চাবি-ছাড়া মানুষের টাকার বাক্সই নেই।');
        $this->assertSame(0, $page->query('//*[@data-home-stock]')->length, '⛔ খরচ দেখার চাবি ছাড়াই বাক্সে মজুদের দাম।');
        $this->assertNotContains(__('inventory::overview.stock_value'), $this->kpiLabels($page), '⛔ চাবি ছাড়াই মূল সূচকে মজুদের দাম।');
        $today = $this->todayAs($clerk);
        $this->assertArrayHasKey('money', $today, 'প্রস্তুতিটাই ভুল — ফোনের আজকের পাতায় টাকার ঘরই নেই।');
        $this->assertArrayNotHasKey('stockValue', $today, '⛔ চাবি ছাড়াই ফোনে মজুদের দাম (বা null/শূন্য) গেল।');

        // ⛔ বিক্রয়কর্মী — মজুদের দাম কোথাও নয় (মালিকের নিয়ম: SR-এর ফোনে মজুদ লুকানো)
        $salesman = User::query()->where('email', 'sales@abos.test')->firstOrFail();
        $this->assertFalse(CompanyContext::forCompany($this->company->id, fn () => $salesman->fresh()->can('inventory.cost.view')),
            'প্রস্তুতিটাই ভুল — ডেমোর বিক্রয়কর্মীর খরচ দেখার চাবি আছে।');
        $this->as($salesman);
        $html = (string) $this->get(route('dashboard'))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-home-stock', $html, '⛔ বিক্রয়কর্মীর হোমে মজুদের দাম।');
        $this->assertNotContains(__('inventory::overview.stock_value'), $this->kpiLabels($this->xpath($html)), '⛔ বিক্রয়কর্মীর মূল সূচকে মজুদের দাম।');
        $this->assertArrayNotHasKey('stockValue', $this->todayAs($salesman), '⛔ বিক্রয়কর্মীর ফোনে মজুদের দাম।');
    }

    public function test_a_sale_lowers_the_figure_by_exactly_what_left_the_shelf(): void
    {
        $this->choose('all');
        $booksBefore = StandardChart::find(StandardChart::INVENTORY)->balanceOn(null, null);
        $layersBefore = $this->layerSum();

        // ⓘ বিক্রি আসল সেবায় — বিল নিশ্চিত হলেই মাল বেরোয় আর খাতায় কেনা দরে খরচ বসে
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $invoice = app(SalesInvoiceService::class)->create(
            ['customer_id' => Customer::query()->orderBy('id')->value('id'), 'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
                'trx_date' => now()->toDateString()],
            [['product_id' => Product::query()->whereNull('tax_id')->orderBy('id')->value('id'), 'qty' => '3', 'rate' => '500']],
        );
        app(SalesInvoiceService::class)->confirm($invoice);

        $this->choose('all');
        $left = bcsub($layersBefore, $this->layerSum(), 4);
        $this->assertSame(1, bccomp($left, '0', 4), 'প্রস্তুতিটাই ভুল — বিক্রিতে তাক থেকে কিছু বেরোয়নি।');

        $booksAfter = StandardChart::find(StandardChart::INVENTORY)->balanceOn(null, null);
        $this->assertSame(0, bccomp(bcsub($booksBefore, $booksAfter, 4), $left, 4),
            "⛔ মজুদের খাত কমল ".bcsub($booksBefore, $booksAfter, 4).", অথচ বেরোনো মালের কেনা দাম {$left}।");
        $this->assertSame(Money::format($booksAfter), $this->tile($this->homePage())['value'], '⛔ বিক্রির পরে হোমের ঘর মজুদ খাতার জের নয়।');
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    private function receiveIn(Branch $branch, Product $product, string $qty, string $rate): void
    {
        $this->choose($branch->id);

        $warehouse = Warehouse::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'branch_id' => $branch->id,
            'code' => 'STKV'.$branch->code, 'name_en' => 'Stock value '.$branch->code, 'is_active' => true,
        ]);

        $service = app(PurchaseReceiptService::class);
        $receipt = $service->confirm($service->create([
            'supplier_id' => Supplier::query()->orderBy('id')->value('id'),
            'warehouse_id' => $warehouse->id,
            'trx_date' => now()->toDateString(),
        ], [['product_id' => $product->id, 'received_qty' => $qty, 'rate' => $rate]]));

        $this->assertSame((int) $branch->id, (int) $receipt->fresh()->branch_id, "প্রস্তুতিটাই ভুল — মাল-গ্রহণ {$branch->code}-এ বসেনি।");
    }

    /** গোটা কোম্পানির খরচের স্তর — অবশিষ্ট × দর (পুরনো সংজ্ঞা; কেবল তুলনার জন্য) */
    private function layerSum(): string
    {
        return (string) CostLayer::query()->withoutGlobalScopes()->where('company_id', $this->company->id)
            ->selectRaw('COALESCE(SUM(qty_remaining * unit_cost), 0) as v')->value('v');
    }

    /** হেডারে শাখা বাছা — মালিক যেভাবে বাছেন (`all` মানে "সব শাখা") */
    private function choose(int|string $branch): void
    {
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->owner->fresh())
            ->post(route('branch.switch'), ['branch_id' => (string) $branch])
            ->assertRedirect();

        $this->owner = $this->owner->fresh();
        CompanyContext::set($this->company->id, $this->owner->current_branch_id);
        app(DataScope::class)->forget();
        $this->app->forgetScopedInstances();
        $this->actingAs($this->owner);
    }

    private function as(User $user): void
    {
        $this->app['auth']->forgetGuards();
        CompanyContext::set($this->company->id, $user->current_branch_id);
        app(DataScope::class)->forget();
        $this->actingAs($user->fresh());
    }

    /** @return array<string, mixed> ফোনের আসল দরজা — `GET /api/v1/dashboard/today` */
    private function todayAs(User $user): array
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user->fresh(), [AuthController::APP]);
        $body = $this->getJson('/api/v1/dashboard/today')->assertOk()->json();

        $this->as($user);

        return $body;
    }

    private function homePage(): DOMXPath
    {
        return $this->xpath((string) $this->get(route('dashboard'))->assertOk()->getContent());
    }

    /** @return array{label: string, value: string, hint: string, href: string} */
    private function tile(DOMXPath $page): array
    {
        $tiles = $page->query('//*[@data-money-position]//*[@data-home-stock]');
        $this->assertSame(1, $tiles->length, '⛔ "মজুদের মূল্য" টাকার বাক্সে নেই, বা দুইবার।');
        $tile = $tiles->item(0);
        $text = fn (string $path) => html_entity_decode(trim($page->query($path, $tile)->item(0)?->textContent ?? ''));

        return [
            'label' => $text('.//p[1]'),
            'value' => $text('.//*[@data-money-tile-value]'),
            'hint' => $text('.//*[@data-money-tile-hint]'),
            'href' => $tile instanceof \DOMElement && $tile->tagName === 'a' ? $tile->getAttribute('href') : '',
        ];
    }

    /** ⭐ একবারই — বাক্সে আছে, মূল সূচকের সারিতে নেই */
    private function assertOnce(DOMXPath $page): void
    {
        $this->assertNotContains(__('inventory::overview.stock_value'), $this->kpiLabels($page),
            '⛔ মজুদের মূল্য বাক্সে আর মূল সূচকে দুইবার — একই সংখ্যা দুই জায়গায়।');
    }

    /** @return list<string> */
    private function kpiLabels(DOMXPath $page): array
    {
        $out = [];
        foreach ($page->query('//a[@data-kpi]') as $kpi) {
            $out[] = trim($page->query('.//span[contains(@class, "truncate")]/span[contains(@class, "truncate")]', $kpi)->item(0)?->textContent ?? '');
        }

        return $out;
    }

    /** @param list<string> $keys */
    private function person(array $keys): User
    {
        $user = User::factory()->create(['current_company_id' => $this->company->id, 'current_branch_id' => null, 'is_active' => true]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);

        CompanyContext::forCompany($this->company->id, function () use ($user, $keys): void {
            foreach ($keys as $key) {
                $user->givePermissionTo(Permission::findOrCreate($key, 'web'));
            }
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    private function branch(string $code): Branch
    {
        return Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', $code)->firstOrFail();
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html);

        return new DOMXPath($dom);
    }
}
