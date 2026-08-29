<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\KitchenTicket;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Recipe;
use App\Modules\Inventory\Models\RecipeLine;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\CostLayerService;
use App\Modules\Inventory\Services\KitchenTicketService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\MasterData\Models\Unit;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\SalesInvoiceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * রান্নাঘর জানত না যে অর্ডার এসেছে — রেস্টুরেন্টের ধাপ ৪।
 *
 * ── কী ছিল না ────────────────────────────────────────────────────────
 * ধাপ ১–৩-এর পর কাউন্টার জানে কয় প্লেট বানানো যাবে, বিক্রিতে উপকরণও
 * কমে। কিন্তু রান্নাঘরে খবরটা যেত না: কেউ কাগজ নিয়ে দৌড়াত, নয়তো
 * চেঁচিয়ে বলত — আর ব্যস্ত সময়ে দুইটাই হারায়।
 */
class TheKitchenNeverKnewAnOrderHadComeTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Warehouse $warehouse;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->user = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs($this->user);

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->customer = Customer::query()->firstOrFail();
    }

    private function make(string $name): Product
    {
        return Product::query()->create([
            'code' => 'KT-'.mb_substr(md5($name.microtime()), 0, 8),
            'name_en' => $name,
            'name_bn' => $name,
            'unit_id' => Unit::query()->orderBy('id')->firstOrFail()->id,
            'sale_price' => '250',
            'is_active' => true,
        ]);
    }

    private function stocked(string $name, string $qty): Product
    {
        $product = $this->make($name);

        app(StockService::class)->move(
            product: $product,
            warehouse: $this->warehouse,
            sourceType: 'test.opening',
            sourceId: $product->id,
            floor: $qty,
        );

        app(CostLayerService::class)->receive(
            product: $product,
            qty: $qty,
            unitCost: '10.00',
            sourceType: 'test.opening',
            sourceId: $product->id,
        );

        return $product;
    }

    private function dish(string $name, string $kind, Product $ingredient): Product
    {
        $dish = $this->make($name);

        $recipe = Recipe::query()->create([
            'product_id' => $dish->id,
            'kind' => $kind,
            'yield_qty' => '1',
            'is_active' => true,
        ]);

        RecipeLine::query()->create([
            'recipe_id' => $recipe->id,
            'product_id' => $ingredient->id,
            'qty' => '1',
            'waste_pct' => '0',
            'sort' => 0,
        ]);

        return $dish;
    }

    private function sell(Product $product, string $qty = '2'): SalesInvoice
    {
        $invoice = app(SalesInvoiceService::class)->create(
            [
                'customer_id' => $this->customer->id,
                'warehouse_id' => $this->warehouse->id,
                'trx_date' => now()->toDateString(),
            ],
            [['product_id' => $product->id, 'qty' => $qty, 'rate' => '250']],
        );

        return app(SalesInvoiceService::class)->confirm($invoice);
    }

    /**
     * বিল নিশ্চিত হলে রান্নাঘরে টিকিট যায়।
     */
    public function test_confirming_a_bill_puts_the_dish_on_the_kitchen_screen(): void
    {
        $rice = $this->stocked('Rice', '100');
        $biryani = $this->dish('Biryani', Recipe::TO_ORDER, $rice);

        $invoice = $this->sell($biryani, '3');

        $ticket = KitchenTicket::query()->latest('id')->first();

        $this->assertNotNull($ticket, 'রান্নাঘরে কোনো টিকিটই যায়নি।');
        $this->assertSame($biryani->id, $ticket->product_id);
        $this->assertSame(0, bccomp((string) $ticket->qty, '3', 4));
        $this->assertSame(KitchenTicket::PLACED, $ticket->state);
        $this->assertSame($invoice->document_no, $ticket->document_no,
            'কাগজের নম্বরটা টিকিটে নেই — রাঁধুনি কী ডেকে বলবেন?');
    }

    /**
     * হাঁড়ির খাবারের টিকিট যায় না।
     *
     * ওটা সকালেই রান্না হয়ে গেছে। টিকিট পাঠানো মানে রাঁধুনিকে এমন কিছু
     * করতে বলা যা ইতিমধ্যেই করা, আর ব্যস্ত সময়ে পর্দাটা ওই কাগজে ভরে
     * গিয়ে সত্যিকারের অর্ডার ঢেকে ফেলত।
     */
    public function test_a_batch_dish_does_not_reach_the_kitchen_screen(): void
    {
        $rice = $this->stocked('Rice', '100');
        $tehari = $this->dish('Tehari', Recipe::BATCH, $rice);

        app(StockService::class)->move(
            product: $tehari,
            warehouse: $this->warehouse,
            sourceType: 'test.cooked',
            sourceId: $tehari->id,
            floor: '20',
        );

        app(CostLayerService::class)->receive(
            product: $tehari,
            qty: '20',
            unitCost: '50.00',
            sourceType: 'test.cooked',
            sourceId: $tehari->id,
        );

        $this->sell($tehari, '2');

        $this->assertSame(0, KitchenTicket::query()->count(),
            'হাঁড়ির খাবারের টিকিটও রান্নাঘরে গেছে।');
    }

    /**
     * সাধারণ পণ্যেরও যায় না — বিস্কুট রাঁধতে হয় না।
     */
    public function test_an_ordinary_product_never_reaches_the_kitchen(): void
    {
        $biscuit = $this->stocked('Biscuit', '50');

        $this->sell($biscuit, '4');

        $this->assertSame(0, KitchenTicket::query()->count());
    }

    /**
     * একই বিল দুইবার এলে টিকিট দুইবার হয় না।
     *
     * নিশ্চিতকরণ একবারই ঘটার কথা, কিন্তু "ঘটার কথা" আর "ঘটে" এক নয় —
     * দুইটা ট্যাব, একটা দ্বিতীয় ক্লিক, একটা রি-ট্রাই। রান্নাঘরে দুইটা
     * একই টিকিট মানে দুইবার রান্না, আর একবারের টাকা।
     */
    public function test_the_same_bill_does_not_raise_the_ticket_twice(): void
    {
        $rice = $this->stocked('Rice', '100');
        $biryani = $this->dish('Biryani', Recipe::TO_ORDER, $rice);

        $invoice = $this->sell($biryani, '1');

        app(KitchenTicketService::class)->raise(
            sourceType: SalesInvoice::STOCK_SOURCE,
            sourceId: $invoice->id,
            documentNo: $invoice->document_no,
            lines: [['product' => $biryani, 'qty' => '1']],
        );

        $this->assertSame(1, KitchenTicket::query()->count());
    }

    /**
     * ধাপগুলো ক্রমেই — লাফ দেওয়া যায় না।
     *
     * "হয়েছে" চাপার আগে "শুরু" চাপতেই হয়। লাফ দিতে দিলে `started_at`
     * খালি থেকে যেত, আর "রাঁধতে গড়ে কত লাগে" প্রশ্নের উত্তর অর্ধেক
     * টিকিটে থাকত না — অথচ ওটাই রান্নাঘরের একমাত্র মাপ।
     */
    public function test_a_ticket_walks_its_steps_and_cannot_jump(): void
    {
        $rice = $this->stocked('Rice', '100');
        $biryani = $this->dish('Biryani', Recipe::TO_ORDER, $rice);

        $this->sell($biryani, '1');

        $ticket = KitchenTicket::query()->latest('id')->firstOrFail();
        $kitchen = app(KitchenTicketService::class);

        $kitchen->advance($ticket, KitchenTicket::COOKING);
        $this->assertNotNull($ticket->fresh()->started_at, 'রান্না শুরুর সময় লেখা হয়নি।');

        $kitchen->advance($ticket, KitchenTicket::READY);
        $this->assertNotNull($ticket->fresh()->ready_at);

        /* ভুল করে দুইবার চাপা — নীরবে কিছুই হয় না, ভুল নয় */
        $kitchen->advance($ticket, KitchenTicket::READY);
        $this->assertSame(KitchenTicket::READY, $ticket->fresh()->state);

        $this->expectException(RuntimeException::class);

        $kitchen->advance($ticket->fresh(), KitchenTicket::COOKING);
    }

    /**
     * দেওয়া হয়ে গেলে টিকিটটা পর্দা থেকে যায়।
     *
     * ব্যস্ত সময়ে পর্দায় ত্রিশটা টিকিট। শেষ হওয়াগুলো থাকলে রাঁধুনিকে
     * প্রতিবার চোখ দিয়ে ছেঁকে নিতে হত, আর ঠিক তখনই নতুনটা চোখ এড়ায়।
     */
    public function test_a_served_ticket_leaves_the_screen(): void
    {
        $rice = $this->stocked('Rice', '100');
        $biryani = $this->dish('Biryani', Recipe::TO_ORDER, $rice);

        $this->sell($biryani, '1');

        $ticket = KitchenTicket::query()->latest('id')->firstOrFail();
        $kitchen = app(KitchenTicketService::class);

        $this->get(route('inventory.kitchen.tickets'))->assertOk()->assertSee($biryani->name());

        $kitchen->advance($ticket, KitchenTicket::COOKING);
        $kitchen->advance($ticket, KitchenTicket::READY);
        $kitchen->advance($ticket, KitchenTicket::SERVED);

        $feed = $this->getJson(route('inventory.kitchen.feed'))->assertOk()->json();

        $this->assertSame([], $feed['tickets'], 'দেওয়া টিকিটটা পর্দায় রয়ে গেছে।');
    }

    /**
     * অপেক্ষার হিসাব অর্ডারের সময় থেকে, রান্না শুরু থেকে নয়।
     *
     * খদ্দের অপেক্ষা করছেন অর্ডার দেওয়ার সময় থেকে। রান্নাঘরের পর্দায়
     * যেটা লাল হওয়া দরকার সেটা **খদ্দেরের** অপেক্ষা, রাঁধুনির নয়।
     */
    public function test_the_wait_is_counted_from_when_the_order_came(): void
    {
        $rice = $this->stocked('Rice', '100');
        $biryani = $this->dish('Biryani', Recipe::TO_ORDER, $rice);

        $this->sell($biryani, '1');

        $ticket = KitchenTicket::query()->latest('id')->firstOrFail();

        $ticket->forceFill([
            'placed_at' => now()->subMinutes(22),
            'started_at' => now()->subMinutes(3),
        ])->save();

        $this->assertSame(22, $ticket->fresh()->waitingMinutes());
    }
}
