<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\PosService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * কাউন্টারের পর্দা থেকে বিল ধরে রাখা ও আবার তোলা।
 *
 * PosParkedBillTest সার্ভিসটা পরীক্ষা করে। এটা করে তার *পথ*: বোতামটা
 * আছে কিনা, রুটটা আছে কিনা, আর চাপলে সত্যিই কিছু ঘটে কিনা। সার্ভিস
 * ঠিক থেকেও রুট না থাকলে সুবিধাটা লেখা থাকত অথচ কেউ ব্যবহার করতে
 * পারত না — engine ৭ ঠিক এই অবস্থাতেই পড়ে ছিল।
 */
class PosParkScreenTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private Warehouse $warehouse;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->product = Product::query()->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->customer = Customer::query()->firstOrFail();

        // কাউন্টারের পর্দা ডিফল্ট বন্ধ (Sales module.php) — পরীক্ষা
        // করার আগে চালু করে নেওয়া হয়।
        app(SettingsService::class)->set('sales.screen_pos', true);
    }

    private function park(string $qty = '2')
    {
        return $this->post(route('sales.pos.park'), [
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'lines' => [[
                'product_id' => $this->product->id,
                'qty' => $qty,
                'rate' => '100',
            ]],
        ]);
    }

    // ── ধরে রাখা ─────────────────────────────────────────────────

    /** বোতামটা চাপলে বিলটা খসড়া হয়ে কাউন্টারে ঝুলে থাকে। */
    public function test_holding_a_cart_leaves_a_draft_waiting(): void
    {
        $this->park()->assertRedirect(route('sales.pos.index'));

        $bill = SalesInvoice::query()->latest('id')->firstOrFail();

        $this->assertSame(DocumentStatus::DRAFT, $bill->status);
        $this->assertNotNull($bill->parked_at);
    }

    /**
     * ধরে রাখা বিলে খাতায় কিছু বসে না।
     *
     * বসলে দিনের বিক্রির যোগফলে এমন টাকা ঢুকত যা কেউ দেয়নি, আর মজুদ
     * থেকে এমন মাল বেরোত যা দোকানেই আছে।
     */
    public function test_a_held_cart_posts_nothing(): void
    {
        $before = $this->pos()->todaysTotal();

        $this->park();

        $this->assertSame(0, bccomp($before, $this->pos()->todaysTotal(), 4));
    }

    /** ফাঁকা ঝুড়ি ধরে রাখা যায় না। */
    public function test_an_empty_cart_cannot_be_held(): void
    {
        $this->post(route('sales.pos.park'), [
            'warehouse_id' => $this->warehouse->id,
            'lines' => [],
        ])->assertSessionHasErrors('lines');
    }

    // ── পর্দায় দেখা ও তোলা ───────────────────────────────────────

    /** ঝুলে থাকা বিলটা কাউন্টারের পর্দায় দেখা যায়। */
    public function test_the_waiting_bill_shows_on_the_counter(): void
    {
        $this->park();

        $no = SalesInvoice::query()->latest('id')->value('document_no');

        $this->get(route('sales.pos.index'))
            ->assertOk()
            ->assertSee($no);
    }

    /**
     * তুললে সারিগুলো ঝুড়িতে ফিরে আসে।
     *
     * না এলে ক্যাশিয়ারকে আবার গোড়া থেকে টাইপ করতে হত — অর্থাৎ ধরে
     * রাখার পুরো মানেটাই থাকত না।
     */
    public function test_resuming_puts_the_lines_back_in_the_cart(): void
    {
        $this->park('7');
        $bill = SalesInvoice::query()->latest('id')->firstOrFail();

        $response = $this->post(route('sales.pos.resume', ['invoice' => $bill->id]));

        $response->assertRedirect(route('sales.pos.index', ['resume' => $bill->id]));

        /*
         * রেন্ডার হওয়া HTML-এ খোঁজা হয় না, কন্ট্রোলার টেমপ্লেটে কী
         * হাতে দিচ্ছে সেটাই দেখা হয়। Js::from উদ্ধৃতিগুলো এস্কেপ করে
         * বসায়, তাই HTML-এ স্ট্রিং খোঁজা মানে এস্কেপিং-এর নিয়ম নিয়ে
         * আন্দাজ করা — আর সেই আন্দাজ ভুল হলে টেস্টটা ফেল করত এমন
         * কারণে যার সাথে কাউন্টারের কোনো সম্পর্ক নেই।
         */
        $seen = [];

        View::composer('sales::pos.index', function ($view) use (&$seen) {
            $seen = $view->getData();
        });

        $this->get(route('sales.pos.index', ['resume' => $bill->id]))->assertOk();

        $this->assertCount(1, $seen['resumed']);
        $this->assertSame($this->product->id, $seen['resumed'][0]['product_id']);
        $this->assertSame(0, bccomp('7', $seen['resumed'][0]['qty'], 4));
    }

    /** তোলার পর ওটা আর অপেক্ষমাণ তালিকায় থাকে না। */
    public function test_a_resumed_bill_leaves_the_waiting_list(): void
    {
        $this->park();
        $bill = SalesInvoice::query()->latest('id')->firstOrFail();

        $this->post(route('sales.pos.resume', ['invoice' => $bill->id]));

        $this->assertFalse($this->pos()->parked()->contains('id', $bill->id));
    }

    /**
     * সম্পূর্ণ হয়ে যাওয়া বিল তোলা যায় না।
     *
     * তুললে ক্যাশিয়ার ভাবতেন ওটা অসমাপ্ত, আর দ্বিতীয়বার টাকা নিতেন।
     */
    public function test_a_completed_bill_cannot_be_resumed(): void
    {
        $this->park();
        $bill = SalesInvoice::query()->latest('id')->firstOrFail();
        $bill->forceFill(['status' => DocumentStatus::CONFIRMED])->save();

        $this->post(route('sales.pos.resume', ['invoice' => $bill->id]))
            ->assertSessionHasErrors('invoice');
    }

    // ── পাহারা ───────────────────────────────────────────────────

    /**
     * তোলা GET-এ হয় না।
     *
     * তোলার সময় বিলটার অবস্থা বদলায়। GET হলে ব্রাউজারের prefetch বা
     * কারো পাঠানো লিংকেই বিলটা কাউন্টারের তালিকা থেকে হারিয়ে যেত।
     */
    public function test_resuming_is_not_a_get(): void
    {
        $this->park();
        $bill = SalesInvoice::query()->latest('id')->firstOrFail();

        $this->get('/sales/pos/'.$bill->id.'/resume')->assertStatus(405);
    }

    /** কাউন্টারের অনুমতি ছাড়া ধরে রাখা যায় না। */
    public function test_the_counter_permission_is_required(): void
    {
        $clerk = User::factory()->create();
        $clerk->companies()->attach(CompanyContext::id(), ['is_active' => true]);
        $clerk->forceFill(['current_company_id' => CompanyContext::id()])->save();

        $this->actingAs($clerk);

        $this->park()->assertForbidden();
    }

    private function pos(): PosService
    {
        return app(PosService::class);
    }
}
