<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Customer;

use App\Core\Dashboard\ActivityRegistry;
use App\Core\Services\PartyRegistry;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MasterData\Models\Location;
use App\Modules\Sales\Models\Collection;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\DirectSaleService;
use App\Modules\Sales\Services\SalesQuotationService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ⭐ গ্রাহকের নামের পাশে পয়েন্ট আর শাখা — মালিক, ১৯ অক্টোবর ২০২৬ (স্থায়ী নিয়ম): *"গ্রাহকের নামের পাশে পয়েন্ট দিতে হবে
 * অবশ্যই, সব জায়গায়, আর শাখার নামও দিতে হবে।"*
 *
 * ⭐ দাবি: রুটে বসা একটা দোকান (পয়েন্ট মায়ের নাম, রুটের নয়) একটা নামওয়ালা শাখায় — বিক্রয়ের তালিকাগুলো (বিল, উদ্ধৃতি, আদায়)
 * আর গ্রাহকের নিজের তালিকা সারিতে "পয়েন্ট · শাখা" দেখায়; পিকারগুলো (বিক্রয় আদেশের ফর্ম, আদায়ের ফর্ম, কাউন্টার, ভাউচারের পক্ষ-খোঁজা)
 * "নাম · পয়েন্ট · শাখা" দেখায়; ড্যাশবোর্ড (হোমের "সদ্য যা হলো", বিক্রয়ের সবচেয়ে বড় বকেয়া, হিসাবের সবচেয়ে বড় বকেয়া) একই।
 * ⚠️ জায়গা আনে গোটা পাতার এক প্রশ্ন — সারি বাড়লে গ্রাহক/জায়গা/শাখার প্রশ্ন বাড়ে না।
 */
final class TheCustomerNameCarriesPointAndBranchTest extends TestCase
{
    use RefreshDatabase;

    private const POINT = 'কারওয়ান বাজার';

    private const BRANCH = 'কিশোরগঞ্জ ডিপো';

    private Company $company;

    private User $owner;

    private Customer $shop;

    private Product $product;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $branch = $this->company->defaultBranch();
        $this->assertNotNull($branch, 'দৃশ্যটাই ভুল — TDEPOT-এর মূল শাখা নেই।');
        CompanyContext::set($this->company->id, $branch->id);
        app(StandardChart::class)->install();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
        app(SettingsService::class)->set('customer.credit_limit_enabled', false);

        // ⓘ শাখার নাম দুই ভাষাতেই এক — চলতি ভাষা যাই হোক, দাবি একই লেখা খোঁজে
        Branch::query()->whereKey($branch->id)->update(['name_en' => self::BRANCH, 'name_bn' => self::BRANCH]);

        $point = $this->place('PT-PB', self::POINT, Location::POINT);
        $route = $this->place('RT-PB', 'রুট ৯', Location::ROUTE, $point);
        $this->shop = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();
        $this->shop->forceFill(['location_id' => $route->id, 'branch_id' => $branch->id])->save();
        $this->shop = $this->shop->fresh()->load(Customer::PLACE);
        $this->product = Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
    }

    public function test_the_model_names_the_shop_with_its_point_and_branch_not_its_route(): void
    {
        $this->assertSame(self::POINT.' · '.self::BRANCH, $this->shop->placeName(), '⛔ জায়গায় পয়েন্ট আর শাখা নেই (বা রুটের নাম)।');
        $this->assertSame($this->shop->name().' · '.self::POINT.' · '.self::BRANCH, $this->shop->nameWithPoint(),
            '⛔ নামের পাশে "পয়েন্ট · শাখা" নেই।');
    }

    public function test_the_sales_lists_show_point_and_branch_in_the_row_and_do_not_ask_per_row(): void
    {
        $bill = $this->plainBill('INV-PB-0');
        $quotation = $this->quotation();
        Collection::query()->create([
            'branch_id' => $this->shop->branch_id, 'document_no' => 'COL-PB-1', 'customer_id' => $this->shop->id,
            'account_id' => Account::query()->postable()->firstOrFail()->id,
            'trx_date' => now()->toDateString(), 'amount' => '100', 'status' => DocumentStatus::CONFIRMED,
        ]);

        foreach ([
            'sales.invoice.index' => [(string) $bill->document_no, 'বিলের তালিকা'],
            'sales.quotation.index' => [(string) $quotation->document_no, 'উদ্ধৃতির তালিকা'],
            'sales.collection.index' => ['COL-PB-1', 'আদায়ের তালিকা'],
        ] as $list => [$no, $where]) {
            $html = (string) $this->get(route($list))->assertOk()->getContent();
            $this->assertStringContainsString(e(__('customer::field.place')), $html, "⛔ {$where}: \"পয়েন্ট · শাখা\" কলামের মাথা নেই।");
            $this->assertPlaceInRow($html, $no, $where);
        }

        $few = $this->placeQueries(fn () => $this->get(route('sales.invoice.index'))->assertOk());
        for ($i = 1; $i <= 20; $i++) {
            $this->plainBill('INV-PB-'.$i);
        }
        $many = $this->placeQueries(fn () => $this->get(route('sales.invoice.index'))->assertOk());

        foreach (['customers', 'mdm_locations', 'branches'] as $table) {
            $this->assertLessThanOrEqual($few[$table], $many[$table],
                "⛔ বিলের তালিকা: সারি বাড়তেই `{$table}`-এর প্রশ্ন বাড়ল ({$few[$table]} → {$many[$table]}) — সারি ধরে প্রশ্ন।");
        }
        $this->assertLessThanOrEqual(3, $many['customers'] + $many['mdm_locations'],
            "⛔ বিলের তালিকা: একুশ সারিতে গ্রাহক/জায়গার ".($many['customers'] + $many['mdm_locations']).'টা প্রশ্ন।');
    }

    public function test_the_customer_list_shows_the_branch_beside_point_and_area(): void
    {
        $html = (string) $this->get(route('customer.index'))->assertOk()->getContent();
        $row = $this->rowWith($html, (string) $this->shop->code);

        $this->assertStringContainsString(e(self::POINT), $row, '⛔ গ্রাহকের তালিকা: সারিতে পয়েন্ট নেই।');
        $this->assertStringContainsString(e(self::BRANCH), $row, '⛔ গ্রাহকের তালিকা: সারিতে শাখা নেই।');
        $this->assertStringContainsString(e(__('customer::field.branch')), $html, '⛔ গ্রাহকের তালিকা: শাখার কলামের মাথা নেই।');
    }

    public function test_the_pickers_name_the_shop_with_its_point_and_branch(): void
    {
        $label = e($this->shop->nameWithPoint());

        $this->assertStringContainsString($label, (string) $this->get(route('sales.order.create'))->assertOk()->getContent(),
            '⛔ বিক্রয় আদেশের ফর্মের গ্রাহক-পিকারে "নাম · পয়েন্ট · শাখা" নেই।');
        /*
         * ⓘ আদায়ের গ্রাহক-ঘর এখন খোঁজা যায় এমন পিকার, দুই লাইনে (fe, ২৪ অক্টোবর ২০২৬): নাম উপরে, তার নিচে "পয়েন্ট · শাখা"। তালিকার
         * সারি পাতায় আঁকা হয় JS-এ, তাই দাবি পিকারের সারি থেকে — নামের সাথে পয়েন্ট আর শাখা ঠিক সেই সারিতেই, আর ঘরটা দুই-লাইনের।
         */
        $collection = $this->get(route('sales.collection.create'))->assertOk();
        $row = collect($collection->viewData('customerRows'))->firstWhere('id', $this->shop->id);
        $this->assertSame([$this->shop->name(), self::POINT.' · '.self::BRANCH], [$row['label'] ?? null, $row['hint'] ?? null],
            '⛔ আদায়ের ফর্মের গ্রাহক-পিকারে নামের সাথে "পয়েন্ট · শাখা" নেই।');
        $collection->assertSee('data-party-stacked', false);

        $terms = $this->get(route('sales.direct.create'))->assertOk()->viewData('customerTerms');
        $this->assertSame(self::POINT.' · '.self::BRANCH, $terms[$this->shop->id]['location'] ?? null,
            '⛔ কাউন্টারের গ্রাহক-খোঁজায় "পয়েন্ট · শাখা" নেই (বা রুটের নাম)।');

        // ⓘ ভাউচার, নোট, চেক — সব পক্ষ-খোঁজার এক উৎস ([[PartyRegistry::forPicker()]]); জায়গা গোটা তালিকার এক প্রশ্নে
        $groups = [];
        $count = $this->placeQueries(function () use (&$groups) {
            $groups = app(PartyRegistry::class)->forPicker();
        });
        $types = count($groups);
        $option = collect(collect($groups)->firstWhere('type', Customer::drillSourceType())['options'] ?? [])->firstWhere('id', (int) $this->shop->id);
        $this->assertNotNull($option, 'দৃশ্যটাই ভুল — পক্ষ-খোঁজায় দোকানটা নেই।');
        $this->assertStringContainsString(self::POINT.' · '.self::BRANCH, $option['hint'], '⛔ পক্ষ-খোঁজার সারিতে "পয়েন্ট · শাখা" নেই।');
        $this->assertStringNotContainsString('রুট ৯', $option['hint'], '⛔ পক্ষ-খোঁজায় পয়েন্টের জায়গায় রুটের নাম।');
        // ⓘ ধরন প্রতি একটা জায়গার জোড়া (নিজের ধাপ + মা) আর একটা শাখার প্রশ্ন — সারি যতই হোক
        $this->assertLessThanOrEqual(2 * $types, $count['mdm_locations'], "⛔ পক্ষ-খোঁজা: {$types} ধরনে জায়গার {$count['mdm_locations']}টা প্রশ্ন — সারি ধরে।");
        $this->assertLessThanOrEqual($types, $count['branches'], "⛔ পক্ষ-খোঁজা: {$types} ধরনে শাখার {$count['branches']}টা প্রশ্ন — সারি ধরে।");
    }

    public function test_the_home_feed_and_the_dashboards_name_point_and_branch(): void
    {
        $bill = $this->bill();
        $full = $this->shop->nameWithPoint();

        $feed = collect(app(ActivityRegistry::class)->forUser($this->owner, 50));
        $row = $feed->first(fn ($h) => str_starts_with($h->title, $bill->document_no.' '));
        $this->assertNotNull($row, "দৃশ্যটাই ভুল — হোমের তালিকায় বিল {$bill->document_no} নেই।");
        $this->assertSame($full, $row->subtitle, '⛔ হোমের "সদ্য যা হলো"-তে "নাম · পয়েন্ট · শাখা" নেই।');

        $this->assertPlaceInRow((string) $this->get(route('module.dashboard', 'sales'))->assertOk()->getContent(),
            (string) $bill->document_no, 'বিক্রয় ড্যাশবোর্ডের বড় বকেয়া');

        $this->assertStringContainsString(e($full), (string) $this->get(route('module.dashboard', 'accounts'))->assertOk()->getContent(),
            '⛔ হিসাবের ড্যাশবোর্ডের "সবচেয়ে বড় বকেয়া"-তে "নাম · পয়েন্ট · শাখা" নেই।');
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    private function assertPlaceInRow(string $html, string $no, string $where): void
    {
        $rows = $this->rowsWith($html, $no);
        $this->assertNotSame([], array_filter($rows, fn (string $r) => str_contains($r, e(self::POINT)) && str_contains($r, e(self::BRANCH))),
            "⛔ {$where}: {$no}-এর সারিতে \"পয়েন্ট · শাখা\" নেই।");

        foreach ($rows as $r) {
            $this->assertStringNotContainsString('রুট ৯', $r, "⛔ {$where}: পয়েন্টের জায়গায় রুটের নাম।");
        }
    }

    private function rowWith(string $html, string $needle): string
    {
        return $this->rowsWith($html, $needle)[0];
    }

    /** @return list<string> লেখাটা যে যে `<tr>`-এ */
    private function rowsWith(string $html, string $needle): array
    {
        preg_match_all('~<tr\b(?:(?!</tr>).)*?'.preg_quote(e($needle), '~').'(?:(?!</tr>).)*</tr>~su', $html, $match);
        $this->assertNotSame([], $match[0], "দৃশ্যটাই ভুল — তালিকায় {$needle}-এর সারি নেই।");

        return $match[0];
    }

    /** @return array{customers: int, mdm_locations: int, branches: int} পাতা/কাজ চালাতে কোন টেবিলে কয়টা প্রশ্ন */
    private function placeQueries(callable $work): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $work();
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $count = ['customers' => 0, 'mdm_locations' => 0, 'branches' => 0];

        foreach ($log as $q) {
            foreach (array_keys($count) as $table) {
                if (preg_match('~\bfrom\s+[`"]?'.$table.'[`"]?(\s|$)~i', $q['query']) === 1) {
                    $count[$table]++;
                }
            }
        }

        return $count;
    }

    private function quotation(): \App\Modules\Sales\Models\SalesQuotation
    {
        return app(SalesQuotationService::class)->create([
            'customer_id' => $this->shop->id, 'trx_date' => now()->toDateString(),
            'valid_until' => now()->addDays(7)->toDateString(), 'header_discount' => '0',
        ], [['product_id' => $this->product->id, 'qty' => '1', 'rate' => '10']]);
    }

    /** কাউন্টারের আসল বিক্রি — বাকি থাকে, খাতায় ওঠে; ⓘ বড় অঙ্ক, যাতে দুই ড্যাশবোর্ডের "সবচেয়ে বড় বকেয়া"-য় ওঠে */
    private function bill(): SalesInvoice
    {
        return app(DirectSaleService::class)->complete(
            ['customer_id' => $this->shop->id, 'warehouse_id' => $this->warehouse->id, 'own_transport' => '1',
                DirectSaleService::REPEAT_FIELD => '1'],
            [['product_id' => $this->product->id, 'qty' => '10', 'rate' => '90000', 'free_qty' => '0']],
        )['invoice'];
    }

    /** তালিকা ভরাতে হালকা বিল — TheSalesListsNameThePointTest-এর একই */
    private function plainBill(string $no): SalesInvoice
    {
        return SalesInvoice::query()->create([
            'branch_id' => $this->shop->branch_id, 'document_no' => $no, 'customer_id' => $this->shop->id,
            'trx_date' => now()->toDateString(), 'due_on' => now()->addDays(30)->toDateString(),
            'subtotal' => '500', 'discount' => '0', 'tax' => '0', 'total' => '500', 'status' => DocumentStatus::CONFIRMED,
        ]);
    }

    private function place(string $code, string $name, string $level, ?Location $parent = null): Location
    {
        return Location::query()->create([
            'company_id' => $this->company->id, 'parent_id' => $parent?->id, 'code' => $code,
            'name_en' => $name, 'name_bn' => $name, 'level' => $level, 'is_active' => true,
        ]);
    }
}
