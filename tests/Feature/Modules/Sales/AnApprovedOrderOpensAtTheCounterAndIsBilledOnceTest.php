<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Services\PermissionSyncer;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Approval;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Approval\Services\OwnerSignsDiscounts;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\CounterSaleSources;
use App\Modules\Sales\Services\DirectSaleService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Tests\Feature\Modules\Sales\Fakes\FakeCounterSource;
use Tests\TestCase;

/**
 * হিসাবে অনুমোদিত কাগজ (DO) কাউন্টারে খোলে, আর একবারই বিল হয় — বিক্রয়ের কাজের ধারা, ২ অক্টোবর ২০২৬, ধাপ ঙ+চ।
 *
 * ── ⭐ মালিকের ধারা ─────────────────────────────────────────────────────
 * ডিপোর যাচাই: *"মজুদের বাধা দেখে কমাতে পারেন (বাকিটা ব্যাক অর্ডার), ⛔ অনুমোদিত পরিমাণের বেশি নয় … "নিশ্চিত" চাপলে
 * সরাসরি বিক্রয়ের পাতায় খোলে → খসড়া রাখা বা নিশ্চিত"*; নিশ্চিতে *"ইনভয়েস আর চালান একসাথে তৈরি"*, অবস্থা "বিল হয়েছে"।
 *
 * ── ⓘ কেন নকল উৎস ───────────────────────────────────────────────────
 * কাউন্টার কেবল চুক্তি চেনে ([[CounterSaleSource]]); নকলটা ([[FakeCounterSource]]) আসল দেয়াল (কোম্পানি, শাখা) নিয়ে
 * `sal_orders`-এ বসে, আর প্রতিটা "বিল হয়েছে" গোনে — তাই DeliveryOrder আসার আগেই সব দাবি প্রমাণ হয়।
 *
 * ⚠️ বিক্রি HTTP দিয়ে, কারণ অর্ধেক নিয়ম (যাচাই, ৪০৪, লুকানো ঘর) কেবল দরজায়; ⛔ প্রতিটা দাবি ডাটাবেসের অবস্থা মাপে —
 * কেবল ৩০২ নয়, কারণ যাচাই-ব্যর্থতাও ৩০২।
 */
final class AnApprovedOrderOpensAtTheCounterAndIsBilledOnceTest extends TestCase
{
    use RefreshDatabase;

    private const LINE = 9001;

    private Company $company;

    private User $owner;

    private Customer $customer;

    private Warehouse $warehouse;

    private Product $product;

    private Product $otherProduct;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        app(StandardChart::class)->install();
        app(CashTillService::class)->ensurePrimaryTill();

        $this->customer = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->orderBy('id')->firstOrFail();
        $this->otherProduct = Product::query()->whereKeyNot($this->product->id)->orderBy('id')->firstOrFail();

        /* ⓘ সীমা এখানে বিষয় নয় — সে যেন ভুল কারণে থামাতে না পারে */
        app(SettingsService::class)->set('customer.credit_limit_enabled', false);

        FakeCounterSource::$lines = [];
        FakeCounterSource::$marked = [];
        CounterSaleSources::extend('fake', FakeCounterSource::class);
    }

    protected function tearDown(): void
    {
        CounterSaleSources::forget('fake');
        FakeCounterSource::$lines = [];
        FakeCounterSource::$marked = [];

        parent::tearDown();
    }

    /**
     * ⭐ ঙ — তালিকার বোতাম (POST) উৎসকে ডিপো যাচাইয়ে তোলে আর কাউন্টারে পাঠায়; ⛔ পাতা (GET) কিছুই বদলায় না
     * (সমন্বয়ক, ৩ অক্টোবর ২০২৬)। পাতা খোলে উৎসের সারি ভরা, লুকানো ঘরে উৎস, মাথায় "… থেকে"।
     */
    public function test_the_list_button_opens_the_counter_with_the_source_lines_and_only_the_post_moves_it(): void
    {
        $source = $this->source();
        $page = route('sales.direct.create', ['source' => 'fake', 'source_id' => $source->id]);

        $this->get($page)->assertOk();
        $this->assertSame(FakeCounterSource::READY, $source->fresh()->status, '⛔ পাতা (GET) খুলতেই উৎসের অবস্থা বদলেছে।');

        $this->post(route('sales.direct.depot_check.open'), ['source' => 'fake', 'source_id' => $source->id])
            ->assertRedirect($page);
        $this->assertSame(FakeCounterSource::DEPOT, $source->fresh()->status, '⛔ "যাচাই করে খুলুন" চাপার পরেও উৎস ডিপো যাচাইয়ে ওঠেনি।');

        $page = $this->get($page);

        $page->assertOk();
        $page->assertViewHas('sourceResume', function (?array $screen) {
            $line = $screen['screen']['lines'][0] ?? [];

            return $screen !== null
                && $screen['invoiceId'] === ''
                && $screen['screen']['customerId'] === (string) $this->customer->id
                && count($screen['screen']['lines']) === 1
                && $line['id'] === (int) $this->product->id
                && $line['qty'] === '10'
                && $line['rate'] === '100'
                && $line['sourceLineId'] === self::LINE;
        });
        $page->assertSee('name="source_id" value="'.$source->id.'"', false);
        $page->assertSee($source->document_no);
    }

    /** ⭐ চ — নিশ্চিতে বিল আর চালান একসাথে পাকা, উৎস একবারই "বিল হয়েছে", সেই বিলে; কম দেওয়া চলে। */
    public function test_confirming_makes_the_bill_and_the_challan_together_and_marks_the_source_once(): void
    {
        $source = $this->source();

        $this->sell($source, [$this->line(qty: '8')])->assertSessionHasNoErrors();

        $invoice = SalesInvoice::query()->latest('id')->firstOrFail();

        $this->assertSame(DocumentStatus::CONFIRMED, $invoice->status, '⛔ বিলটা পাকা হয়নি।');
        $this->assertContains($this->challanOf($invoice)->status, DocumentStatus::POSTED, '⛔ চালানটা বিলের সাথে পাকা হয়নি।');
        $this->assertSame([['source' => $source->id, 'invoice' => $invoice->id]], FakeCounterSource::$marked,
            '⛔ উৎস "বিল হয়েছে" ঠিক একবার, এই বিলে — হয়নি।');
        $this->assertSame(FakeCounterSource::INVOICED, $source->fresh()->status);
        $this->assertSame('fake', $invoice->counter_source, '⛔ বিল মনে রাখেনি সে কোন উৎস থেকে।');
        $this->assertSame($source->id, $invoice->counter_source_id);
    }

    /**
     * ⛔ অনুমোদিতের বেশি নয় — উৎসের সারি ধরে মোট (সমন্বয়কের নিয়ম, ২ ও ৩ অক্টোবর ২০২৬)। পরিমাণে চার পথে: সারির
     * আইডিসহ, দুই ভাগে ভাগ করে, আইডি ছাড়া (পণ্য ধরে), আর উৎসে নেই এমন পণ্য; ফ্রিতে দুই পথে (আইডিসহ, আইডি ছাড়া)।
     * ⓘ প্রতিটা "না" উৎসের নিজের — বার্তায় DO-র নম্বর; অন্য কোনো দেয়ালের (যেমন ফ্রির অনুপাত) নয়।
     */
    public function test_more_than_approved_is_refused_every_way(): void
    {
        $source = $this->source();
        $before = SalesInvoice::query()->count();

        foreach ([
            'with the line id' => [$this->line(qty: '11')],
            'split in two' => [$this->line(qty: '6'), $this->line(qty: '6')],
            'without the line id' => [$this->line(qty: '11', sourceLine: null)],
            'a product not on it' => [$this->line(qty: '1', sourceLine: null, product: $this->otherProduct)],
            'free with the line id' => [$this->line(qty: '5', free: '3')],
            'free without the line id' => [$this->line(qty: '5', sourceLine: null, free: '3')],
        ] as $how => $lines) {
            $this->sell($source, $lines)->assertSessionHasErrors('lines');
            $this->assertStringContainsString($source->document_no, (string) session('errors')?->first('lines'),
                "⛔ {$how}: থামল, কিন্তু উৎসের দেয়ালে নয়।");

            $this->assertSame($before, SalesInvoice::query()->count(), "⛔ {$how}: অনুমোদিতের বাইরে বিল হয়ে গেছে।");
        }

        $this->assertSame([], FakeCounterSource::$marked);
        $this->assertNotSame(FakeCounterSource::INVOICED, $source->fresh()->status);
    }

    /** ⛔ আটকানো মাল DO-র গুদামে — অন্য গুদাম থেকে বিক্রি থামে (সমন্বয়ক, ৩ অক্টোবর ২০২৬)। */
    public function test_a_sale_from_another_warehouse_is_refused(): void
    {
        $source = $this->source();
        $other = Warehouse::query()->whereKeyNot($this->warehouse->id)->orderBy('id')->firstOrFail();
        $before = SalesInvoice::query()->count();

        $this->sell($source, [$this->line(qty: '5')], ['warehouse_id' => $other->id])->assertSessionHasErrors('warehouse_id');

        $this->assertStringContainsString($source->document_no, (string) session('errors')?->first('warehouse_id'));
        $this->assertSame($before, SalesInvoice::query()->count(), '⛔ অন্য গুদাম থেকে DO-র বিল হয়ে গেছে।');
        $this->assertSame([], FakeCounterSource::$marked);
    }

    /**
     * ⭐ উৎস থেকে আসা রাখা খসড়া বাতিল — উৎস ডিপো যাচাই থেকে আবার "হিসাবে অনুমোদিত"-এ, যদি উৎস `leaveDepotCheck()`
     * দেয় (চুক্তির বাইরে; সমন্বয়ক, ৩ অক্টোবর ২০২৬)।
     */
    public function test_discarding_a_parked_draft_puts_the_source_back(): void
    {
        $source = $this->source();
        $this->post(route('sales.direct.depot_check.open'), ['source' => 'fake', 'source_id' => $source->id]);
        $this->assertSame(FakeCounterSource::DEPOT, $source->fresh()->status, 'দৃশ্যটাই বানানো যায়নি — উৎস ডিপো যাচাইয়ে ওঠেনি।');

        $this->sell($source, [$this->line(qty: '7')], ['save_as_draft' => '1'])->assertSessionHasNoErrors();
        $draft = SalesInvoice::query()->latest('id')->firstOrFail();

        $this->post(route('sales.direct.discard', $draft), ['reason' => 'ক্রেতা আসেননি'])->assertSessionHasNoErrors();

        $this->assertSame(DocumentStatus::CANCELLED, $draft->fresh()->status);
        $this->assertSame(FakeCounterSource::READY, $source->fresh()->status, '⛔ খসড়া বাতিল হলো, অথচ উৎস ডিপো যাচাইয়েই আটকে রইল।');
    }

    /** ⛔ একটা উৎস একবারই বিল হয় — দ্বিতীয় বিক্রি থামে, কোনো বিল জন্মায় না। */
    public function test_a_second_sale_from_the_same_source_is_refused(): void
    {
        $source = $this->source();

        $this->sell($source, [$this->line(qty: '5')])->assertSessionHasNoErrors();
        $after = SalesInvoice::query()->count();

        $this->sell($source, [$this->line(qty: '5')])->assertSessionHasErrors('source');

        $this->assertSame($after, SalesInvoice::query()->count(), '⛔ একই উৎস থেকে দ্বিতীয় বিল হয়ে গেছে।');
        $this->assertCount(1, FakeCounterSource::$marked);
    }

    /**
     * ⭐ "খসড়া রাখুন" — খসড়া বিল উৎস মনে রাখে, উৎস তখনো বিল হয়নি; আবার খুললে সেই খসড়াই খোলে; পাকা করলে উৎস
     * "বিল হয়েছে", সেই একই বিলে — পর্দা উৎসের নাম আর না পাঠালেও।
     */
    public function test_a_parked_draft_marks_the_source_when_it_is_confirmed(): void
    {
        $source = $this->source();

        $this->sell($source, [$this->line(qty: '7')], ['save_as_draft' => '1'])->assertSessionHasNoErrors();
        $draft = SalesInvoice::query()->latest('id')->firstOrFail();

        $this->assertSame(DocumentStatus::DRAFT, $draft->status);
        $this->assertSame('fake', $draft->counter_source, '⛔ খসড়া মনে রাখেনি সে কোন উৎস থেকে।');
        $this->assertSame([], FakeCounterSource::$marked, '⛔ খসড়াতেই উৎস "বিল হয়েছে"।');

        $this->get(route('sales.direct.create', ['source' => 'fake', 'source_id' => $source->id]))
            ->assertRedirect(route('sales.direct.create', ['draft' => $draft->id]));

        $this->post(route('sales.direct.store'), [
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'resume_invoice_id' => $draft->id,
            'lines' => [$this->line(qty: '7')],
            'own_transport' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertSame(DocumentStatus::CONFIRMED, $draft->fresh()->status);
        $this->assertSame([['source' => $source->id, 'invoice' => $draft->id]], FakeCounterSource::$marked,
            '⛔ খসড়া পাকা হলো, অথচ উৎস "বিল হয়েছে" হয়নি — বা অন্য বিলে।');
    }

    /** ⭐ ছাড়ে মালিকের সই — বিক্রি সইয়ের অপেক্ষায়, উৎস তখনো খোলা; মালিক সই দিলে বিক্রি নিজে শেষ, আর উৎস তখনই "বিল হয়েছে"। */
    public function test_a_sale_held_for_the_owners_discount_signature_marks_the_source_when_signed(): void
    {
        app(OwnerSignsDiscounts::class)->ensure($this->company);
        $this->actingAs(User::query()->where('email', 'sales@abos.test')->firstOrFail());
        $source = $this->source();

        $result = app(DirectSaleService::class)->complete(
            ['customer_id' => $this->customer->id, 'warehouse_id' => $this->warehouse->id, 'deposit' => '0',
                'source' => 'fake', 'source_id' => $source->id],
            [$this->line(qty: '10', discount: '1')],
        );

        $this->assertTrue($result['discount_held'] ?? false, 'দৃশ্যটাই বানানো যায়নি — ছাড় সইয়ে যায়নি।');
        $this->assertSame([], FakeCounterSource::$marked, '⛔ সইয়ের আগেই উৎস "বিল হয়েছে"।');
        $this->assertSame('fake', $result['invoice']->fresh()->counter_source);

        $approval = Approval::query()->where('approvable_type', SalesInvoice::class)->where('approvable_id', $result['invoice']->id)
            ->where('action', 'discount')->where('status', Approval::PENDING)->firstOrFail();
        app(ApprovalEngine::class)->approve($approval, $this->owner);

        $this->assertSame(DocumentStatus::CONFIRMED, $result['invoice']->fresh()->status, '⛔ সইয়ের পরেও বিক্রি শেষ হয়নি।');
        $this->assertSame([['source' => $source->id, 'invoice' => $result['invoice']->id]], FakeCounterSource::$marked,
            '⛔ সইয়ের পরে বিক্রি শেষ হলো, অথচ উৎস "বিল হয়েছে" হয়নি।');
    }

    /** ⛔ অন্য কোম্পানির উৎস — পাতা ৪০৪, আর বিক্রিও থামে (উৎস "নেই")। */
    public function test_a_source_of_another_company_is_not_found(): void
    {
        $foreign = Company::query()->whereKeyNot($this->company->id)->orderBy('id')->firstOrFail();
        $source = $this->source(company: $foreign);

        $this->get(route('sales.direct.create', ['source' => 'fake', 'source_id' => $source->id]))->assertNotFound();

        $before = SalesInvoice::query()->count();
        $this->sell($source, [$this->line(qty: '1')])->assertSessionHasErrors('source');

        $this->assertSame($before, SalesInvoice::query()->count());
        $this->assertSame(FakeCounterSource::READY, FakeCounterSource::query()->withoutGlobalScopes()->find($source->id)?->status);
    }

    /** ⛔→⭐ ডিপোর যাচাইয়ের তালিকা — কাউন্টারের চাবিতে খোলে; একই মানুষের ভূমিকা থেকে চাবি তুললে ৪০৩। */
    public function test_the_depot_check_list_opens_only_with_the_counter_key(): void
    {
        $role = Role::query()->where('company_id', $this->company->id)->where('name', '!=', PermissionSyncer::SUPER_ADMIN_ROLE)
            ->whereHas('permissions', fn ($q) => $q->where('name', 'sales.challan.create'))
            ->orderBy('id')->firstOrFail();
        $user = User::factory()->create(['current_company_id' => $this->company->id]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);
        $user->assignRole($role);

        $this->actingAs($user->fresh())->get(route('sales.direct.depot_check'))->assertOk();

        $role->revokePermissionTo('sales.challan.create');

        $this->actingAs($user->fresh())->get(route('sales.direct.depot_check'))->assertForbidden();
    }

    // ── প্রস্তুতি ────────────────────────────────────────────────────────

    private function source(string $status = FakeCounterSource::READY, ?Company $company = null): FakeCounterSource
    {
        $company ??= $this->company;

        $source = FakeCounterSource::query()->withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'branch_id' => $company->defaultBranch()?->id,
            'document_no' => 'FAKE-'.random_int(1000, 9999),
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
            'status' => $status,
        ]);

        FakeCounterSource::$lines[$source->id] = [[
            'product_id' => (int) $this->product->id, 'qty' => '10.0000', 'free_qty' => '2.0000',
            'rate' => '100.0000', 'discount_percent' => '0.0000', 'source_line_id' => self::LINE,
        ]];

        return $source;
    }

    /** @return array<string, mixed> */
    private function line(string $qty, ?int $sourceLine = self::LINE, ?Product $product = null, string $discount = '0', string $free = '0'): array
    {
        return array_filter([
            'product_id' => ($product ?? $this->product)->id,
            'qty' => $qty,
            'free_qty' => $free,
            'rate' => '100',
            'discount_percent' => $discount,
            'source_line_id' => $sourceLine,
        ], fn ($v) => $v !== null);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, mixed>  $extra
     */
    private function sell(FakeCounterSource $source, array $lines, array $extra = []): TestResponse
    {
        return $this->post(route('sales.direct.store'), [
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'source' => 'fake',
            'source_id' => $source->id,
            'lines' => $lines,
            // ⓘ পরিবহন এখানে বিষয় নয় — "ক্রেতার নিজের"
            'own_transport' => '1',
            ...$extra,
        ]);
    }

    private function challanOf(SalesInvoice $invoice): DeliveryChallan
    {
        $id = $invoice->fresh()->load('lines.challanLine')->lines->first()?->challanLine?->delivery_challan_id;

        $this->assertNotNull($id, '⛔ বিলের সারি কোনো চালানে বাঁধা নয় — চালান আর বিল একসাথে হয়নি।');

        return DeliveryChallan::query()->findOrFail($id);
    }
}
