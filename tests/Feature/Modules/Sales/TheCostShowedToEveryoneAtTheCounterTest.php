<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Engines\Print\PaperSize;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\AuditTrail;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Services\DirectPurchaseService;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesInvoiceLine;
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Sales\Services\DirectSaleService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * ক্রয়মূল্য পর্দায় আসে কেবল `sales.cost.view` থাকলে — প্রতিটা দরজা আলাদা করে।
 *
 * ── ⓘ কী মাপা হয় ────────────────────────────────────────────────────
 * একটা নতুন পণ্য কেনা হয় ৩৭.৭৩ দরে (সরাসরি ক্রয়ের আসল পথে — স্তর আর
 * পণ্যের ক্রয়মূল্য দুইটাই ওই দর বয়ে নেয়), গুদামে বুঝে নেওয়া হয়, আর
 * কাউন্টারে তিনটা বেচা হয় ৫২.১০ দরে। ⓘ তাই বিলের বিক্রীত পণ্যের ব্যয়
 * ১১৩.১৯, আর মুনাফা ৪৩.১১ — তিনটা সংখ্যাই এমন যে অন্য কিছুর সাথে মিলবে না।
 *
 * ── ⭐ একই মানুষ, দুইবার ─────────────────────────────────────────────
 * প্রতিটা দরজায় একই কর্মী একই অনুরোধ দুইবার করেন: চাবি ছাড়া, তারপর
 * চাবিসহ। ⚠️ তফাত কেবল `sales.cost.view` — তাই "নেই" অংশটা অন্য চাবি
 * বা সদস্যপদের কারণে সবুজ হতে পারে না, আর "আছে" অংশটা প্রমাণ করে যে
 * খোঁজটা অন্ধ নয়।
 *
 * ⓘ কর্মীর হাতে `inventory.cost.view`-ও নেই — ওটা পণ্যের পর্দার চাবি,
 * আর ওটা থাকলে কোনো দরজা ভুল চাবি দেখে খুলছে কি না বোঝা যেত না।
 *
 * ── ⓘ যে দরজায় খরচ কখনো যায় না ──────────────────────────────────────
 * সেগুলোও আলাদা সারি: দুই অবস্থাতেই সংখ্যাটা নেই, আর পাতাটা যে সত্যিই
 * এই বিল/পণ্য দেখাচ্ছে তার একটা চিহ্ন (বিলের নম্বর, পণ্যের কোড) আছে —
 * ⚠️ নাহলে একটা খালি বা ভুল পাতাও "নেই" বলে সবুজ হত।
 */
final class TheCostShowedToEveryoneAtTheCounterTest extends TestCase
{
    use RefreshDatabase;

    private const PURCHASE_PRICE = '37.73';

    /** ৩ × ৩৭.৭৩ */
    private const COST_OF_GOODS = '113.19';

    /** ৩ × ৫২.১০ − ১১৩.১৯ */
    private const GROSS_PROFIT = '43.11';

    private const CODE = 'CVX-3773';

    private Company $company;

    private Product $product;

    private SalesInvoice $invoice;

    private DeliveryChallan $challan;

    /** শেষ যে ছাপার ছাঁচ আঁকা হলো — তার নাম আর ডেটা ([[PrintEngine::render()]])। */
    private ?array $printed = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();

        /*
         * ⓘ POS পর্দা কোম্পানির সুইচে বন্ধ থাকে (`sales.screen_pos`, ডিফল্ট বন্ধ) —
         * বন্ধ পর্দা ৪০৪ দেয় অনুমতির আগেই, তাই দরজাটা মাপতে আগে খুলতে হয়।
         */
        app(SettingsService::class)->set('sales.screen_pos', true);

        /*
         * ⓘ ছাপা এখন PDF — ভিতরের লেখা সংকুচিত আর ফন্টে বাঁধা, তাই সরাসরি খোঁজা যায় না.
         * ছাপার ছাঁচের ডেটা ধরে রাখা হয় (`paper` আর `profile` কেবল ছাপাতেই থাকে), আর
         * [[bodyOf()]] ওই ডেটা থেকেই HTML আঁকে — কাগজে যা যায়, ঠিক তাই মাপা হয়.
         */
        View::composer('*', function ($view): void {
            $data = $view->getData();

            if (($data['paper'] ?? null) instanceof PaperSize && array_key_exists('profile', $data)) {
                $this->printed ??= ['view' => $view->name(), 'data' => $data];
            }
        });

        /*
         * ⚠️ নতুন পণ্য, ডেমোর পণ্য নয় — ডেমোর পণ্যে আগের স্তর আছে, আর FIFO
         * সেগুলো আগে খরচ করত; তখন বিলের ব্যয় ৩৭.৭৩ থেকে আসত না।
         * ⓘ কর নেই, যাতে বিক্রয় = ৩ × ৫২.১০ আর মুনাফার অঙ্কটা নিশ্চিত।
         */
        $product = Product::query()->orderBy('id')->firstOrFail()->replicate(['public_id']);
        $product->code = self::CODE;
        $product->barcode = null;
        $product->name_en = 'Cost Probe';
        $product->name_bn = 'Cost Probe';
        $product->tax_id = null;
        $product->purchase_price = self::PURCHASE_PRICE;
        $product->sale_price = '52.10';
        $product->track_batch = false;
        $product->save();
        $this->product = $product;

        $warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();

        $bill = app(DirectPurchaseService::class)->complete(
            [
                'supplier_id' => Supplier::query()->firstOrFail()->id,
                'warehouse_id' => $warehouse->id,
                'trx_date' => now()->toDateString(),
                'supplier_bill_no' => 'CV-3773',
            ],
            [['product_id' => $product->id, 'qty' => '10', 'rate' => self::PURCHASE_PRICE, 'sales_price' => '52.10']],
        )['bill'];

        app(StockService::class)->place(
            product: $product,
            warehouse: $warehouse,
            qty: '10',
            sourceType: PurchaseBill::STOCK_SOURCE,
            sourceId: $bill->id,
        );

        $sale = app(DirectSaleService::class)->complete(
            [
                'customer_id' => Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail()->id,
                'warehouse_id' => $warehouse->id,
            ],
            [['product_id' => $product->id, 'qty' => '3', 'rate' => '52.10', 'free_qty' => '0']],
        );

        $this->invoice = $sale['invoice']->fresh(['lines']);
        $this->challan = $sale['challan'];

        /*
         * ⚠️ সংখ্যাগুলো সত্যিই তৈরি হয়েছে — নাহলে নিচের প্রতিটা "নেই" কিছু
         * না দেখেই সবুজ হত।
         */
        $this->assertSame(0, bccomp((string) $product->fresh()->purchase_price, self::PURCHASE_PRICE, 4),
            'প্রস্তুতিটাই ভুল — পণ্যের ক্রয়মূল্য ৩৭.৭৩ নয়।');
        $this->assertSame(0, bccomp((string) $this->invoice->cost_of_goods, self::COST_OF_GOODS, 4),
            'প্রস্তুতিটাই ভুল — বিলের বিক্রীত পণ্যের ব্যয় '.$this->invoice->cost_of_goods.', ১১৩.১৯ নয়।');
        $this->assertSame(0, bccomp((string) $this->invoice->lines->first()->unit_cost, self::PURCHASE_PRICE, 4),
            'প্রস্তুতিটাই ভুল — বিলের সারির একক ব্যয় ৩৭.৭৩ নয়।');
    }

    // ── চাবির পেছনের দরজা ───────────────────────────────────────────────

    /** @return array<string, array{string}> */
    public static function gatedDoors(): array
    {
        return [
            'direct sale counter page (catalogue cost)' => ['direct.create'],
            'invoice screen (cost of goods)' => ['invoice.show'],
            'report by product, screen' => ['report.by-product'],
            'report by product, CSV' => ['report.by-product.csv'],
            'report by customer, screen' => ['report.by-customer'],
            'report by customer, CSV' => ['report.by-customer.csv'],
            'report by brand, screen' => ['report.by-brand'],
            'report by brand, CSV' => ['report.by-brand.csv'],
            'audit entry of the invoice' => ['audit.show.invoice'],
            'audit entry of the invoice line' => ['audit.show.line'],
            'audit history of the invoice' => ['audit.record.invoice'],
            'audit time machine of the invoice' => ['audit.at.invoice'],
        ];
    }

    #[DataProvider('gatedDoors')]
    public function test_the_cost_reaches_this_door_only_with_its_key(string $door): void
    {
        $clerk = $this->clerk();

        [$url, $value, $canary] = $this->door($door);

        $without = $this->reach($clerk, $url);

        $this->assertStringContainsString($canary, $without,
            "⛔ [{$door}] চাবি ছাড়া পাতাটা এই কাগজ/পণ্যের নয় — \"নেই\" দাবিটা অন্ধ হত।");
        $this->assertStringNotContainsString($value, $without,
            "⛔ [{$door}] চাবি ছাড়াই ক্রয়মূল্য/ব্যয় ({$value}) পৌঁছেছে।");

        // ── একই মানুষ, এবার চাবিসহ ───────────────────────────────────
        $clerk->givePermissionTo('sales.cost.view');
        $clerk = $clerk->fresh();

        $with = $this->reach($clerk, $url);

        $this->assertStringContainsString($value, $with,
            "⛔ [{$door}] চাবি থাকা সত্ত্বেও সংখ্যাটা ({$value}) নেই — তাহলে উপরের \"নেই\" কিছুই প্রমাণ করে না।");
    }

    // ── যে দরজায় খরচ কখনো যায় না ────────────────────────────────────────

    /** @return array<string, array{string}> */
    public static function doorsWithoutCost(): array
    {
        return [
            'free-goods question (JSON)' => ['direct.free_allowed'],
            'POS counter page' => ['pos.index'],
            'POS barcode lookup (JSON)' => ['pos.lookup'],
            'POS bill lookup for returns (JSON)' => ['pos.bill'],
            'invoice print' => ['print.invoice'],
            'challan print' => ['print.challan'],
            'gate pass print' => ['print.gatepass'],
            'challan screen' => ['challan.show'],
            'invoice list' => ['invoice.index'],
            'new invoice form' => ['invoice.create'],
            'new challan form' => ['challan.create'],
            'new order form' => ['order.create'],
            'global search (JSON)' => ['search'],
        ];
    }

    #[DataProvider('doorsWithoutCost')]
    public function test_this_door_never_carries_the_cost(string $door): void
    {
        $clerk = $this->clerk();

        [$url, $canary] = $this->plainDoor($door);

        foreach ([false, true] as $keyed) {
            if ($keyed) {
                $clerk->givePermissionTo('sales.cost.view');
                $clerk = $clerk->fresh();
            }

            $body = $this->reach($clerk, $url);
            $state = $keyed ? 'চাবিসহ' : 'চাবি ছাড়া';

            $this->assertStringContainsString($canary, $body,
                "⛔ [{$door}] {$state}: পাতাটা এই কাগজ/পণ্যের নয় — \"নেই\" দাবিটা অন্ধ হত।");

            foreach ([self::PURCHASE_PRICE, self::COST_OF_GOODS] as $value) {
                $this->assertStringNotContainsString($value, $body,
                    "⛔ [{$door}] {$state}: খরচ ({$value}) এমন দরজায় পৌঁছেছে যেখানে তার কোনো কাজ নেই।");
            }
        }
    }

    // ── সাহায্যকারী ─────────────────────────────────────────────────────

    /**
     * কর্মী — `sales.cost.view` আর `inventory.cost.view` ছাড়া বাকি সব চাবি।
     */
    private function clerk(): User
    {
        foreach (['sales.cost.view', 'inventory.cost.view'] as $key) {
            Permission::findOrCreate($key, 'web');
        }

        $user = User::factory()->create();
        $user->companies()->attach($this->company, ['is_active' => true]);
        $user->forceFill(['current_company_id' => $this->company->id])->save();
        $user->givePermissionTo(
            Permission::query()->whereNotIn('name', ['sales.cost.view', 'inventory.cost.view'])->get(),
        );

        $user = $user->fresh();

        $this->assertFalse($user->can('sales.cost.view'), 'প্রস্তুতিটাই ভুল — চাবি না দিয়েও হাতে আছে।');

        return $user;
    }

    private function reach(User $user, string $url): string
    {
        $this->printed = null;

        $response = $this->actingAs($user)->get($url, ['Accept' => 'text/html,application/json']);

        $this->assertSame(200, $response->getStatusCode(),
            "দরজাটা খোলেনি ({$url}) — {$response->getStatusCode()}।");

        return $this->bodyOf($response);
    }

    private function bodyOf(TestResponse $response): string
    {
        if ($response->headers->get('Content-Type') === 'application/pdf') {
            $this->assertNotNull($this->printed, 'PDF এল, অথচ কোনো ছাপার ছাঁচ আঁকা হয়নি।');

            $previous = app()->getLocale();
            app()->setLocale($this->printed['data']['locale'] ?? $previous);

            try {
                return View::make($this->printed['view'], $this->printed['data'])->render();
            } finally {
                app()->setLocale($previous);
            }
        }

        $base = $response->baseResponse;

        if ($base instanceof \Symfony\Component\HttpFoundation\StreamedResponse) {
            return (string) $response->streamedContent();
        }

        return (string) $response->getContent();
    }

    /**
     * চাবির পেছনের দরজা: ঠিকানা, খোঁজার সংখ্যা, আর পাতাটা যে এই কাগজের তার চিহ্ন।
     *
     * @return array{string, string, string}
     */
    private function door(string $door): array
    {
        $report = fn (string $slug, bool $csv) => route('sales.report.show', array_filter([
            'slug' => $slug,
            'from' => now()->subDay()->toDateString(),
            'to' => now()->addDay()->toDateString(),
            'export' => $csv ? 'csv' : null,
        ]));

        $productLabel = __('sales::field.revenue');

        // ⓘ ক্রেতার নাম পাতার ভাষায় — কর্মীর ভাষা অ্যাপের ডিফল্ট (বাংলা); ইংরেজি 'Rahim' খুঁজলে চিহ্নটা অন্ধ হত
        $buyer = Customer::query()->findOrFail($this->invoice->customer_id)->name(config('app.locale'));

        return match ($door) {
            'direct.create' => [route('sales.direct.create'), self::PURCHASE_PRICE, self::CODE],
            'invoice.show' => [route('sales.invoice.show', $this->invoice), self::COST_OF_GOODS, (string) $this->invoice->document_no],
            'report.by-product' => [$report('by-product', false), self::COST_OF_GOODS, self::CODE],
            'report.by-product.csv' => [$report('by-product', true), self::COST_OF_GOODS, self::CODE],
            'report.by-customer' => [$report('by-customer', false), self::GROSS_PROFIT, $buyer],
            'report.by-customer.csv' => [$report('by-customer', true), self::GROSS_PROFIT, $buyer],
            'report.by-brand' => [$report('by-brand', false), self::COST_OF_GOODS, $productLabel],
            'report.by-brand.csv' => [$report('by-brand', true), self::COST_OF_GOODS, $productLabel],
            'audit.show.invoice' => [route('governance.audit.show', $this->trailOf(SalesInvoice::class, (int) $this->invoice->id, 'cost_of_goods')),
                self::COST_OF_GOODS, 'cost_of_goods'],
            'audit.show.line' => [route('governance.audit.show', $this->trailOf(SalesInvoiceLine::class, (int) $this->invoice->lines->first()->id, 'unit_cost')),
                self::PURCHASE_PRICE, 'unit_cost'],
            'audit.record.invoice' => [route('governance.audit.record', $this->trailOf(SalesInvoice::class, (int) $this->invoice->id, 'cost_of_goods')),
                self::COST_OF_GOODS, 'cost_of_goods'],
            'audit.at.invoice' => [route('governance.audit.at', [
                'trail' => $this->trailOf(SalesInvoice::class, (int) $this->invoice->id, 'cost_of_goods'),
                'on' => now()->toDateString(),
            ]), self::COST_OF_GOODS, 'cost_of_goods'],
        };
    }

    /** বিল-না-হওয়া একটা নিশ্চিত চালান — বিলের ফর্মের দরজা মাপতে (বিল হওয়া চালানে ফর্ম খোলে না)। */
    private function unbilledChallan(): DeliveryChallan
    {
        $challans = app(DeliveryChallanService::class);

        return $challans->confirm($challans->create([
            'customer_id' => $this->challan->customer_id,
            'warehouse_id' => $this->challan->warehouse_id,
            'trx_date' => now()->toDateString(),
            'own_transport' => true,
        ], [['product_id' => $this->product->id, 'delivered_qty' => '1', 'rate' => '52.10']]));
    }

    /**
     * খরচহীন দরজা: ঠিকানা আর পাতাটা যে এই কাগজ/পণ্যের তার চিহ্ন।
     *
     * @return array{string, string}
     */
    private function plainDoor(string $door): array
    {
        $no = (string) $this->invoice->document_no;

        return match ($door) {
            'direct.free_allowed' => [route('sales.direct.free_allowed', [
                'product_id' => $this->product->id, 'qty' => '3',
            ]), '"known":true'],
            'pos.index' => [route('sales.pos.index'), self::CODE],
            'pos.lookup' => [route('sales.pos.lookup', ['code' => self::CODE]), self::CODE],
            'pos.bill' => [route('sales.pos.bill', ['no' => $no]), $no],
            'print.invoice' => [route('sales.print.invoice', $this->invoice), $no],
            'print.challan' => [route('sales.print.challan', $this->challan), (string) $this->challan->document_no],
            'print.gatepass' => [route('sales.print.gatepass', $this->challan), (string) $this->challan->document_no],
            'challan.show' => [route('sales.challan.show', $this->challan), (string) $this->challan->document_no],
            'invoice.index' => [route('sales.invoice.index'), $no],
            // ⓘ ২১ সেপ্টেম্বর থেকে বিলের ফর্ম খোলে কেবল একটা নিশ্চিত চালান বেছে (e0f0c3a7)
            // ⓘ ২৯ সেপ্টেম্বর থেকে বিল হয়ে যাওয়া চালানে ফর্ম খোলে না, চালানে ফেরায় ([[ChallanBills]]) — তাই বিল-না-হওয়া চালান
            'invoice.create' => [route('sales.invoice.create', ['delivery_challan_id' => $this->unbilledChallan()->id]), self::CODE],
            'challan.create' => [route('sales.challan.create'), self::CODE],
            'order.create' => [route('sales.order.create'), self::CODE],
            'search' => [route('search', ['q' => $no]), $no],
        };
    }

    /** এই রেকর্ডের যে অডিট-সারিতে ঘরটা বদলেছে। */
    private function trailOf(string $type, int $id, string $field): int
    {
        $trail = AuditTrail::query()
            ->where('auditable_type', $type)
            ->where('auditable_id', $id)
            ->whereHas('changes', fn ($q) => $q->where('field', $field))
            ->latest('id')
            ->first();

        $this->assertNotNull($trail, "প্রস্তুতিটাই ভুল — {$type}#{$id}-এর `{$field}` বদলের কোনো অডিট-সারি নেই।");

        return (int) $trail->id;
    }
}
