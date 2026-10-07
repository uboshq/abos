<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\PosService;
use Database\Seeders\DemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ক্যাশিয়ার দুইবার Enter চাপেন, ক্রেতার টাকা যায় একবার।
 *
 * বিরল কোনো ঘটনা নয়। সংযোগ ধীর, পর্দায় কিছুই হচ্ছে না, তাই আবার চাপা
 * হয়। দুইটা অনুরোধই সম্পূর্ণ বৈধ — **ওরা হুবহু এক, আর সেটাই পুরো
 * সমস্যা**: কোনোটাতে ভুল কিছু নেই যা ধরা যায়।
 */
class PosIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Product $product;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->product = Product::query()->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
    }

    private function sell(?string $key, string $qty = '1'): array
    {
        return app(PosService::class)->checkout(
            array_filter([
                'warehouse_id' => $this->warehouse->id,
                'paid' => '1000',
                'idempotency_key' => $key,
            ], fn ($v) => $v !== null),
            [['product_id' => $this->product->id, 'qty' => $qty, 'rate' => '100']],
        );
    }

    // ── একই চাবি, একটাই বিক্রয় ───────────────────────────────────

    /**
     * দ্বিতীয় চাপে নতুন বিল বসে না — আগেরটাই ফিরে আসে।
     *
     * ক্যাশিয়ারের দিক থেকে কিছুই ভুল হয়নি, আর সেটা সত্যি: বিক্রয়টা
     * ঠিক একবারই হয়েছে। তাই দ্বিতীয়বারেও রসিদটাই ফেরত যায়, কোনো
     * ভুলের বার্তা নয়।
     */
    public function test_pressing_enter_twice_makes_one_sale(): void
    {
        $first = $this->sell('cart-1');
        $second = $this->sell('cart-1');

        $this->assertSame($first['invoice']->id, $second['invoice']->id);
        $this->assertSame(1, SalesInvoice::query()->whereNotNull('idempotency_key')->count());
    }

    /** স্টকও একবারই কমে — টাকার মতোই। */
    public function test_the_stock_only_leaves_once(): void
    {
        $this->sell('cart-2', '3');

        $after = SalesInvoice::query()->where('idempotency_key', 'cart-2')->firstOrFail();
        $moved = $after->lines->sum('qty');

        $this->sell('cart-2', '3');

        $this->assertSame(0, bccomp((string) $moved, '3', 4),
            'দ্বিতীয় চাপে মাল দ্বিতীয়বার বেরিয়েছে।');
    }

    /** ফেরত টাকা দুইবারেই এক। */
    public function test_the_change_is_the_same_both_times(): void
    {
        $first = $this->sell('cart-3');
        $second = $this->sell('cart-3');

        $this->assertSame(0, bccomp($first['change'], $second['change'], 4));
    }

    // ── আলাদা চাবি, আলাদা বিক্রয় ─────────────────────────────────

    /**
     * একই মিনিটে একই জিনিস কেনা দুই ক্রেতা দুইটা বিক্রয়।
     *
     * উল্টো দিকের ভুলটা সমান খারাপ: দ্বিতীয় ক্রেতাকে প্রথমজনের রসিদ
     * দিয়ে ফেরত পাঠানো।
     */
    public function test_two_carts_are_two_sales(): void
    {
        $this->assertNotSame(
            $this->sell('cart-4')['invoice']->id,
            $this->sell('cart-5')['invoice']->id,
        );
    }

    /**
     * চাবি ছাড়া বিক্রয় আগের মতোই চলে।
     *
     * পুরনো টিল, ইমপোর্ট, পরীক্ষার কোড — কেউ চাবি পাঠায় না, আর
     * তাদের কিছুই বদলানো উচিত নয়।
     */
    public function test_sales_without_a_key_are_untouched(): void
    {
        $a = $this->sell(null);
        $b = $this->sell(null);

        $this->assertNotSame($a['invoice']->id, $b['invoice']->id);
    }

    /**
     * চাবিহীন বিল যত খুশি — ইনডেক্স তাদের আটকায় না।
     *
     * সাধারণ ইউনিক ইনডেক্সে NULL কখনো NULL-এর সমান নয়। এটা না হলে
     * প্রতি কোম্পানিতে ঠিক একটাই চাবিহীন বিল থাকতে পারত, অর্থাৎ
     * সরাসরি বিক্রয় দ্বিতীয় বিলেই ভেঙে যেত।
     */
    public function test_many_keyless_invoices_can_coexist(): void
    {
        $this->sell(null);
        $this->sell(null);
        $this->sell(null);

        $this->assertGreaterThanOrEqual(
            3,
            SalesInvoice::query()->whereNull('idempotency_key')->count(),
        );
    }

    // ── ইনডেক্সই শেষ পাহারা ──────────────────────────────────────

    /**
     * খোঁজা যে দৌড়টা জিততে পারে না, ইনডেক্স সেটা জেতে।
     *
     * দুইটা অনুরোধ যথেষ্ট কাছাকাছি এলে দুইজনেই খোঁজে, দুইজনেই কিছু
     * পায় না, দুইজনেই বসায়। খোঁজা জানালাটা ছোট করে, বন্ধ করে না —
     * বন্ধ করে ডাটাবেজ।
     */
    public function test_the_same_key_cannot_be_written_twice(): void
    {
        $invoice = $this->sell('cart-6')['invoice'];

        $this->expectException(QueryException::class);

        SalesInvoice::query()->create([
            'company_id' => $this->company->id,
            'customer_id' => $invoice->customer_id,
            'document_no' => 'DUP-0001',
            'idempotency_key' => 'cart-6',
            'trx_date' => now()->toDateString(),
            'status' => 'draft',
        ]);
    }

    /** এক কোম্পানির চাবি অন্য কোম্পানির বিক্রয় ফেরত দেয় না। */
    public function test_a_key_does_not_cross_companies(): void
    {
        $this->sell('cart-7');

        $other = Company::query()->where('code', '!=', 'TDEPOT')->first();

        if ($other === null) {
            $this->markTestSkipped('ডেমোতে দ্বিতীয় কোম্পানি নেই।');
        }

        CompanyContext::set($other->id, null);

        $this->assertSame(0, SalesInvoice::query()->where('idempotency_key', 'cart-7')->count());
    }
}
