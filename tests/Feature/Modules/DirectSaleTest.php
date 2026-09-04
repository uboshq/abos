<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Services\PurchaseBillService;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\DirectSaleService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * সরাসরি বিক্রয় — DMS-এর Direct Delivery Challan-এর সমতুল্য।
 *
 * ── এখানে যা প্রমাণ করা দরকার ─────────────────────────────────────────
 * এটা একটা দ্রুত পথ, আলাদা হিসাব নয়। এক চাপে চালান, বিল আর জমা — কিন্তু
 * দাখিলাগুলো ঠিক সেইগুলোই যেগুলো লম্বা পথে বসত। আলাদা হলে অমিলটা ধরা পড়ত
 * মাস শেষে।
 *
 * আর দ্বিতীয় দাবি: ফ্রি ও উপহার ফ্রি ভাণ্ডার থেকে যায়, বিক্রির মজুদ থেকে
 * নয় — নাহলে ফ্রি মালের ক্রয়মূল্য বিক্রির খরচে মিশে মুনাফা বেশি দেখাত।
 */
class DirectSaleTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    private Customer $customer;

    private Warehouse $warehouse;

    private Product $product;

    private Product $gift;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->user = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs($this->user);

        $this->customer = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();

        $products = Product::query()->orderBy('id')->take(2)->get();
        $this->product = $products->first();
        $this->gift = $products->last();
    }

    private function sales(): DirectSaleService
    {
        return app(DirectSaleService::class);
    }

    private function stock(): StockService
    {
        return app(StockService::class);
    }

    /**
     * ফ্রি ভাণ্ডারে কিছু মাল রাখা — প্রস্তুতকারকের পাঠানো।
     *
     * ── কেন এখন সত্যিকারের ক্রয় দিয়ে ────────────────────────────────
     * আগে এটা নিজেই `stock->move(free: …)` ডেকে ভাণ্ডারটা ভরে নিত,
     * `sourceType: 'test_free_receipt'` দিয়ে। তাতে নিচের পরীক্ষাগুলো
     * প্রমাণ করত "ফ্রি মাল ফ্রি ভাণ্ডার থেকে বেরোয়" — অথচ **ওই
     * ভাণ্ডারে মাল ঢোকার পথটাই বাস্তবে ছিল না**।
     *
     * অর্থাৎ জিনিসটা না থাকলেও পরীক্ষাগুলো পাশ করত, আর আসল দোকানে
     * ফ্রি দিতে গেলে বিক্রয়ই আটকে যেত। এখন মালটা ঢোকে যে পথে সত্যিই
     * ঢোকে — একটা ক্রয় বিলের ফ্রি পরিমাণ হয়ে।
     */
    private function receiveFree(Product $product, string $qty): void
    {
        $bill = app(PurchaseBillService::class)->create(
            [
                'supplier_id' => Supplier::query()->value('id'),
                'warehouse_id' => $this->warehouse->id,
                'trx_date' => now()->toDateString(),
            ],
            [['product_id' => $product->id, 'qty' => '1', 'free_qty' => $qty, 'rate' => '1']],
        );

        app(PurchaseBillService::class)->confirm($bill);

        /*
         * আর মালটা বুঝে নেওয়া হয় — Stock Placement, ৪ সেপ্টেম্বর ২০২৬।
         *
         * ⚠️ এই হেল্পারের নিজের ব্যাখ্যাই বলে সে *"মালটা ঢোকে যে পথে
         * সত্যিই ঢোকে"* — আর এখন সেই পথে একটা ধাপ বেড়েছে: গাড়ি থেকে
         * নামা আর গুদামে বুঝে নেওয়া এক ঘটনা নয়। ধাপটা বাদ দিলে ফ্রি
         * মাল ভাণ্ডারে ঢুকত না, আর নিচের বিক্রয়গুলো "ফ্রি মাল নেই"
         * বলে আটকাত।
         *
         * ⛔ লাইনটা তুলে দিলে পরীক্ষাগুলো লাল হবে — সেটাই প্রমাণ যে
         * ধাপটা সত্যিকারের।
         */
        app(StockService::class)->place(
            product: $product,
            warehouse: $this->warehouse,
            qty: '1',
            sourceType: PurchaseBill::STOCK_SOURCE,
            sourceId: $bill->id,
            freeQty: $qty,
        );
    }

    /**
     * নগদ কোথায় বসে — প্রধান নগদ কাউন্টারের খাতে, "হাতে নগদ" মাথায় নয়।
     *
     * ── এই পরীক্ষাগুলো আগে মাথাটাই দেখত ─────────────────────────────
     * ১১০১ একটা গ্রুপ, আর গ্রুপে বসানো সারি কোনো ব্যালেন্সে দেখায় না
     * (`Account::balanceOn()` গ্রুপের নিজের সারি গোনে না)। আদায় ঠিক
     * ওখানেই টাকা বসাত, আর এই পরীক্ষাগুলো সেটাকেই সঠিক বলে ধরে রাখত।
     *
     * এখন টাকা যায় প্রধান কাউন্টারে — কারও হেফাজতে, আর দিনশেষের
     * গণনায় মেলে।
     */
    private function cashTillAccount(): Account
    {
        return app(CashTillService::class)
            ->ensurePrimaryTill()->account;
    }

    private function balanceOfAccount(Account $account): string
    {
        return LedgerEntry::query()->where('account_id', $account->id)->get()->reduce(
            fn (string $sum, LedgerEntry $e) => bcadd($sum, bcsub((string) $e->debit, (string) $e->credit, 4), 4),
            '0',
        );
    }

    private function balanceOf(string $code): string
    {
        $account = Account::query()->where('code', $code)->firstOrFail();

        return LedgerEntry::query()->where('account_id', $account->id)->get()->reduce(
            fn (string $sum, LedgerEntry $e) => bcadd($sum, bcsub((string) $e->debit, (string) $e->credit, 4), 4),
            '0',
        );
    }

    /**
     * @param  array<string, mixed>  $extra
     * @param  list<array<string, mixed>>  $gifts
     * @return array{challan: DeliveryChallan, invoice: SalesInvoice, change: string}
     */
    private function sell(array $extra = [], array $gifts = [], string $qty = '10', string $rate = '100'): array
    {
        return $this->sales()->complete(
            [
                'customer_id' => $this->customer->id,
                'warehouse_id' => $this->warehouse->id,
                ...$extra,
            ],
            [['product_id' => $this->product->id, 'qty' => $qty, 'rate' => $rate,
                'free_qty' => $extra['free_qty'] ?? '0']],
            $gifts,
        );
    }

    // ── এক চাপে তিনটা কাগজ ──────────────────────────────────────────────

    /**
     * চালান, বিল আর জমা — একসাথে, আর দাখিলাগুলো লম্বা পথের মতোই।
     */
    public function test_one_press_makes_a_challan_an_invoice_and_a_collection(): void
    {
        $floorBefore = $this->stock()->floorQty($this->product, $this->warehouse);

        $result = $this->sell(['deposit' => '1000']);

        $this->assertSame(DocumentStatus::CONFIRMED, $result['challan']->status);
        $this->assertSame(DocumentStatus::CONFIRMED, $result['invoice']->status);

        // মাল বেরিয়েছে
        $this->assertSame(0, bccomp(
            $this->stock()->floorQty($this->product, $this->warehouse),
            bcsub($floorBefore, '10', 4),
            4,
        ));

        // আয়, আর টাকা এসে যাওয়ায় পাওনা শূন্য
        $this->assertSame(0, bccomp($this->balanceOf(StandardChart::SALES), '-1000', 4));
        $this->assertSame(0, bccomp($this->balanceOf(StandardChart::RECEIVABLE), '0', 4));
        $this->assertSame(0, bccomp($this->balanceOfAccount($this->cashTillAccount()), '1000', 4));

        // বিক্রীত পণ্যের ব্যয়ও বসেছে — লম্বা পথের মতোই
        $cost = bcmul('10', (string) $this->product->purchase_price, 4);
        $this->assertSame(0, bccomp($this->balanceOf(StandardChart::COST_OF_GOODS_SOLD), $cost, 4));
    }

    /**
     * বিলের লাইন চালানের লাইনের সাথে বাঁধা।
     *
     * না বাঁধলে "মাল গেছে, বিল হয়নি" রিপোর্টটা এই বিক্রিগুলোকে চিরকাল
     * বাকি দেখাত — অথচ বিল ওই মুহূর্তেই কাটা হয়েছে।
     */
    public function test_the_invoice_lines_point_at_the_challan_lines(): void
    {
        $result = $this->sell();

        $line = $result['invoice']->lines->first();

        $this->assertNotNull($line->delivery_challan_line_id);
        $this->assertSame(
            $result['challan']->lines->first()->id,
            $line->delivery_challan_line_id,
        );
    }

    /** মাল গেছে-বিল হয়নি রিপোর্টে সরাসরি বিক্রি ঝুলে থাকে না। */
    public function test_a_direct_sale_never_shows_as_uninvoiced(): void
    {
        $this->sell();

        $result = app(ReportEngine::class)->run('sales.uninvoiced', [
            'from' => now()->subYear()->toDateString(),
            'to' => now()->addDay()->toDateString(),
        ]);

        $this->assertCount(0, $result->rows);
    }

    // ── ফ্রি ও উপহার ────────────────────────────────────────────────────

    /**
     * ফ্রি পরিমাণ ফ্রি ভাণ্ডার থেকে কাটে, বিক্রির মজুদ থেকে নয়।
     */
    public function test_free_quantity_comes_out_of_the_free_pool(): void
    {
        $this->receiveFree($this->product, '50');

        $floorBefore = $this->stock()->floorQty($this->product, $this->warehouse);
        $freeBefore = $this->stock()->freeQty($this->product, $this->warehouse);

        $this->sell(['free_qty' => '3']);

        $this->assertSame(0, bccomp(
            $this->stock()->floorQty($this->product, $this->warehouse),
            bcsub($floorBefore, '10', 4),
            4,
        ), 'বিক্রির মজুদ থেকে কেবল বিক্রির পরিমাণ');

        $this->assertSame(0, bccomp(
            $this->stock()->freeQty($this->product, $this->warehouse),
            bcsub($freeBefore, '3', 4),
            4,
        ), 'ফ্রি পরিমাণ ফ্রি ভাণ্ডার থেকে');
    }

    /**
     * ফ্রি পরিমাণ বিলে টাকা হয়ে বসে না।
     *
     * বসলে গ্রাহক ভাবতেন ফ্রি মালের জন্যও টাকা নেওয়া হয়েছে — আর সেটাই
     * কাউন্টারে সবচেয়ে বেশি ঝগড়ার কারণ।
     */
    public function test_free_quantity_is_not_charged_for(): void
    {
        $this->receiveFree($this->product, '50');

        $result = $this->sell(['free_qty' => '3']);

        // ১০ × ১০০ = ১,০০০ — ফ্রি তিনটার কোনো দাম নেই
        $this->assertSame(0, bccomp((string) $result['invoice']->total, '1000', 4));
        $this->assertCount(1, $result['invoice']->lines);
    }

    /**
     * উপহার অন্য পণ্য, আর সেটাও ফ্রি ভাণ্ডার থেকে।
     */
    public function test_a_gift_is_a_different_product_from_the_free_pool(): void
    {
        $this->receiveFree($this->gift, '40');

        $freeBefore = $this->stock()->freeQty($this->gift, $this->warehouse);

        $result = $this->sell([], [[
            'product_id' => $this->gift->id,
            'against_product_id' => $this->product->id,
            'qty' => '2',
            'remarks' => 'বিক্রয়ের জন্য নয়',
        ]]);

        $this->assertCount(1, $result['challan']->giftLines);

        $this->assertSame(0, bccomp(
            $this->stock()->freeQty($this->gift, $this->warehouse),
            bcsub($freeBefore, '2', 4),
            4,
        ));

        // উপহার বিলে যোগ হয় না
        $this->assertSame(0, bccomp((string) $result['invoice']->total, '1000', 4));
    }

    /** উপহার কোন পণ্যের সাথে গেল তা লেখা থাকে। */
    public function test_a_gift_records_what_it_was_given_against(): void
    {
        $this->receiveFree($this->gift, '40');

        $result = $this->sell([], [[
            'product_id' => $this->gift->id,
            'against_product_id' => $this->product->id,
            'qty' => '1',
        ]]);

        $this->assertSame(
            $this->product->id,
            (int) $result['challan']->giftLines->first()->against_product_id,
        );
    }

    /**
     * যে ফ্রি মাল নেই তা দেওয়া যায় না — বিক্রির মজুদ ভরা থাকলেও।
     */
    public function test_a_gift_without_free_stock_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        $this->sell([], [[
            'product_id' => $this->gift->id,
            'against_product_id' => $this->product->id,
            'qty' => '5',
        ]]);
    }

    // ── কাগজের নিচের ঘরগুলো ─────────────────────────────────────────────

    /** DO নম্বর, খরচ, রাউন্ডিং আর জমা চালানে লেখা থাকে। */
    public function test_the_document_carries_the_counter_figures(): void
    {
        $result = $this->sell([
            'do_no' => 'DO-8821',
            'expense_amount' => '50',
            'rounding_amount' => '2',
            'deposit' => '600',
            'credit_period_days' => 7,
        ]);

        $challan = $result['challan']->fresh();

        $this->assertSame('DO-8821', $challan->do_no);
        $this->assertSame(0, bccomp((string) $challan->expense_amount, '50', 4));
        $this->assertSame(0, bccomp((string) $challan->rounding_amount, '2', 4));
        $this->assertSame(0, bccomp((string) $challan->deposit_amount, '600', 4));
        $this->assertSame(7, (int) $challan->credit_period_days);
    }

    /**
     * আংশিক জমা দিলে বাকিটা গ্রাহকের নামে পাওনা থাকে।
     */
    public function test_a_part_deposit_leaves_the_rest_outstanding(): void
    {
        $result = $this->sell(['deposit' => '400']);

        $this->assertSame(0, bccomp($result['invoice']->fresh()->dueAmount(), '600', 4));
        $this->assertSame(0, bccomp($this->balanceOf(StandardChart::RECEIVABLE), '600', 4));
    }

    /** বেশি জমা দিলে ফেরত হিসাব হয়, কিন্তু খাতায় বসে না। */
    public function test_change_is_worked_out_but_never_posted(): void
    {
        $result = $this->sell(['deposit' => '1500']);

        /*
         * ⚠️ দাবিটা উল্টে গেছে, আর সেটাই সংশোধন — ৪ সেপ্টেম্বর ২০২৬।
         *
         * এই পরীক্ষাটার পুরনো নাম ছিল সৎ: *"ফেরত হিসাব হয়, কিন্তু বসে
         * না"*। ⚠️ কিন্তু ওটা একটা **বাগকেই পাহারা দিচ্ছিল**: বিলের চেয়ে
         * বেশি টাকা নিলে উদ্বৃত্তটা **কোনো কাগজেই লেখা হত না** — ক্যাশিয়ার
         * হাতে নিয়েছেন, খাতা জানে না। মালিক ধরেন ৬০,৫৬৫ টাকার বিলে
         * ৫৬ লাখ বসিয়ে: পর্দা বলল "বকেয়া ০", আর বাকিটা উধাও।
         *
         * ── এখন ────────────────────────────────────────────────────
         * পুরো জমাটা আদায়ের কাগজে বসে; কেবল **বিলে বরাদ্দটা** সীমিত।
         * উদ্বৃত্ত গ্রাহকের খাতায় **অগ্রিম** — ডিপোর স্বাভাবিক ঘটনা।
         *
         * ⓘ তাই ড্রয়ারে এখন ১,৫০০, আর গ্রাহকের হিসাব ৫০০ ঋণাত্মক।
         */
        $this->assertSame(0, bccomp($result['change'], '500', 4));
        $this->assertSame(0, bccomp($this->balanceOfAccount($this->cashTillAccount()), '1500', 4),
            'পুরো জমাটা টাকার খাতে বসেনি — উদ্বৃত্ত আবার হারাচ্ছে।');
        $this->assertSame(0, bccomp((string) $this->customer->fresh()->outstanding(), '-500', 4),
            'উদ্বৃত্তটা গ্রাহকের অগ্রিম হিসেবে দাঁড়ায়নি।');
    }

    /**
     * বাকির মেয়াদ না বললে গ্রাহকের নিজের মেয়াদ।
     *
     * শূন্য আর "বলা হয়নি" এক নয় — শূন্য মানে আজই দিতে হবে।
     */
    public function test_the_due_date_falls_back_to_the_customer_terms(): void
    {
        $result = $this->sell();

        $this->assertSame(
            now()->addDays((int) $this->customer->credit_days)->toDateString(),
            $result['invoice']->fresh()->due_on?->toDateString(),
        );
    }

    // ── পর্দা ও অনুমতি ──────────────────────────────────────────────────

    public function test_the_screen_carries_every_stock_figure(): void
    {
        $response = $this->actingAs($this->user)->get(route('sales.direct.create'))->assertOk();

        $product = $response->viewData('products')->first();

        // নমুনা দাবি করে Main / Free / Reserved / Available সরাসরি দেখা যাবে
        foreach (['main', 'reserved', 'available', 'free', 'free_available'] as $figure) {
            $this->assertObjectHasProperty($figure, $product, "মজুদের ঘর নেই: {$figure}");
        }
    }

    public function test_selling_through_the_screen_ends_at_the_receipt(): void
    {
        $response = $this->actingAs($this->user)->post(route('sales.direct.store'), [
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'deposit' => '500',
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => '4', 'rate' => '125'],
            ],
        ]);

        $invoice = SalesInvoice::query()->latest('id')->firstOrFail();

        $response->assertRedirect(route('sales.print.invoice', [
            'invoice' => $invoice->id,
            'paper' => '80mm',
        ]));

        $this->assertSame(0, bccomp((string) $invoice->total, '500', 4));
    }

    /**
     * প্রতিটা ঘরের সুইচ সত্যিই ঘরটা লুকায়।
     *
     * ── কেন এই পরীক্ষাটা ঘোষণা গুনে থামে না ──────────────────────────
     * সুইচ ঘোষণা করা সহজ, ভিউতে সেটা মানা আলাদা কাজ। ঘোষিত অথচ না-মানা
     * সুইচ সবচেয়ে খারাপ ধরনের: Control Panel-এ বন্ধ করে ব্যবহারকারী ভাবেন
     * কাজ হয়েছে, অথচ পর্দায় ঘরটা রয়েই যায়।
     *
     * তাই প্রতিটা সুইচ বন্ধ করে পাতাটা এঁকে দেখা হয় লেখাটা সত্যিই গেল
     * কি না।
     */
    public function test_every_field_switch_really_hides_its_field(): void
    {
        $settings = app(SettingsService::class);

        $cases = [
            'sales.field_do_no' => __('sales::field.do_no'),
            'sales.field_free_qty' => __('sales::field.free_qty'),
            'sales.field_gift' => __('sales::field.gift_item'),
            /*
             * ⚠️ লেবেলটা বদলেছে — ৩ সেপ্টেম্বর ২০২৬ কাউন্টারের পর্দা নতুন
             * করে সাজানোর সময়।
             *
             * লাইনের ছাড় এখন "এই লাইন" প্যানেলে বসে, আর ঘরটা টাকা বা
             * শতাংশ **দুইটাই** নেয় — তাই "ছাড় %" নামটা আর সত্যি ছিল না,
             * নামটা এখন শুধু "ছাড়"। ⓘ পাহারাটা লেবেল ধরে খোঁজে, তাই
             * পুরনো চাবিটা আর কোথাও মিলত না।
             */
            'sales.field_line_discount' => __('sales::field.line_discount'),
            'sales.field_expense' => __('sales::field.expense'),
            'sales.field_rounding' => __('sales::field.rounding'),
            'sales.field_deposit' => __('sales::field.received_deposit'),
            /*
             * ⚠️ লেবেলটা বদলেছে — ৩ সেপ্টেম্বর ২০২৬, মালিকের নির্দেশে।
             *
             * ছাপা **সীমা** কাউন্টারে কোনো প্রশ্নের উত্তর দিত না; বিক্রেতার
             * প্রশ্ন একটাই — *"এই পার্টিকে আর কত বাকিতে দেওয়া যাবে?"* — আর
             * সেটা সীমা নয়, **সীমা বিয়োগ যা ইতিমধ্যে পাওনা**। সুইচটা একই,
             * কেবল ঘরটার নাম আর অর্থ বদলেছে।
             */
            'sales.field_credit_limit' => __('sales::field.available_credit'),
            /*
             * ⚠️ `sales.field_warehouse_select` এখানে আর নেই — ৩ সেপ্টেম্বর ২০২৬।
             *
             * মালিক গুদামের ঘরটা কাউন্টার থেকে তুলে দিতে বলেছেন, আর
             * কারণটা তাঁর নিজের ভাষায়: **"বিল একটাই হয়, গুদামে গুদামে
             * বিল হয় না"**। কাগজটা এক গুদাম থেকেই বেরোয়; মাল অন্য
             * গুদামে থাকলে আগে স্টক স্থানান্তর, তারপর বিক্রি।
             *
             * গুদামটা এখনো যায় — লুকানো ঘরে, কাউন্টারের নিজের গুদাম —
             * নাহলে স্টক লেজার আর গুদামের যোগফল আলাদা হয়ে যেত। কিন্তু
             * **বাছার কিছু নেই**, তাই সুইচটারও লুকানোর কিছু নেই।
             *
             * সুইচটা `module.php`-তে রয়ে গেছে, মুছিনি — সেটিং মোছা
             * মালিকের সিদ্ধান্ত, আর তাঁকে জিজ্ঞেস করা আছে। সারিটা এখান
             * থেকে সরানো হয়েছে যাতে গার্ডটা এমন কিছু দাবি না করে যা
             * পর্দায় আর নেই; সুইচটা ফিরে এলে সারিটাও ফিরবে।
             */
            'sales.field_sub_total' => __('sales::field.sub_total_no_vat'),
            'sales.field_total_item' => __('sales::field.total_item'),
            'sales.field_sales_qty' => __('sales::field.total_sales_qty'),
            'sales.field_free_qty_total' => __('sales::field.total_free_qty'),
            'sales.field_total_qty' => __('sales::field.total_free_plus_sales'),
        ];

        foreach ($cases as $key => $label) {
            /*
             * খোঁজা হয় লেখাটার শেষ সহ ("মোট ফ্রি" তারপর ট্যাগ), শুধু লেখাটা নয়।
             *
             * কারণ "মোট ফ্রি" আসলে "মোট ফ্রি+বিক্রয়"-এর শুরুটাও — শুধু
             * লেখা খুঁজলে একটা সারি বন্ধ করেও অন্যটার ভেতরে সেটা পাওয়া
             * যেত, আর পরীক্ষা মিথ্যা নালিশ করত।
             */
            $needle = '/'.preg_quote($label, '/').'\s*</u';

            $settings->set($key, true);
            $settings->flush();
            $this->assertMatchesRegularExpression($needle, $this->formMarkup(), "খোলা থাকলেও ঘরটা নেই: {$key}");

            $settings->set($key, false);
            $settings->flush();
            $this->assertDoesNotMatchRegularExpression($needle, $this->formMarkup(), "বন্ধ করেও ঘরটা রয়ে গেছে: {$key}");

            $settings->set($key, true);
        }
    }

    /**
     * কেবল ফর্মটুকু — শেল বাদ।
     *
     * পুরো পাতা ধরে খুঁজলে সাইডবারের মেনুর শব্দও মিলে যায়: "গুদাম" ঘরটা
     * লুকানো সত্ত্বেও Inventory-র মেনুতে ওই শব্দটা থেকেই যায়, আর টেস্ট
     * মিথ্যা অভিযোগ করে।
     */
    private function formMarkup(): string
    {
        $html = $this->actingAs($this->user)
            ->get(route('sales.direct.create'))
            ->assertOk()
            ->getContent();

        // ঠিক এই পর্দার ফর্মটা — টপবারের কোম্পানি-সুইচারও একটা POST ফর্ম,
        // আর প্রথম <form> ধরলে সেটাই আসত
        $needle = 'action="'.route('sales.direct.store').'"';

        $start = strpos($html, $needle);
        $this->assertNotFalse($start, 'সরাসরি বিক্রয়ের ফর্মটাই পাওয়া গেল না');

        $end = strpos($html, '</form>', $start);

        return substr($html, $start, $end - $start);
    }

    /**
     * গুদাম বাছার ঘর বন্ধ থাকলেও গুদামটা যায়।
     *
     * না গেলে মাল কোন গুদাম থেকে বেরোল তা লেখা থাকত না, আর এক গুদামের
     * প্রতিষ্ঠানেও একদিন দ্বিতীয় গুদাম খুললে পুরনো চালানগুলো অনাথ হত।
     */
    public function test_hiding_the_warehouse_picker_still_stamps_a_warehouse(): void
    {
        app(SettingsService::class)->set('sales.field_warehouse_select', false);

        $this->actingAs($this->user)->post(route('sales.direct.store'), [
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'lines' => [['product_id' => $this->product->id, 'qty' => '2', 'rate' => '100']],
        ])->assertRedirect();

        $challan = DeliveryChallan::query()->latest('id')->firstOrFail();

        $this->assertSame($this->warehouse->id, (int) $challan->warehouse_id);
    }

    public function test_a_user_without_the_permission_cannot_reach_it(): void
    {
        $stranger = User::factory()->create();
        $stranger->companies()->attach($this->company, ['is_active' => true]);
        $stranger->forceFill(['current_company_id' => $this->company->id])->save();

        $this->actingAs($stranger)->get(route('sales.direct.create'))->assertForbidden();
    }
}
