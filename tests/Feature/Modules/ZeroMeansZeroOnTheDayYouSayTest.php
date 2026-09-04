<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Purchase\Models\PurchaseReceipt;
use App\Modules\Purchase\Services\PurchaseReceiptService;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\SalesInvoiceService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * শূন্য মানে শূন্য — কিন্তু আপনার বেছে নেওয়া দিনে।
 *
 * ── তিনটা আলাদা জিনিস, একসাথে ───────────────────────────────────────
 *
 * **এক ·** ধারের সীমা দেখা হত **কেবল বিক্রয় আদেশে**। কিন্তু ডিপোতে
 * বেশিরভাগ বিল আদেশ ছাড়াই হয় — কাউন্টারে, সরাসরি বিক্রয়ে, চালান থেকে।
 * ফলে সীমা পেরিয়ে যাওয়া গ্রাহককেও দিব্যি বাকিতে মাল দেওয়া যেত।
 *
 * **দুই ·** সীমা পার করানোর অনুমতি দেখা হত `sales.discount.override`
 * ধরে, অথচ ধারের সীমার নিজের চাবি আছে। যিনি ছাড় অনুমোদন করতে পারেন
 * তিনি ধারও পার করাতে পারতেন, আর যাঁকে ঠিক এই কাজের চাবি দেওয়া হয়েছে
 * তিনি পারতেন না — **দুইটাই উল্টো**।
 *
 * **তিন ·** মালিকের সিদ্ধান্ত: শূন্য লিমিট মানে বাকি নয়। কিন্তু আজ
 * শূন্য মানে সীমাহীন, আর ১৪৮ জনের লিমিট বসানোই নেই — নিয়মটা চুপচাপ
 * উল্টে দিলে পরদিন সকালেই ডিপো অচল। তাই সুইচ, আর সুইচটা মালিকের।
 */
class ZeroMeansZeroOnTheDayYouSayTest extends TestCase
{
    use RefreshDatabase;

    private Customer $dealer;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->dealer = Customer::query()->firstOrFail();
        $this->dealer->update(['credit_limit' => '0']);

        $this->product = Product::query()->create([
            'company_id' => CompanyContext::id(),
            'code' => 'LIM-1',
            'name_en' => 'Tofan',
            'name_bn' => 'তুফান',
            'unit_id' => Product::query()->value('unit_id'),
            'purchase_price' => '100',
            'sale_price' => '120',
            'is_active' => true,
        ]);

        $receipt = app(PurchaseReceiptService::class)->confirm(
            app(PurchaseReceiptService::class)->create(
                ['supplier_id' => Supplier::query()->value('id'), 'warehouse_id' => $this->warehouse->id,
                    'trx_date' => now()->toDateString()],
                [['product_id' => $this->product->id, 'received_qty' => '100', 'rate' => '100']],
            ),
        );

        /*
         * আর মালটা বুঝে নেওয়া — Stock Placement, ৪ সেপ্টেম্বর ২০২৬।
         *
         * ⓘ এই ফাইলের প্রশ্ন ক্রেডিট সীমা নিয়ে, মজুদ নিয়ে নয় — কিন্তু
         * সীমার পরীক্ষা করতে হলে আগে বেচতে পারতে হবে। ⛔ ধাপটা ছাড়া
         * প্রতিটা বিক্রয় "তাকে যথেষ্ট নেই" বলে আটকাত, আর সীমার নিয়মটা
         * কখনো পরীক্ষাই হত না — সবুজ, অথচ অন্ধ।
         */
        app(StockService::class)->place(
            product: $this->product,
            warehouse: $this->warehouse,
            qty: '100',
            sourceType: PurchaseReceipt::STOCK_SOURCE,
            sourceId: $receipt->id,
        );
    }

    /** একটা বিল — খসড়া থেকে নিশ্চিত পর্যন্ত। */
    private function bill(string $qty = '10'): SalesInvoice
    {
        return app(SalesInvoiceService::class)->confirm(
            app(SalesInvoiceService::class)->create(
                ['customer_id' => $this->dealer->id, 'warehouse_id' => $this->warehouse->id,
                    'trx_date' => now()->toDateString()],
                [['product_id' => $this->product->id, 'qty' => $qty, 'rate' => '120']],
            ),
        );
    }

    // ── এক · বিলের পথেও সীমা খাটে ───────────────────────────────────

    /**
     * সীমা পেরোনো গ্রাহকের বিল আর নিশ্চিত হয় না।
     *
     * আগে এই পাহারাটা কেবল বিক্রয় আদেশে ছিল, তাই আদেশ ছাড়া বিল করলে
     * সীমাটা কিছুই আটকাত না।
     */
    public function test_the_bill_path_now_honours_the_limit(): void
    {
        $this->dealer->update(['credit_limit' => '500']);
        $this->actingAs(User::query()->where('email', 'sales@abos.test')->firstOrFail());

        try {
            $this->bill('10');   // ১,২০০ টাকা, সীমা ৫০০

            $this->fail('সীমা পেরিয়ে যাওয়া বিলটা নিশ্চিত হয়ে গেছে।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('customer_id', $e->errors());
        }

        $this->assertSame(0, SalesInvoice::query()
            ->where('status', DocumentStatus::CONFIRMED)->count());
    }

    /** সীমার ভেতরের বিল আগের মতোই যায়। */
    public function test_a_bill_inside_the_limit_passes(): void
    {
        $this->dealer->update(['credit_limit' => '5000']);
        $this->actingAs(User::query()->where('email', 'sales@abos.test')->firstOrFail());

        $this->assertSame(DocumentStatus::CONFIRMED, $this->bill('10')->status);
    }

    // ── দুই · ঠিক চাবিটাই দরজা খোলে ─────────────────────────────────

    /**
     * ধারের সীমা পার করাতে ধারের চাবিই লাগে।
     *
     * মালিকের কাছে `customer.credit_limit.override` আছে, তাই তাঁর
     * বিলটা যায়।
     */
    public function test_the_credit_key_opens_the_door(): void
    {
        $this->dealer->update(['credit_limit' => '500']);

        $this->assertSame(DocumentStatus::CONFIRMED, $this->bill('10')->status);
    }

    /**
     * আর ছাড়ের চাবি দিয়ে ধারের দরজা খোলে না।
     *
     * ── কেন এটা আলাদা করে পরীক্ষা করা ───────────────────────────────
     * আগে ঠিক উল্টোটা ছিল: কোডে `sales.discount.override` দেখা হত।
     * দুইটা আলাদা ক্ষমতা এক করে ফেললে অনুমতির তালিকাটাই মিথ্যা হয়ে
     * যায় — কাগজে লেখা থাকে একজন পারেন না, বাস্তবে পারেন।
     */
    public function test_the_discount_key_does_not_open_the_credit_door(): void
    {
        $salesman = User::query()->where('email', 'sales@abos.test')->firstOrFail();
        $salesman->givePermissionTo('sales.discount.override');

        $this->dealer->update(['credit_limit' => '500']);
        $this->actingAs($salesman->fresh());

        $this->expectException(ValidationException::class);

        $this->bill('10');
    }

    // ── তিন · সুইচটা মালিকের ────────────────────────────────────────

    /**
     * সুইচ বন্ধ থাকলে শূন্য মানে সীমাহীন — আজকের আচরণ অটুট।
     *
     * এটাই সবচেয়ে জরুরি পরীক্ষা: কাজটা ডিপোর রোজকার চলা বদলায় না,
     * যতক্ষণ মালিক নিজে সুইচটা না টেপেন।
     */
    public function test_with_the_switch_off_zero_still_means_no_limit(): void
    {
        $this->actingAs(User::query()->where('email', 'sales@abos.test')->firstOrFail());

        $this->assertFalse($this->dealer->wouldExceedCreditLimit('100000'));
        $this->assertSame(DocumentStatus::CONFIRMED, $this->bill('10')->status);
    }

    /** সুইচ চালু করলে শূন্য মানে শূন্য — বাকিতে কিছুই নয়। */
    public function test_with_the_switch_on_zero_means_no_credit(): void
    {
        app(SettingsService::class)->set('customer.zero_limit_blocks', true);
        $this->actingAs(User::query()->where('email', 'sales@abos.test')->firstOrFail());

        $this->assertTrue($this->dealer->fresh()->wouldExceedCreditLimit('1'),
            'সুইচ চালু, তবু শূন্য লিমিটে বাকি চলছে।');

        $this->expectException(ValidationException::class);

        $this->bill('10');
    }

    /** সুইচ চালু থাকলেও যাঁর লিমিট বসানো আছে তিনি অক্ষত। */
    public function test_a_dealer_with_a_real_limit_is_untouched(): void
    {
        app(SettingsService::class)->set('customer.zero_limit_blocks', true);
        $this->dealer->update(['credit_limit' => '5000']);
        $this->actingAs(User::query()->where('email', 'sales@abos.test')->firstOrFail());

        $this->assertSame(DocumentStatus::CONFIRMED, $this->bill('10')->status);
    }

    // ── সুইচের জোড়া কাগজ ────────────────────────────────────────────

    /**
     * "কাদের লিমিট নেই" তালিকাটা সত্যিই তাঁদের দেখায়।
     *
     * সুইচ টেপার আগে এই কাগজটাই মালিকের হাতে থাকা দরকার — নাহলে
     * পরদিন সকালে কে আটকাবেন তা আগে থেকে জানার উপায় নেই।
     */
    public function test_the_list_names_who_has_no_limit(): void
    {
        $withLimit = Customer::query()->where('id', '!=', $this->dealer->id)->first();
        $withLimit?->update(['credit_limit' => '9000']);

        $rows = app(ReportEngine::class)->run('customer.no_limit', [])->rows;

        $ids = collect($rows)->map(fn ($r) => (int) ((object) $r)->id)->all();

        $this->assertContains($this->dealer->id, $ids,
            'যাঁর লিমিট শূন্য তিনি তালিকায় নেই।');

        if ($withLimit !== null) {
            $this->assertNotContains($withLimit->id, $ids,
                'যাঁর লিমিট বসানো আছে তিনিও তালিকায় চলে এসেছেন।');
        }
    }

    /** পর্দাটা খোলে। */
    public function test_the_list_screen_opens(): void
    {
        $this->get(route('customer.report.show', ['slug' => 'no-limit']))
            ->assertOk()
            ->assertSee(__('customer::menu.no_limit'));
    }
}
