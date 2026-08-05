<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Engines\Print\PaperSize;
use App\Core\Support\AmountInWords;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Sales\Services\SalesInvoiceService;
use App\Modules\Sales\Services\SalesOrderService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * ছাপা — ছয়টা কাগজ × তিনটা মাপ।
 *
 * ── এই টেস্ট কী প্রমাণ করে, আর কী করে না ──────────────────────────────
 * প্রমাণ করে: প্রতিটা কাগজ প্রতিটা মাপে সত্যিই একটা PDF হয়ে বেরোয়, আর
 * যেখানে দাম থাকার কথা নয় সেখানে দাম নেই।
 *
 * প্রমাণ করে না: কাগজটা দেখতে ঠিক কেমন। PDF তৈরি হয়েছে দেখে বোঝা যায় না
 * বাংলা যুক্তাক্ষর ভেঙেছে কি না বা ডান দিকের অঙ্ক কেটে গেছে কি না — ওটা
 * চোখে দেখেই ধরতে হয়। এই প্রকল্পে ঠিক সেভাবেই একবার ধরা পড়েছিল যে
 * টেবিলের হেডারে DejaVu বসানোয় "ডেবিট" ফাঁকা বাক্স হয়ে যাচ্ছে।
 */
class SalesPrintTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Customer $customer;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->user = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs($this->user);

        $this->customer = Customer::query()->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->firstOrFail();
    }

    private function order(): SalesOrder
    {
        return app(SalesOrderService::class)->confirm(
            app(SalesOrderService::class)->create(
                ['customer_id' => $this->customer->id, 'warehouse_id' => $this->warehouse->id,
                    'trx_date' => now()->toDateString()],
                [['product_id' => $this->product->id, 'ordered_qty' => '4', 'rate' => '250']],
            )
        );
    }

    private function challan(): DeliveryChallan
    {
        return app(DeliveryChallanService::class)->confirm(
            app(DeliveryChallanService::class)->create(
                ['customer_id' => $this->customer->id, 'warehouse_id' => $this->warehouse->id,
                    'trx_date' => now()->toDateString(), 'vehicle_no' => 'ঢাকা মেট্রো-ট ১১-২২৩৩'],
                [['product_id' => $this->product->id, 'delivered_qty' => '4', 'rate' => '250']],
            )
        );
    }

    private function invoice(): SalesInvoice
    {
        return app(SalesInvoiceService::class)->confirm(
            app(SalesInvoiceService::class)->create(
                ['customer_id' => $this->customer->id, 'warehouse_id' => $this->warehouse->id,
                    'trx_date' => now()->toDateString()],
                [['product_id' => $this->product->id, 'qty' => '4', 'rate' => '250']],
            )
        );
    }

    /**
     * ছয়টা কাগজই তিনটা মাপে বেরোয়।
     *
     * আগে ছাপার ইঞ্জিনটা ছিল কিন্তু কোনো রুট ছিল না — অর্থাৎ কোড লেখা
     * হয়েছিল, কিন্তু কেউ কোনোদিন কিছু ছাপতে পারত না।
     */
    public function test_every_document_prints_on_every_paper(): void
    {
        $order = $this->order();
        $challan = $this->challan();
        $invoice = $this->invoice();

        $urls = [
            route('sales.print.order', $order),
            route('sales.print.delivery_order', $order),
            route('sales.print.challan', $challan),
            route('sales.print.gatepass', $challan),
            route('sales.print.invoice', $invoice),
            route('sales.print.draft', $invoice),
        ];

        foreach ($urls as $url) {
            foreach (PaperSize::all() as $paper) {
                $response = $this->actingAs($this->user)->get($url.'?paper='.$paper);

                $response->assertOk();
                $response->assertHeader('Content-Type', 'application/pdf');

                $pdf = $response->getContent();

                $this->assertStringStartsWith('%PDF', $pdf, "{$url} @ {$paper} PDF নয়");
                $this->assertGreaterThan(800, strlen($pdf), "{$url} @ {$paper} সন্দেহজনকভাবে ছোট");
            }
        }
    }

    /**
     * গেটপাস ও ডেলিভারি অর্ডারে দাম থাকে না।
     *
     * থাকলে গাড়ির চালক থেকে দারোয়ান পর্যন্ত সবাই জেনে যেতেন কোন গ্রাহক
     * কী দরে কেনেন — আর ওই তথ্য ফাঁস হলে দর নিয়ে দরকষাকষি শুরু হয়।
     *
     * PDF-এর ভেতরের লেখা সরাসরি পড়া যায় না (সংকুচিত), তাই যাচাইটা
     * DTO-র স্তরে: কন্ট্রোলার showMoney বন্ধ করে পাঠাচ্ছে কি না।
     */
    public function test_the_gatepass_and_delivery_order_carry_no_prices(): void
    {
        $challan = $this->challan();
        $order = $this->order();

        foreach ([
            route('sales.print.gatepass', $challan),
            route('sales.print.delivery_order', $order),
        ] as $url) {
            $rendered = null;

            // ভিউটা কী ডেটা পেল তা ধরে রাখা — PDF-এর ভেতর দেখার চেয়ে
            // এটাই সৎ যাচাই
            View::composer('print.document', function ($view) use (&$rendered) {
                $rendered = $view->getData()['doc'] ?? null;
            });

            $this->actingAs($this->user)->get($url)->assertOk();

            $this->assertNotNull($rendered, "{$url} কোনো ডকুমেন্ট পাঠায়নি");
            $this->assertFalse($rendered->showMoney, "{$url}-এ দাম দেখানো হচ্ছে");
            $this->assertSame([], $rendered->totals, "{$url}-এ মোটের ঘর আছে");
        }
    }

    /** বিলে দাম থাকে, আর কথায় লেখা অঙ্কও। */
    public function test_the_invoice_carries_prices_and_words(): void
    {
        $rendered = null;

        View::composer('print.document', function ($view) use (&$rendered) {
            $rendered = $view->getData()['doc'] ?? null;
        });

        $invoice = $this->invoice();

        $this->actingAs($this->user)->get(route('sales.print.invoice', $invoice))->assertOk();

        $this->assertTrue($rendered->showMoney);
        $this->assertNotEmpty($rendered->totals);
        $this->assertNotEmpty($rendered->amountInWords);
    }

    /** খসড়ায় বড় করে লেখা থাকে যে এটা চূড়ান্ত নয়। */
    public function test_the_draft_says_it_is_not_final(): void
    {
        $rendered = null;

        View::composer('print.document', function ($view) use (&$rendered) {
            $rendered = $view->getData()['doc'] ?? null;
        });

        $invoice = $this->invoice();

        $this->actingAs($this->user)->get(route('sales.print.draft', $invoice))->assertOk();

        $this->assertNotNull($rendered->notice);
    }

    /** অজানা কাগজের মাপে পাতাটা ভাঙে না, A4-তে ফেরে। */
    public function test_an_unknown_paper_size_falls_back_instead_of_breaking(): void
    {
        $invoice = $this->invoice();

        foreach (['a3', '', 'drop table', '1000mm'] as $nonsense) {
            $this->actingAs($this->user)
                ->get(route('sales.print.invoice', $invoice).'?paper='.urlencode($nonsense))
                ->assertOk()
                ->assertHeader('Content-Type', 'application/pdf');
        }
    }

    public function test_a_user_without_the_permission_cannot_print(): void
    {
        $stranger = User::factory()->create();
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $stranger->companies()->attach($company, ['is_active' => true]);
        $stranger->forceFill(['current_company_id' => $company->id])->save();

        $invoice = $this->invoice();

        $this->actingAs($stranger)
            ->get(route('sales.print.invoice', $invoice))
            ->assertForbidden();
    }

    // ── কথায় লেখা অঙ্ক ──────────────────────────────────────────────────

    /**
     * বাংলা দল ইংরেজি দলের মতো নয় — লক্ষ ও কোটি।
     *
     * NumberFormatter::SPELLOUT বাংলা লোকেলেও ইংরেজি ছক ধরে ভাঙে, তাই
     * "এক লক্ষ"-এর জায়গায় "একশত হাজার" আসত — যা কেউ চেকে লেখে না।
     */
    public function test_bengali_words_group_in_lakh_and_crore(): void
    {
        $this->assertSame('এক লক্ষ টাকা মাত্র', AmountInWords::of('100000', 'bn'));
        $this->assertSame('এক কোটি টাকা মাত্র', AmountInWords::of('10000000', 'bn'));
        $this->assertSame(
            'চার লক্ষ বাইশ হাজার সাতশত টাকা মাত্র',
            AmountInWords::of('422700', 'bn'),
        );
    }

    /**
     * পয়সা আলাদা করে বলা হয়।
     *
     * "সাতশত টাকা পঁচিশ পয়সা" আর "সাতশত পঁচিশ টাকা" দুইটা সম্পূর্ণ
     * আলাদা অঙ্ক — কাগজে গুলিয়ে গেলে টাকার হিসাব গুলিয়ে যায়।
     */
    public function test_paisa_are_spoken_separately(): void
    {
        $this->assertSame('সাতশত টাকা পঁচিশ পয়সা মাত্র', AmountInWords::of('700.25', 'bn'));
        $this->assertSame('সাতশত পঁচিশ টাকা মাত্র', AmountInWords::of('725', 'bn'));
    }

    /** ইংরেজিতেও লক্ষ-কোটি — কাগজটা বাংলাদেশে পড়া হয়। */
    public function test_english_words_also_use_lakh_and_crore(): void
    {
        $this->assertSame('One lakh taka only', AmountInWords::of('100000', 'en'));
        $this->assertStringContainsString('crore', AmountInWords::of('25000000', 'en'));
    }

    public function test_zero_and_negative_amounts_have_words(): void
    {
        $this->assertSame('শূন্য টাকা মাত্র', AmountInWords::of('0', 'bn'));
        $this->assertStringContainsString('ঋণাত্মক', AmountInWords::of('-50', 'bn'));
    }
}
