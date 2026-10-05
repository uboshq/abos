<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Engines\Dashboard\Breakdown;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MasterData\Models\ReasonCode;
use App\Modules\Sales\Dashboard\SalesDashboard;
use App\Modules\Sales\Models\Collection;
use App\Modules\Sales\Models\DeliveryOrder;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesInvoiceLine;
use App\Modules\Sales\Models\SalesTarget;
use App\Modules\Sales\Services\CollectionService;
use App\Modules\Sales\Services\DeliveryOrderService;
use App\Modules\Sales\Services\DirectSaleService;
use App\Modules\Sales\Services\SalesReturnService;
use App\Modules\Sales\Support\DeliveryOrderStatus;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * বিক্রয় ড্যাশবোর্ডের পুরো নকশা — মালিক, ৫ অক্টোবর ২০২৬ ([[SalesCharts]])।
 *
 * ⭐ প্রতিটা দাবি আসল কাগজ খাইয়ে, আগে-পরে মেপে, আর খাতার নিজের অঙ্কের সাথে মিলিয়ে:
 *   ১. টার্গেট — অর্জন বাড়ে ঠিক বিলের লাইনের (দাম − ভ্যাট) যোগফল, বাকি কমে ততটাই; লক্ষ্য = বসানো টার্গেটের যোগ
 *   ২. মোট লাভ — বিক্রয় বাড়ে বিলের `total`, ব্যয় বাড়ে বিলের `cost_of_goods`, লাভ তাদের ফারাক;
 *      শতাংশ = এ মাসের পাকা বিলের (মোট − ব্যয়) ÷ ব্যয়
 *   ৩. বিক্রেতা আর এরিয়া — যিনি বিল কাটলেন তাঁর দণ্ড বাড়ে বিলের মোট; এরিয়ার দণ্ডের যোগফলও
 *   ৪. ফানেল — খসড়া DO কিছু বাড়ায় না, সই পেরোনো DO এক বাড়ায়; খসড়া আদায় কিছু নয়, পাকা আদায় এক
 *   ৫. ফেরতের কারণ — "ক্ষতিগ্রস্ত" দণ্ড বাড়ে ঠিক ফেরতের মোট
 *   ৬. সুইচ বন্ধে নতুন চার্টের একটাও নেই — একই মালিক, একই কাগজ, চালু করলে আবার আছে
 */
final class TheSalesDashboardShowsTheWholeSpecTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Customer $customer;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        $this->customer = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->firstOrFail();

        config(['abos.dashboards_v2' => true]);
    }

    public function test_target_profit_seller_and_area_move_by_exactly_the_new_bill(): void
    {
        // ⓘ লক্ষ্যটা অনেক বড় — যাতে এই বিলে লক্ষ্য না ছাড়ায় আর "বাকি" শূন্যে আটকে না যায়
        SalesTarget::query()->forMonth(Carbon::today())->where('user_id', $this->owner->id)->delete();
        SalesTarget::query()->create([
            'user_id' => $this->owner->id,
            'month' => Carbon::today()->startOfMonth()->toDateString(),
            'amount' => '900000000',
        ]);
        $targetTotal = (string) SalesTarget::query()->forMonth(Carbon::today())->sum('amount');

        $target = $this->parts('target');
        $profit = $this->parts('profit');
        $seller = $this->parts('by_seller');
        $area = $this->parts('by_territory');

        $this->assertNotNull($target, '⛔ টার্গেট বসানো, অথচ টার্গেটের চার্ট নেই।');
        $this->assertStringContainsString(Money::format($targetTotal), $this->panel('target')->hint,
            '⛔ লেখায় লক্ষ্যটা বসানো টার্গেটের যোগফল নয়।');

        $invoice = $this->sell('5', '37');

        $lines = SalesInvoiceLine::query()->where('sales_invoice_id', $invoice->id)->get();
        $exVat = '0';
        foreach ($lines as $line) {
            $exVat = bcadd($exVat, bcsub((string) $line->amount, (string) $line->tax, 4), 4);
        }
        $this->assertSame(1, bccomp($exVat, '0', 4), 'প্রস্তুতিটাই ভুল — বিলের লাইনে টাকা নেই।');

        // ── ১ · টার্গেট ─────────────────────────────────────────────
        $after = $this->parts('target');
        $achieved = __('sales::dashboard.target_achieved');
        $remaining = __('sales::dashboard.target_remaining');
        $this->assertSame(Money::round(bcadd($target[$achieved], $exVat, 4)), Money::round($after[$achieved]),
            '⛔ অর্জন বিলের ভ্যাট-বাদ অঙ্ক ধরে বাড়েনি।');
        $this->assertSame(Money::round(bcsub($target[$remaining], $exVat, 4)), Money::round($after[$remaining]),
            '⛔ বাকি ঠিক ততটা কমেনি।');

        // ── ২ · মোট লাভ ─────────────────────────────────────────────
        $invoice->refresh();
        $this->assertSame(1, bccomp((string) $invoice->cost_of_goods, '0', 4),
            'প্রস্তুতিটাই ভুল — বিলে পণ্যের ব্যয় নেই, তাই ব্যয়ের দাবি কিছুই দেখত না।');

        $sold = __('sales::dashboard.profit_sold');
        $cost = __('sales::dashboard.profit_cost');
        $margin = __('sales::dashboard.profit_margin');
        $profitAfter = $this->parts('profit');
        $profit ??= [$sold => '0', $cost => '0', $margin => '0'];

        $this->assertSame(Money::round(bcadd($profit[$sold], (string) $invoice->total, 4)), Money::round($profitAfter[$sold]),
            '⛔ বিক্রয় বিলের মোট ধরে বাড়েনি।');
        $this->assertSame(Money::round(bcadd($profit[$cost], (string) $invoice->cost_of_goods, 4)), Money::round($profitAfter[$cost]),
            '⛔ ব্যয় বিলের cost_of_goods ধরে বাড়েনি।');
        $this->assertSame(Money::round(bcsub($profitAfter[$sold], $profitAfter[$cost], 4)), Money::round($profitAfter[$margin]),
            '⛔ মোট লাভ বিক্রয় − ব্যয় নয়।');

        // ⓘ শতাংশ খাতার নিজের অঙ্ক থেকে — এ মাসের পাকা বিল, ব্যয়ের উপর (ক্রয়ের মার্জিন কার্ডের নিয়ম)
        $books = SalesInvoice::query()->posted()
            ->whereBetween('trx_date', [Carbon::today()->startOfMonth()->toDateString(), Carbon::today()->endOfMonth()->toDateString()])
            ->selectRaw('COALESCE(SUM(total), 0) as sold, COALESCE(SUM(cost_of_goods), 0) as cost')->toBase()->first();
        $percent = Money::round(bcmul(bcdiv(bcsub((string) $books->sold, (string) $books->cost, 4), (string) $books->cost, 6), '100', 6), 2);
        $this->assertSame(Money::round($books->sold), Money::round($profitAfter[$sold]), '⛔ বিক্রয় খাতার পাকা বিলের যোগফল নয়।');
        $this->assertSame(__('sales::dashboard.profit_hint', ['percent' => $percent]), $this->panel('profit')->hint,
            '⛔ মার্জিনের শতাংশ খাতার অঙ্কের সাথে মেলে না।');

        // ── ৩ · বিক্রেতা আর এরিয়া ────────────────────────────────────
        $sellerAfter = $this->parts('by_seller');
        $this->assertSame(
            Money::round(bcadd($seller[$this->owner->name] ?? '0', (string) $invoice->total, 4)),
            Money::round($sellerAfter[$this->owner->name] ?? '0'),
            '⛔ বিল কাটা মানুষের দণ্ড বিলের মোট ধরে বাড়েনি।',
        );

        $areaAfter = $this->parts('by_territory');
        $this->assertLessThan(8, count($areaAfter), 'ⓘ এরিয়া আটের কম ধরে নেওয়া — নাহলে যোগফলের দাবি কাটা সারি দেখত না।');
        $this->assertSame(
            Money::round(bcadd($this->sum($area ?? []), (string) $invoice->total, 4)),
            Money::round($this->sum($areaAfter)),
            '⛔ এরিয়ার দণ্ডের যোগফল বিলের মোট ধরে বাড়েনি।',
        );
    }

    public function test_the_funnel_counts_an_approved_do_and_a_posted_collection_but_no_draft(): void
    {
        $invoice = $this->sell('2', '40');
        $before = $this->funnel();

        // ── DO: খসড়া কিছু নয়, সই পেরোলে এক ─────────────────────────────
        $do = app(DeliveryOrderService::class)->create(
            ['customer_id' => $this->customer->id, 'warehouse_id' => $this->warehouse->id],
            [['product_id' => $this->product->id, 'qty' => '1']],
            $this->owner,
        );
        $this->assertSame($before, $this->funnel(), '⛔ খসড়া DO ফানেলে গোনা হয়েছে।');

        $approvedBefore = $this->approvedDos();
        app(DeliveryOrderService::class)->markSupervisorApproved($do);
        $afterDo = $this->funnel();
        $this->assertSame($approvedBefore + 1, $this->approvedDos(), 'প্রস্তুতিটাই ভুল — DO অনুমোদিত হয়নি।');
        $this->assertSame($before[2] + 1, $afterDo[2], '⛔ সই পেরোনো DO ফানেলে এক বাড়েনি।');
        $this->assertSame([$before[0], $before[1], $before[3], $before[4], $before[5]],
            [$afterDo[0], $afterDo[1], $afterDo[3], $afterDo[4], $afterDo[5]], '⛔ DO-তে অন্য ধাপ বদলেছে।');

        // ── আদায়: খসড়া কিছু নয়, পাকা হলে এক — আর খাতার আদায়-কাগজের সংখ্যার সাথে মেলে ──────────
        $books = $this->collectionPapers();
        $due = bcsub((string) $invoice->total, (string) SalesInvoice::query()->withCollected()->findOrFail($invoice->id)->collected_total, 4);
        $this->assertSame(1, bccomp($due, '0', 4), 'প্রস্তুতিটাই ভুল — বিলটা আগেই পুরো শোধ।');

        $draft = app(CollectionService::class)->create(
            ['customer_id' => $this->customer->id, 'trx_date' => now()->toDateString(), 'amount' => $due],
            [['sales_invoice_id' => $invoice->id, 'amount' => $due]],
        );
        $this->assertSame($afterDo[5], $this->funnel()[5], '⛔ খসড়া আদায় ফানেলে গোনা হয়েছে।');

        app(CollectionService::class)->confirm($draft->fresh());
        $this->assertSame(DocumentStatus::CONFIRMED, $draft->fresh()->status, 'প্রস্তুতিটাই ভুল — আদায় পাকা হয়নি।');
        $this->assertSame($books + 1, $this->collectionPapers(), 'প্রস্তুতিটাই ভুল — খাতায় আদায়ের কাগজ বাড়েনি।');
        $this->assertSame($afterDo[5] + 1, $this->funnel()[5], '⛔ পাকা আদায় ফানেলে এক বাড়েনি।');
    }

    public function test_a_damaged_return_grows_its_reason_bar_by_the_return_total(): void
    {
        $invoice = $this->sell('4', '30')->load('lines');
        $damage = ReasonCode::query()->inContext(ReasonCode::SALES_RETURN)->where('code', 'DAMAGE')->firstOrFail();
        $before = $this->parts('return_reasons') ?? [];

        $service = app(SalesReturnService::class);
        $draft = $service->create(
            [
                'customer_id' => $this->customer->id,
                'warehouse_id' => $this->warehouse->id,
                'sales_invoice_id' => $invoice->id,
                'trx_date' => now()->toDateString(),
                'reason_code_id' => $damage->id,
            ],
            [[
                'product_id' => $this->product->id,
                'sales_invoice_line_id' => $invoice->lines->first()->id,
                'qty' => '1',
                'rate' => '30',
            ]],
        );
        $this->assertSame($before, $this->parts('return_reasons') ?? [], '⛔ খসড়া ফেরত কারণের চার্টে গোনা হয়েছে।');

        $return = $service->confirm($draft->fresh());
        $this->assertSame(1, bccomp((string) $return->fresh()->total, '0', 4), 'প্রস্তুতিটাই ভুল — ফেরতের মোট শূন্য।');

        $after = $this->parts('return_reasons');
        $name = $damage->name();
        $this->assertSame(
            Money::round(bcadd($before[$name] ?? '0', (string) $return->fresh()->total, 4)),
            Money::round($after[$name] ?? '0'),
            '⛔ "ক্ষতিগ্রস্ত" দণ্ড ফেরতের মোট ধরে বাড়েনি।',
        );
    }

    public function test_switch_off_hides_every_new_chart_for_the_same_owner_and_on_brings_them_back(): void
    {
        SalesTarget::query()->create([
            'user_id' => $this->owner->id,
            'month' => Carbon::today()->startOfMonth()->toDateString(),
            'amount' => '1000',
        ]);
        $this->sell('1', '50');

        $new = ['funnel', 'target', 'profit', 'by_territory', 'by_seller'];

        foreach ($new as $key) {
            $this->assertNotNull($this->panel($key), "প্রস্তুতিটাই ভুল — চালুতে {$key} নেই, তাই বন্ধের দাবি কিছুই দেখত না।");
        }

        config(['abos.dashboards_v2' => false]);
        foreach ([...$new, 'return_reasons'] as $key) {
            $this->assertNull($this->panel($key), "⛔ সুইচ বন্ধেও {$key} চার্টটা আছে।");
        }

        config(['abos.dashboards_v2' => true]);
        foreach ($new as $key) {
            $this->assertNotNull($this->panel($key), "⛔ আবার চালু করলে {$key} ফেরেনি।");
        }
    }

    // ── সাহায্য ─────────────────────────────────────────────────────────

    /** কাউন্টারের একটা পাকা বিক্রি, মালিকের হাতে */
    private function sell(string $qty, string $rate): SalesInvoice
    {
        $result = app(DirectSaleService::class)->complete(
            [
                'customer_id' => $this->customer->id,
                'warehouse_id' => $this->warehouse->id,
                'own_transport' => '1',
            ],
            [['product_id' => $this->product->id, 'qty' => $qty, 'rate' => $rate, 'free_qty' => '0']],
        );

        $invoice = $result['invoice']->fresh();
        $this->assertContains($invoice->status, DocumentStatus::POSTED, 'প্রস্তুতিটাই ভুল — বিক্রিটা পাকা হয়নি।');

        return $invoice;
    }

    private function panel(string $key): ?Breakdown
    {
        return collect(SalesDashboard::dashboard()->panels)
            ->first(fn ($p) => $p instanceof Breakdown && $p->label === __('sales::dashboard.'.$key));
    }

    /** @return array<string, string>|null ভাগের নাম → টাকা (কমা ছাড়া) */
    private function parts(string $key): ?array
    {
        $panel = $this->panel($key);

        return $panel === null ? null : collect($panel->parts)
            ->mapWithKeys(fn (array $p) => [$p['label'] => str_replace(',', '', $p['value'])])
            ->all();
    }

    /** @return list<int> উদ্ধৃতি, অর্ডার, DO, চালান, বিল, আদায় */
    private function funnel(): array
    {
        return array_map(fn (array $p) => (int) $p['value'], $this->panel('funnel')->parts);
    }

    /** @param array<string, string> $parts */
    private function sum(array $parts): string
    {
        return array_reduce($parts, fn (string $s, string $v) => bcadd($s, $v, 4), '0');
    }

    private function approvedDos(): int
    {
        return DeliveryOrder::query()
            ->whereBetween('trx_date', [Carbon::today()->startOfMonth()->toDateString(), Carbon::today()->toDateString()])
            ->whereNotIn('status', [DeliveryOrderStatus::DRAFT, DeliveryOrderStatus::SUBMITTED, DeliveryOrderStatus::SUPERVISOR_PENDING,
                DeliveryOrderStatus::REJECTED, DeliveryOrderStatus::CANCELLED])
            ->count();
    }

    /** খাতায় বসা আদায়ের কাগজ, এ মাসে — আদায় + গ্রাহকের রসিদ ভাউচার */
    private function collectionPapers(): int
    {
        $month = [Carbon::today()->startOfMonth()->toDateString(), Carbon::today()->toDateString()];

        return Collection::query()->posted()->whereBetween('trx_date', $month)->count()
            + Voucher::query()->where('type', Voucher::RECEIPT)->where('party_type', 'customer')->posted()->whereBetween('trx_date', $month)->count();
    }
}
