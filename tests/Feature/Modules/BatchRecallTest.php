<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Services\DeliveryChallanService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * রিকল — এই লটটা কাদের কাছে গেছে।
 *
 * ── কেন এই প্রশ্নটার উত্তর থাকতেই হবে ────────────────────────────────
 * প্রস্তুতকারক একটা লট ফিরিয়ে নিতে বললে দোকানিকে দুইটা কাজ করতে হয়:
 * তাকেরটা সরানো, আর যাঁদের কাছে গেছে তাঁদের ফোন করা। ব্যাচ ধরে রাখার
 * পুরো কারণটাই এই মুহূর্তটা — আর এতদিন ABOS-এ ওই প্রশ্নের কোনো উত্তর
 * ছিল না, যদিও তথ্যটা চলাচলের সারিতে বসে ছিল।
 */
class BatchRecallTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private Warehouse $warehouse;

    private Customer $customer;

    private Batch $batch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->customer = Customer::query()->firstOrFail();

        $this->product = Product::query()->firstOrFail();
        $this->product->forceFill(['track_batch' => true])->save();

        $this->batch = Batch::query()->create([
            'product_id' => $this->product->id,
            'batch_no' => 'RECALL1',
            'expiry_date' => now()->addMonths(9)->toDateString(),
        ]);

        app(StockService::class)->move(
            product: $this->product,
            warehouse: $this->warehouse,
            sourceType: 'test_opening',
            sourceId: $this->batch->id,
            floor: '100',
            batch: $this->batch,
        );
    }

    private function sell(string $qty, ?Customer $to = null): DeliveryChallan
    {
        $challan = app(DeliveryChallanService::class)->create(
            [
                'customer_id' => ($to ?? $this->customer)->id,
                'warehouse_id' => $this->warehouse->id,
                'trx_date' => now()->toDateString(),
            ],
            [['product_id' => $this->product->id, 'delivered_qty' => $qty, 'rate' => '100']],
        );

        return app(DeliveryChallanService::class)->confirm($challan);
    }

    /**
     * @return array<string, mixed>
     */
    private function screen(): array
    {
        $seen = [];

        View::composer('sales::lot.trace', function ($view) use (&$seen) {
            $seen = $view->getData();
        });

        $this->get(route('sales.lot.trace', ['batch' => $this->batch->id]))->assertOk();

        return $seen;
    }

    // ── উত্তরটা ──────────────────────────────────────────────────

    public function test_the_screen_names_who_received_the_lot(): void
    {
        $this->sell('4');

        $recipients = $this->screen()['recipients'];

        $this->assertCount(1, $recipients);
        $this->assertSame($this->customer->id, (int) $recipients->first()->customer_id);
        $this->assertSame(0, bccomp('4', (string) $recipients->first()->qty, 4));
    }

    /** দুইজন গ্রাহক, দুইটা সারি — কে কতটা পেয়েছেন আলাদা করে। */
    public function test_two_customers_appear_separately(): void
    {
        $other = Customer::query()->where('id', '!=', $this->customer->id)->first();

        if ($other === null) {
            $this->markTestSkipped('ডেমোতে দ্বিতীয় গ্রাহক নেই।');
        }

        $this->sell('4');
        $this->sell('6', $other);

        $recipients = $this->screen()['recipients'];

        $this->assertCount(2, $recipients);
        $this->assertSame(0, bccomp('10', (string) $recipients->sum(fn ($r) => (float) $r->qty), 4));
    }

    /**
     * তাকেরটা আর গ্রাহকেরটা আলাদা সংখ্যা।
     *
     * একটা যোগফলে মিলিয়ে দিলে দোকানি জানতেন কত পিস বাজারে আছে, কিন্তু
     * কোনটা নিয়ে কী করবেন জানতেন না — একটায় হাত, অন্যটায় ফোন।
     */
    public function test_shelf_and_customers_are_two_different_numbers(): void
    {
        $this->sell('30');

        $seen = $this->screen();

        $this->assertSame(0, bccomp('70', $seen['onHand'], 4));
        $this->assertSame(0, bccomp('30', (string) $seen['recipients']->sum(fn ($r) => (float) $r->qty), 4));
    }

    /**
     * বাতিল হওয়া চালানের গ্রাহক তালিকায় থাকেন না।
     *
     * মালটা ফিরে এসেছে, অর্থাৎ তাঁর কাছে কখনো পৌঁছায়নি। থাকলে রিকলে
     * তাঁকে অকারণে ফোন করা হত — আর সত্যিকারের ক্রেতাদের তালিকাটা
     * অবিশ্বাস্য হয়ে যেত।
     */
    public function test_a_cancelled_challan_drops_off_the_list(): void
    {
        $challan = $this->sell('5');

        app(DeliveryChallanService::class)->cancel($challan, 'পরীক্ষা');

        $recipients = $this->screen()['recipients'];

        $this->assertSame(
            0,
            bccomp('0', (string) $recipients->sum(fn ($r) => (float) $r->qty), 4),
            'বাতিল চালানের মাল এখনো গ্রাহকের নামে দেখাচ্ছে',
        );
    }

    /** কোনো মাল না গেলে তালিকা খালি, আর পর্দা তা বলেও দেয়। */
    public function test_a_lot_that_never_left_says_so(): void
    {
        $this->assertCount(0, $this->screen()['recipients']);
    }

    // ── পাহারা ───────────────────────────────────────────────────

    /**
     * মজুদ দেখার অনুমতিই যথেষ্ট — দাম বদলানোর অনুমতি লাগে না।
     *
     * রিকলের মুহূর্তে কাজটা গুদামের লোকের হাতে। দাম বদলানোর অনুমতির
     * পেছনে রাখলে তিনি নিজে খুঁজতেই পারতেন না।
     */
    public function test_a_storekeeper_can_trace_without_the_reprice_permission(): void
    {
        $role = Role::findOrCreate('store-hand');
        $role->syncPermissions(Permission::query()->whereIn('name', [
            'sales.challan.view',
        ])->get());

        $hand = User::factory()->create();
        $hand->companies()->attach(CompanyContext::id(), ['is_active' => true]);
        $hand->forceFill(['current_company_id' => CompanyContext::id()])->save();
        $hand->assignRole($role);

        $this->actingAs($hand)
            ->get(route('sales.lot.trace', ['batch' => $this->batch->id]))
            ->assertOk();
    }

    /** মজুদ দেখার অনুমতি না থাকলে পর্দাটাই খোলে না। */
    public function test_without_stock_view_the_screen_is_closed(): void
    {
        $stranger = User::factory()->create();
        $stranger->companies()->attach(CompanyContext::id(), ['is_active' => true]);
        $stranger->forceFill(['current_company_id' => CompanyContext::id()])->save();

        $this->actingAs($stranger)
            ->get(route('sales.lot.trace', ['batch' => $this->batch->id]))
            ->assertForbidden();
    }
}
