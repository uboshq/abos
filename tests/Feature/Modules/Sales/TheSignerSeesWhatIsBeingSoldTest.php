<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Engines\Approval\HeldForApproval;
use App\Core\Support\CompanyContext;
use App\Core\Support\DateFormat;
use App\Core\Support\Money;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Services\PurchaseBillService;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Sales\Services\DirectSaleService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * সইকারী দেখেন কী বিক্রি হচ্ছে — কোন পণ্য, কত, কত ফ্রি, কোন দরে, আর কার কাছে।
 *
 * ── ⛔ কেন (২৮ সেপ্টেম্বর ২০২৬) ─────────────────────────────────────────
 * অনুমোদনের পাতায় ছিল কেবল পক্ষের নাম আর অঙ্ক। ⚠️ সইকারী হয় কাগজ খুঁজতে
 * যেতেন, নয় না দেখেই সই দিতেন — আর ফ্রি বা ইচ্ছাকৃত ছাড় ঠিক সারিতেই লুকায়।
 * ⭐ এখন কাগজ নিজে বলে ([[ShowsItselfForSigning]], [[SalesSigningSheet]]), আর
 * পাশে গ্রাহকের কার্ড ([[CustomerCardFacts]])।
 *
 * ── ⓘ তিনটা দাবি ────────────────────────────────────────────────────────
 * ⓵ চালানের সইয়ের পাতা — কাউন্টারের আসল বিক্রি ([[DirectSaleService::complete()]]),
 *    কারণ চালানের সারিতে ফ্রি কেবল ঐ পথেই বসে; লটের অনুপাতে ফ্রি থাকায়
 *    পণ্যটা আগে লটসহ কেনা হয় (DirectSaleTest-এর একই প্রস্তুতি)।
 * ⓶ ঐ একই বিক্রির বিল — বিলের সারিতে ফ্রি নেই, আসে চালানের সারি থেকে।
 * ⓷ আঁকা পাতা — অফিসের চালান `sales/challan` ছকে আটকায়
 *    ([[DeliveryChallanService::confirm()]] → `assertClear()`, লেনদেনের বাইরে,
 *    তাই অনুমোদনের সারি টিকে থাকে)। ⚠️ অফিসের চালান-সেবা ফ্রি জানে না
 *    (ইচ্ছাকৃত — DirectSaleService-এর মন্তব্য), তাই ফ্রিটা সারিতে বসানো হয়
 *    ঠিক যেভাবে কাউন্টার বসায় (`stampExtras()`: `$line->update(['free_qty' …])`)।
 *    ⓘ কাউন্টারের বিক্রি নিজে `sales/challan` ছকে আটকালে `complete()`-এর
 *    লেনদেন গোটা কাজটা ফিরিয়ে নেয় — সেই পথে পাতায় দেখানোর মতো সারি থাকে না।
 *
 * ⚠️ প্রত্যাশিত লেখা সবই `__()`/`Money::format()`/মডেলের পদ্ধতি থেকে — হাতে
 * লেখা বাংলা নয়। পণ্যের নাম লোকেল-নির্ভর, তাই পাতার দাবিতে প্রত্যাশা বানানো
 * হয় অনুরোধের **পরে**, একই লোকেলে।
 */
final class TheSignerSeesWhatIsBeingSoldTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Customer $customer;

    private Warehouse $warehouse;

    private Product $freeOne;

    private Product $plainOne;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs($this->owner);

        app(StandardChart::class)->install();
        app(CashTillService::class)->ensurePrimaryTill();

        $this->customer = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();

        $products = Product::query()->orderBy('id')->take(2)->get();
        $this->freeOne = $products->first();
        $this->plainOne = $products->last();
    }

    // ── ⓵ চালান ────────────────────────────────────────────────────────────

    public function test_the_challan_sheet_names_each_product_its_free_qty_rate_and_amount(): void
    {
        ['challan' => $challan] = $this->counterSale();

        $sheet = $challan->fresh()->signingSheet();

        $this->assertSame(['product', 'qty', 'free', 'rate', 'amount'], array_column($sheet['columns'], 'key'));
        $this->assertCount(2, $sheet['rows'], '⛔ চালানের দুইটা সারি, পাতায় অন্য সংখ্যা।');

        [$first, $second] = $sheet['rows'];

        $rateA = (string) $this->freeOne->sale_price;
        $rateB = (string) $this->plainOne->sale_price;

        $this->assertSame([
            'product' => $this->freeOne->code.' - '.$this->freeOne->name(),
            'qty' => Money::format('10'),
            'free' => Money::format('2'),
            'rate' => Money::format($rateA),
            'amount' => Money::format(bcmul('10', $rateA, 4)),
        ], $first, '⛔ ফ্রি-সহ সারিটা সইকারী ঠিকমতো দেখছেন না।');

        $this->assertSame([
            'product' => $this->plainOne->code.' - '.$this->plainOne->name(),
            'qty' => Money::format('5'),
            'free' => null,
            'rate' => Money::format($rateB),
            'amount' => Money::format(bcmul('5', $rateB, 4)),
        ], $second, '⛔ ফ্রি-ছাড়া সারিতে "ফ্রি" ঘর ফাঁকা থাকার কথা।');

        $this->assertSame(
            ['amount' => Money::format(bcadd(bcmul('10', $rateA, 4), bcmul('5', $rateB, 4), 4))],
            $sheet['totals'],
        );
        $this->assertSame(['type' => 'customer', 'id' => (int) $this->customer->id], $sheet['party']);
    }

    // ── ⓶ বিল ──────────────────────────────────────────────────────────────

    public function test_the_invoice_sheet_carries_the_free_qty_from_its_challan_line_and_the_due_date(): void
    {
        $due = now()->addDays(10)->toDateString();

        ['invoice' => $invoice] = $this->counterSale(['due_on' => $due]);

        $invoice = SalesInvoice::query()->findOrFail($invoice->id);
        $sheet = $invoice->signingSheet();

        $this->assertCount(2, $sheet['rows']);

        [$first, $second] = $sheet['rows'];

        $this->assertSame($this->freeOne->code.' - '.$this->freeOne->name(), $first['product']);
        $this->assertSame(Money::format('10'), $first['qty']);
        $this->assertSame(Money::format('2'), $first['free'],
            '⛔ বিলের সইয়ের পাতায় চালানের ফ্রি আসেনি — ফ্রি থাকে চালানের সারিতে।');
        $this->assertSame(Money::format((string) $this->freeOne->sale_price), $first['rate']);

        $this->assertSame($this->plainOne->code.' - '.$this->plainOne->name(), $second['product']);
        $this->assertNull($second['free']);

        $this->assertContains(
            ['label' => (string) __('sales::field.due_on'), 'value' => DateFormat::format(Carbon::parse($due))],
            $sheet['facts'],
            '⛔ বাকির শেষ তারিখ সইয়ের পাতায় নেই।',
        );
        $this->assertSame(['amount' => Money::format((string) $invoice->total)], $sheet['totals']);
        $this->assertSame(['type' => 'customer', 'id' => (int) $this->customer->id], $sheet['party']);
    }

    // ── ⓷ আঁকা পাতা ───────────────────────────────────────────────────────

    public function test_the_signer_page_shows_the_rows_the_free_qty_and_the_customer_card(): void
    {
        $signer = User::query()->create([
            'name' => 'Signing Manager',
            'email' => 'signer-'.uniqid().'@abos.test',
            'password' => Hash::make('secret-secret'),
            'is_active' => true,
        ]);
        $signer->companies()->attach($this->company->id, ['is_active' => true]);
        $signer->givePermissionTo(Permission::firstOrCreate(['name' => 'approval.decide', 'guard_name' => 'web']));

        $this->challanFlow($signer);

        $challan = app(DeliveryChallanService::class)->create(
            [
                'customer_id' => $this->customer->id,
                'warehouse_id' => $this->warehouse->id,
                'trx_date' => now()->toDateString(),
            ],
            [
                ['product_id' => $this->freeOne->id, 'delivered_qty' => '10', 'rate' => (string) $this->freeOne->sale_price],
                ['product_id' => $this->plainOne->id, 'delivered_qty' => '5', 'rate' => (string) $this->plainOne->sale_price],
            ],
        );

        // ⓘ ফ্রি বসে ঠিক যেভাবে কাউন্টার বসায় (DirectSaleService::stampExtras())
        $challan->lines()->orderBy('line_no')->firstOrFail()->update(['free_qty' => '2']);

        try {
            app(DeliveryChallanService::class)->confirm($challan->fresh(['lines']));
            $this->fail('⛔ দৃশ্যটাই বানানো যায়নি — চালানটা সইয়ে আটকানোর কথা।');
        } catch (HeldForApproval) {
            // প্রত্যাশিত
        }

        $approval = Approval::query()
            ->where('approvable_type', DeliveryChallan::class)
            ->where('approvable_id', $challan->id)
            ->where('status', Approval::PENDING)
            ->firstOrFail();

        $html = (string) $this->actingAs($signer)
            ->get(route('approval.inbox.show', $approval->id))
            ->assertOk()
            ->getContent();

        $at = strpos($html, 'data-signing-sheet');
        $this->assertNotFalse($at, '⛔ সইয়ের পাতাটাই আঁকা হয়নি।');
        $sheet = substr($html, (int) $at);

        $customer = $this->customer->fresh();

        foreach ([
            'product' => e($this->freeOne->code.' - '.$this->freeOne->name()),
            'other product' => e($this->plainOne->code.' - '.$this->plainOne->name()),
            'free' => Money::format('2'),
            'phone' => e((string) $customer->phone),
            'point' => e((string) $customer->location?->name()),
            'outstanding' => Money::format($customer->outstanding()),
            'outstanding label' => e((string) __('customer::field.outstanding')),
        ] as $what => $expected) {
            $this->assertNotSame('', trim($expected), "প্রস্তুতিটাই ভুল — '{$what}' খালি।");
            $this->assertTrue(str_contains($sheet, $expected),
                "⛔ সইয়ের পাতায় '{$what}' ({$expected}) নেই — সইকারী না দেখেই সই দেবেন।");
        }
    }

    /**
     * ⭐ ইনবক্সের সারি দেখেই বোঝা যায় কোন চালান — ২৯ সেপ্টেম্বর ২০২৬।
     *
     * ⛔ লাইভে: একই গ্রাহকের দুইটা চালান সইয়ে, আর তালিকায় দুই সারিই একরকম — নম্বর
     * কোথাও নেই, পক্ষ আর "কী বাবদ" ফাঁকা (abos-7c ধরেছেন)। সইকারী S-0008 না S-0009
     * বুঝতে প্রতিটা খুলে দেখতেন। ⓘ এখন প্রতিটা সারিতে নিজের নম্বর, গ্রাহক, আর প্রথম পণ্য।
     */
    public function test_each_inbox_row_names_its_own_challan_the_customer_and_the_goods(): void
    {
        $signer = User::query()->create([
            'name' => 'Row Reader',
            'email' => 'rows-'.uniqid().'@abos.test',
            'password' => Hash::make('secret-secret'),
            'is_active' => true,
        ]);
        $signer->companies()->attach($this->company->id, ['is_active' => true]);
        $signer->givePermissionTo(Permission::firstOrCreate(['name' => 'approval.decide', 'guard_name' => 'web']));

        $this->challanFlow($signer);

        $first = $this->heldChallan();
        $second = $this->heldChallan();

        $html = (string) $this->actingAs($signer)
            ->get(route('approval.inbox.index'))
            ->assertOk()
            ->getContent();

        $customer = e($this->customer->fresh()->name());
        $goods = e((string) __('approval::field.and_more', [
            'first' => $this->freeOne->name(),
            'count' => 1,
        ]));

        foreach ([$first, $second] as $challan) {
            $no = (string) $challan->document_no;
            // ⓘ ঘরের ভিতরে নম্বরটাই পুরো লেখা — চারপাশে ফাঁকা থাকতে পারে, অন্য লেখা নয়
            $at = preg_match('~>\s*'.preg_quote($no, '~').'\s*<~', $html, $m, PREG_OFFSET_CAPTURE) === 1 ? $m[0][1] : false;
            $this->assertNotFalse($at, "⛔ চালান {$no}-এর নম্বর ইনবক্সের কোনো সারিতে নেই।");

            // ⓘ নম্বরের সারিটাই — `<tr` থেকে `</tr>` পর্যন্ত; অন্য সারির নাম দিয়ে সবুজ হবে না
            $row = substr($html, (int) strrpos(substr($html, 0, (int) $at), '<tr'));
            $row = substr($row, 0, (int) strpos($row, '</tr>'));

            $this->assertStringContainsString($customer, $row, "⛔ {$no}-এর সারিতে গ্রাহকের নাম নেই।");
            $this->assertStringContainsString($goods, $row, "⛔ {$no}-এর সারিতে 'কী বাবদ' পণ্য বলে না।");
        }

        $this->assertNotSame((string) $first->document_no, (string) $second->document_no,
            'প্রস্তুতিটাই ভুল — দুইটা চালানের একই নম্বর।');
    }

    // ── যন্ত্রপাতি ─────────────────────────────────────────────────────────

    /** একটা চালান, দুই পণ্যের — নিশ্চিত করতে গিয়ে সইয়ে আটকে। */
    private function heldChallan(): DeliveryChallan
    {
        $challan = app(DeliveryChallanService::class)->create(
            [
                'customer_id' => $this->customer->id,
                'warehouse_id' => $this->warehouse->id,
                'trx_date' => now()->toDateString(),
            ],
            [
                ['product_id' => $this->freeOne->id, 'delivered_qty' => '10', 'rate' => (string) $this->freeOne->sale_price],
                ['product_id' => $this->plainOne->id, 'delivered_qty' => '5', 'rate' => (string) $this->plainOne->sale_price],
            ],
        );

        try {
            app(DeliveryChallanService::class)->confirm($challan->fresh(['lines']));
            $this->fail('⛔ দৃশ্যটাই বানানো যায়নি — চালানটা সইয়ে আটকানোর কথা।');
        } catch (HeldForApproval) {
            // প্রত্যাশিত
        }

        return $challan->fresh();
    }

    /**
     * কাউন্টারের বিক্রি — প্রথম পণ্য ১০টা, লট থেকে ২ ফ্রি; দ্বিতীয়টা ৫, ফ্রি নেই।
     *
     * @param  array<string, mixed>  $extra
     * @return array{challan: DeliveryChallan, invoice: SalesInvoice}
     */
    private function counterSale(array $extra = []): array
    {
        $lot = $this->receiveLotWithFree($this->freeOne);

        return app(DirectSaleService::class)->complete(
            [
                'customer_id' => $this->customer->id,
                'warehouse_id' => $this->warehouse->id,
                ...$extra,
            ],
            [
                ['product_id' => $this->freeOne->id, 'qty' => '10', 'free_qty' => '2',
                    'rate' => (string) $this->freeOne->sale_price, 'batch_id' => $lot->id],
                ['product_id' => $this->plainOne->id, 'qty' => '5', 'free_qty' => '0',
                    'rate' => (string) $this->plainOne->sale_price],
            ],
        );
    }

    /** DirectSaleTest::receiveLotWithFree() — ১০০ কেনা, ৫০ ফ্রি, লট ধরে। */
    private function receiveLotWithFree(Product $product): Batch
    {
        $product->forceFill(['track_batch' => true])->save();

        $bill = app(PurchaseBillService::class)->create(
            [
                'supplier_id' => Supplier::query()->value('id'),
                'warehouse_id' => $this->warehouse->id,
                'trx_date' => now()->toDateString(),
            ],
            [[
                'product_id' => $product->id,
                'qty' => '100',
                'free_qty' => '50',
                'rate' => '1',
                'batch_no' => 'SIGN-FREE-1',
                'expiry_date' => now()->addYear()->toDateString(),
            ]],
        );

        app(PurchaseBillService::class)->confirm($bill);

        $batch = Batch::query()->where('product_id', $product->id)->where('batch_no', 'SIGN-FREE-1')->firstOrFail();

        app(StockService::class)->place(
            product: $product,
            warehouse: $this->warehouse,
            qty: '100',
            sourceType: PurchaseBill::STOCK_SOURCE,
            sourceId: $bill->id,
            batch: $batch,
            freeQty: '50',
        );

        return $batch->refresh();
    }

    private function challanFlow(User $signer): void
    {
        $flow = ApprovalFlow::query()->create([
            'company_id' => $this->company->id,
            'module' => 'sales',
            'action' => 'challan',
            'document_type' => '',
            'threshold_amount' => null,
            'is_active' => true,
        ]);

        ApprovalFlowStep::query()->create([
            'approval_flow_id' => $flow->id,
            'level' => 1,
            'approver_type' => 'user',
            'approver_id' => $signer->id,
        ]);
    }
}
