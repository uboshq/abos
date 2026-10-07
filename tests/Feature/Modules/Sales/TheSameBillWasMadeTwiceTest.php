<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\DirectSaleService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * একই বিল দুইবার — লাইভ, UB, ৪ অক্টোবর ২০২৬: ক্রেতা ৯৭-এর INV-0006 নিশ্চিত হলো ১৮:১০:১৪-এ (১১ পণ্য, ৪০,৫৯৯.১২), আর
 * ১৮:১১:৫৫-এ হুবহু একই কার্ট "খসড়া রাখুন"-এ DRF-0012। মালিক সেটা নিশ্চিত করতে যাচ্ছিলেন — হলে ক্রেতা দুইবার বিল পেতেন।
 * মালিকের নির্দেশ: *"এভাবে ডাবল যাতে না হয় সেই ব্যবস্থা করো"*।
 *
 * ⭐ দেয়াল ([[DirectSaleService::refuseARepeatBill()]]): একই ক্রেতা, শেষ N মিনিটে (`sales.duplicate_bill_minutes`, ডিফল্ট ৩০)
 * কাউন্টারের পাঠানো বিল, সারি (পণ্য, লট, পরিমাণ, ফ্রি, দর) আর মোট হুবহু এক — থামে, আগের নম্বর বলে; "আবার করুন" টিকে যায়,
 * অডিটে লেখা হয়।
 *
 * দাবি — একই মানুষ, একই কার্ট, দুইবার: দ্বিতীয়টা (খসড়া বা নিশ্চিত) থামে আর আগের নম্বর বলে; টিক দিলে যায়; ক্রেতা, এক
 * সারি, সময় বা সেটিং ভিন্ন হলে যায়; রাখা খসড়া নিজেকে আটকায় না; আর নিশ্চিতের পরে কাউন্টারের পাতা পুরনো কার্ট ফেরায় না।
 */
final class TheSameBillWasMadeTwiceTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private Customer $other;

    private Warehouse $warehouse;

    /** @var list<array{product: Product, lot: Batch}> */
    private array $goods = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
        app(CashTillService::class)->ensurePrimaryTill();

        $this->customer = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();
        $this->other = Customer::query()->whereKeyNot($this->customer->id)->where('name_en', '!=', 'Walk-in Customer')
            ->orderBy('id')->firstOrFail();

        foreach ([$this->customer, $this->other] as $buyer) {
            $buyer->forceFill(['credit_limit' => '1000000'])->save();
        }

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();

        foreach (Product::query()->orderBy('id')->limit(2)->get() as $i => $product) {
            $product->update(['track_batch' => true]);
            $lot = Batch::query()->create([
                'company_id' => $company->id, 'product_id' => $product->id,
                'batch_no' => 'LOT-TWICE-'.$i, 'expiry_date' => '2028-01-01',
            ]);
            app(StockService::class)->move(
                product: $product, warehouse: $this->warehouse, sourceType: 'opening', sourceId: $lot->id,
                floor: '500', date: now()->toDateString(), documentNo: 'TEST-TWICE-'.$i, batch: $lot,
            );
            $this->goods[] = ['product' => $product, 'lot' => $lot];
        }
    }

    public function test_a_kept_draft_of_a_bill_just_confirmed_is_refused_and_names_the_bill(): void
    {
        $first = $this->confirmed();

        $this->post(route('sales.direct.store'), [...$this->form(), 'save_as_draft' => '1'])
            ->assertSessionHasErrors(DirectSaleService::REPEAT_FIELD);

        $this->assertStringContainsString($first->document_no, $this->repeatError());
        $this->assertSame(1, $this->billsOf($this->customer), 'দ্বিতীয় কাগজটা (খসড়া) তবু লেখা হয়েছে।');
    }

    public function test_confirming_the_same_bill_again_is_refused_and_names_the_first_bill(): void
    {
        $first = $this->confirmed();

        // ⓘ সারির ক্রম উল্টো — ছাপ সাজানো, তাই একই বিল
        $this->post(route('sales.direct.store'), $this->form(reversed: true))
            ->assertSessionHasErrors(DirectSaleService::REPEAT_FIELD);

        $this->assertStringContainsString($first->document_no, $this->repeatError());
        $this->assertSame(1, $this->billsOf($this->customer), 'একই বিল দুইবার খাতায় বসেছে।');
    }

    public function test_the_tick_makes_the_same_bill_again_and_the_audit_names_the_first(): void
    {
        $first = $this->confirmed();

        $this->post(route('sales.direct.store'), [...$this->form(), DirectSaleService::REPEAT_FIELD => '1'])
            ->assertSessionHasNoErrors();

        $second = SalesInvoice::query()->latest('id')->firstOrFail();
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(DocumentStatus::CONFIRMED, $second->status);
        $this->assertSame(2, $this->billsOf($this->customer));

        $trail = DB::table('audit_trails')->where('auditable_type', SalesInvoice::class)
            ->where('auditable_id', $second->id)->where('action', DirectSaleService::REPEAT_AUDIT)->first();
        $this->assertNotNull($trail, 'জেনেশুনে করা একই বিল অডিটে নেই।');
        $this->assertTrue(
            DB::table('audit_field_changes')->where('audit_trail_id', $trail->id)->where('new_value', $first->document_no)->exists(),
            'অডিটে আগের বিলের নম্বর নেই।',
        );
    }

    public function test_another_customer_goes_through(): void
    {
        $this->confirmed();

        $this->post(route('sales.direct.store'), $this->form(customer: $this->other))->assertSessionHasNoErrors();

        $this->assertSame(1, $this->billsOf($this->other));
    }

    public function test_one_line_different_goes_through(): void
    {
        $this->confirmed();

        $this->post(route('sales.direct.store'), $this->form(firstQty: '11'))->assertSessionHasNoErrors();

        $this->assertSame(2, $this->billsOf($this->customer));
    }

    public function test_outside_the_window_goes_through_and_inside_it_still_stops(): void
    {
        $this->confirmed();

        // ⓘ একই মানুষ, একই কার্ট — ২৯ মিনিটে তখনো থামে (নইলে দাবিটা "কখনো থামে না" দিয়েও সবুজ হত)
        $this->travel(29)->minutes();
        $this->post(route('sales.direct.store'), $this->form())->assertSessionHasErrors(DirectSaleService::REPEAT_FIELD);

        $this->travel(2)->minutes();
        $this->post(route('sales.direct.store'), $this->form())->assertSessionHasNoErrors();

        $this->assertSame(2, $this->billsOf($this->customer));
    }

    public function test_the_setting_at_zero_turns_the_wall_off_and_back_on(): void
    {
        $this->confirmed();

        app(SettingsService::class)->set('sales.duplicate_bill_minutes', 0);
        $this->post(route('sales.direct.store'), $this->form())->assertSessionHasNoErrors();
        $this->assertSame(2, $this->billsOf($this->customer));

        // ⓘ একই মানুষ — সুইচ আবার চালু করলে তৃতীয়টা থামে
        app(SettingsService::class)->set('sales.duplicate_bill_minutes', 30);
        $this->post(route('sales.direct.store'), $this->form())->assertSessionHasErrors(DirectSaleService::REPEAT_FIELD);
        $this->assertSame(2, $this->billsOf($this->customer));
    }

    public function test_finishing_a_kept_draft_is_not_stopped_by_the_draft_itself(): void
    {
        $this->post(route('sales.direct.store'), [...$this->form(), 'save_as_draft' => '1'])->assertSessionHasNoErrors();
        $draft = SalesInvoice::query()->latest('id')->firstOrFail();
        $this->assertSame(DocumentStatus::DRAFT, $draft->status, 'প্রস্তুতিটাই ভুল — খসড়া রাখা হয়নি।');

        // ⓘ আবার রাখা (খুলে সংরক্ষণ), তারপর পাকা — দুইবারই নিজের বিরুদ্ধে নয়
        $this->post(route('sales.direct.store'), [...$this->form(), 'save_as_draft' => '1', 'resume_invoice_id' => $draft->id])
            ->assertSessionHasNoErrors();
        $this->post(route('sales.direct.store'), [...$this->form(), 'resume_invoice_id' => $draft->id])->assertSessionHasNoErrors();

        $this->assertSame(DocumentStatus::CONFIRMED, $draft->fresh()->status);
        $this->assertSame(1, $this->billsOf($this->customer));

        // ⭐ আর সেই পাকা খসড়ার পরে একই কার্ট — এবার থামে
        $this->post(route('sales.direct.store'), $this->form())->assertSessionHasErrors(DirectSaleService::REPEAT_FIELD);
    }

    /**
     * ⭐ পর্দার দিক — নিশ্চিতের পরে কাউন্টার খালি খোলে, আর থামলে কার্ট ফেরে টিকসহ (মালিক: "কনফার্মের পরে লিস্ট খালি")।
     */
    public function test_the_counter_opens_empty_after_a_confirm_and_offers_the_tick_only_when_stopped(): void
    {
        $first = $this->confirmed();

        $this->assertNull($first->fresh()->counter_draft, 'পাকা বিলে হাতের খসড়ার ছবি রয়ে গেছে।');

        $page = $this->get(route('sales.direct.create'))->assertOk();
        $page->assertSee('hasErrors: false', false);
        $page->assertDontSee('data-repeat-bill', false);

        $this->from(route('sales.direct.create'))
            ->followingRedirects()
            ->post(route('sales.direct.store'), $this->form())
            ->assertOk()
            ->assertSee('hasErrors: true', false)
            ->assertSee('data-repeat-bill', false)
            ->assertSee('name="confirm_duplicate"', false);
    }

    private function confirmed(): SalesInvoice
    {
        $this->post(route('sales.direct.store'), $this->form())->assertSessionHasNoErrors();
        $invoice = SalesInvoice::query()->latest('id')->firstOrFail();
        $this->assertSame(DocumentStatus::CONFIRMED, $invoice->status, 'প্রস্তুতিটাই ভুল — প্রথম বিল নিশ্চিত হয়নি।');

        return $invoice;
    }

    private function repeatError(): string
    {
        return (string) session('errors')->first(DirectSaleService::REPEAT_FIELD);
    }

    private function billsOf(Customer $customer): int
    {
        return SalesInvoice::query()->where('customer_id', $customer->id)
            ->where('status', '!=', DocumentStatus::CANCELLED)->count();
    }

    /** @return array<string, mixed> */
    private function form(?Customer $customer = null, bool $reversed = false, string $firstQty = '10'): array
    {
        $lines = [];

        foreach ($this->goods as $i => $item) {
            $lines[] = [
                'product_id' => $item['product']->id, 'batch_id' => $item['lot']->id,
                'qty' => $i === 0 ? $firstQty : '3', 'rate' => '100', 'free_qty' => '0',
            ];
        }

        return [
            'customer_id' => ($customer ?? $this->customer)->id,
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
            'payment_term' => 'credit',
            'own_transport' => '1',
            'lines' => $reversed ? array_reverse($lines) : $lines,
        ];
    }
}
