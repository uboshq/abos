<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Services\CustomerService;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MasterData\Models\Location;
use App\Modules\Sales\Services\DeliveryStage;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ⭐ বিক্রয় বিল আর চালানের তালিকা — কেন্দ্রীয় ছাঁকনি ইঞ্জিনে (মালিক, ১৪ নভেম্বর ২০২৬: "এইভাবে এই কাজটা ধরে ফেলো"; পরিকল্পনা §৫)।
 *
 * বিল ([[InvoiceListFilters]]):
 *   - "বাকি রয়েছে" কেবল যার বাকি আছে; "মেয়াদ পার" কেবল যার মেয়াদ পেরিয়েছে আর বাকি আছে; "পরিশোধিত" কেবল শোধ — বকেয়ার
 *     রিপোর্টের একই হিসাব ([[SalesInvoice::dueSql()]])
 *   - এলাকার ঘরে সংখ্যা আসে (এক কোয়েরিতে) — ⛔ আগে গ্রাহকের টেবিল জুড়তেই "status" দ্ব্যর্থ হয়ে পাতা ভেঙেছিল
 *   - ধাপের পটির পুরোনো একক লিংক `?stage=draft` আর `?from=…` চলে
 * চালান ([[ChallanListFilters]]):
 *   - "পৌঁছেছে" কেবল যার ডেলিভারির ধাপ লেখা; "পৌঁছানোর অপেক্ষায়" খাতায় বসা অথচ পৌঁছায়নি; উপরের ট্যাব ছাঁকনিতে হারায় না
 */
final class TheSalesListsFilterThroughTheEngineTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Location $north;

    private Location $south;

    /** @var array<string, int> */
    private array $bill = [];

    /** @var array<string, int> */
    private array $challan = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->north = $this->place('ZSN');
        $this->south = $this->place('ZSS');
        $northShop = $this->shop('Zsx North Shop', $this->north);
        $southShop = $this->shop('Zsx South Shop', $this->south);

        // ⓘ তিনটা পাকা বিল: বাকি (মেয়াদ আসেনি) · মেয়াদ পার · শোধ (টাকা শূন্য — বাকি নেই); আর একটা খসড়া
        $this->bill['owing'] = $this->invoice('ZSINV-OWING', $northShop, '5000', now()->addDays(10)->toDateString());
        $this->bill['late'] = $this->invoice('ZSINV-LATE', $southShop, '3000', now()->subDays(10)->toDateString());
        $this->bill['paid'] = $this->invoice('ZSINV-PAID', $northShop, '0', now()->subDays(10)->toDateString());
        $this->bill['draft'] = $this->invoice('ZSINV-DRAFT', $southShop, '700', null, DocumentStatus::DRAFT);

        $this->challan['arrived'] = $this->challanFor('ZSDC-ARRIVED', $northShop);
        $this->challan['waiting'] = $this->challanFor('ZSDC-WAITING', $southShop);
        DB::table('sal_delivery_states')->insert([
            'public_id' => (string) str()->uuid7(), 'company_id' => $this->company->id, 'delivery_challan_id' => $this->challan['arrived'],
            'stage' => DeliveryStage::DELIVERED, 'stage_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_the_payment_boxes_pick_the_right_bills(): void
    {
        $this->listShows('sales.invoice.index', ['pay' => ['due']], ['ZSINV-OWING', 'ZSINV-LATE'], ['ZSINV-PAID', 'ZSINV-DRAFT'],
            '"বাকি রয়েছে"-তে কেবল যার বাকি আছে');
        $this->listShows('sales.invoice.index', ['pay' => ['overdue']], ['ZSINV-LATE'], ['ZSINV-OWING', 'ZSINV-PAID'],
            '"মেয়াদ পার"-এ কেবল মেয়াদ পেরোনো বাকি');
        $this->listShows('sales.invoice.index', ['pay' => ['paid']], ['ZSINV-PAID'], ['ZSINV-OWING', 'ZSINV-LATE', 'ZSINV-DRAFT'],
            '"পরিশোধিত"-তে কেবল শোধ হওয়া পাকা বিল');
        $this->listShows('sales.invoice.index', ['stage' => 'draft'], ['ZSINV-DRAFT'], ['ZSINV-OWING'], 'ধাপের পটির পুরোনো একক লিংক');
        $this->listShows('sales.invoice.index', ['area' => [$this->south->id]], ['ZSINV-LATE', 'ZSINV-DRAFT'], ['ZSINV-OWING', 'ZSINV-PAID'],
            'দক্ষিণ এলাকা');
    }

    public function test_the_invoice_drawer_counts_places_and_the_late_card_counts_late_bills(): void
    {
        $response = $this->getJson(route('sales.invoice.index', ['q' => 'ZSINV', '_filter_counts' => 1]));
        $this->assertSame(200, $response->getStatusCode(), '⛔ বিলের তালিকার প্যানেল সংখ্যা চাইলে ভাঙল (৫০০) — এলাকার গোনায় দ্ব্যর্থ কলাম?');
        $counts = $response->json('counts');
        $this->assertSame(2, $counts['area'][(string) $this->north->id] ?? null, '⛔ উত্তরে দুটো বিল — এলাকার ঘরে অন্য সংখ্যা');
        $this->assertSame(2, $counts['area'][(string) $this->south->id] ?? null, '⛔ দক্ষিণে দুটো বিল — এলাকার ঘরে অন্য সংখ্যা');
        $this->assertSame(2, $counts['pay']['due'] ?? null, '⛔ "বাকি রয়েছে"-র পাশে ২ নয়');

        $html = (string) $this->get(route('sales.invoice.index', ['q' => 'ZSINV']))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/list-card-label">'.preg_quote(__('sales::filter.pay_overdue'), '/').'<(?:(?!list-card-label).)*?list-card-value num">'
            .preg_quote(__('sales::filter.bills', ['n' => '1']), '/').'</su', $html, '⛔ "মেয়াদ পার" কার্ডে একটা বিল দেখানোর কথা');
    }

    public function test_the_challan_delivery_boxes_split_arrived_from_waiting_and_keep_the_tab(): void
    {
        $this->listShows('sales.challan.index', ['delivery' => ['delivered']], ['ZSDC-ARRIVED'], ['ZSDC-WAITING'], '"পৌঁছেছে"');
        $this->listShows('sales.challan.index', ['delivery' => ['awaiting']], ['ZSDC-WAITING'], ['ZSDC-ARRIVED'], '"পৌঁছানোর অপেক্ষায়"');

        $html = (string) $this->get(route('sales.challan.index', ['tab' => 'awaiting', 'q' => 'ZSDC']))->assertOk()->getContent();
        // ⓘ খোঁজার ফর্মের ভেতরেই — নিচের "প্রতি পাতায়" ঘরের নিজের ফর্মেও লুকানো ট্যাব থাকে, সেটা খোঁজাকে বাঁচায় না
        $this->assertMatchesRegularExpression('/<form id="list-form"[^>]*>(?:(?!<\/form>).)*?<input type="hidden" name="tab" value="awaiting">/s', $html,
            '⛔ খুঁজলে বা ছাঁকনি দিলে উপরের ধাপের ট্যাব হারায়');
        $this->assertStringContainsString('ZSDC-WAITING', $html);
        $this->assertStringNotContainsString('ZSDC-ARRIVED', $html, '⛔ "অপেক্ষায়" ট্যাবে পৌঁছানো চালান');

        $json = $this->getJson(route('sales.challan.index', ['q' => 'ZSDC', '_filter_counts' => 1]))->assertOk()->json('counts');
        $this->assertSame(1, $json['delivery']['delivered'] ?? null, '⛔ "পৌঁছেছে"-র পাশে ১ নয়');
        $this->assertSame(1, $json['area'][(string) $this->south->id] ?? null, '⛔ চালানের এলাকার ঘরে অন্য সংখ্যা');
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  list<string>  $in
     * @param  list<string>  $out
     */
    private function listShows(string $route, array $query, array $in, array $out, string $why): void
    {
        $response = $this->get(route($route, $query));
        $this->assertSame(200, $response->getStatusCode(), "⛔ {$why}: পাতা ভাঙল");
        $html = (string) $response->getContent();

        foreach ($in as $no) {
            $this->assertStringContainsString($no, $html, "⛔ {$why}: {$no} থাকার কথা, নেই।");
        }

        foreach ($out as $no) {
            $this->assertStringNotContainsString($no, $html, "⛔ {$why}: {$no} বাদ পড়ার কথা, আছে।");
        }
    }

    private function place(string $code): Location
    {
        return Location::query()->create([
            'company_id' => $this->company->id, 'code' => $code, 'name_en' => $code.' Place', 'name_bn' => $code.' জায়গা',
            'level' => Location::TERRITORY, 'is_active' => true,
        ]);
    }

    private function shop(string $name, Location $where): Customer
    {
        $customer = app(CustomerService::class)->create(['name_en' => $name, 'name_bn' => $name, 'credit_limit' => '0']);
        $customer->forceFill(['location_id' => $where->id])->saveQuietly();

        return $customer;
    }

    private function invoice(string $no, Customer $customer, string $total, ?string $dueOn, string $status = DocumentStatus::CONFIRMED): int
    {
        return DB::table('sal_invoices')->insertGetId([
            'company_id' => $this->company->id, 'branch_id' => $this->company->defaultBranch()?->id, 'document_no' => $no,
            'customer_id' => $customer->id, 'trx_date' => now()->subDays(20)->toDateString(), 'due_on' => $dueOn,
            'status' => $status, 'subtotal' => $total, 'total' => $total, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function challanFor(string $no, Customer $customer): int
    {
        $warehouse = (int) Warehouse::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->orderBy('id')->value('id');

        return DB::table('sal_challans')->insertGetId([
            'company_id' => $this->company->id, 'branch_id' => $this->company->defaultBranch()?->id, 'document_no' => $no,
            'customer_id' => $customer->id, 'warehouse_id' => $warehouse, 'trx_date' => now()->toDateString(),
            'status' => DocumentStatus::CONFIRMED, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
