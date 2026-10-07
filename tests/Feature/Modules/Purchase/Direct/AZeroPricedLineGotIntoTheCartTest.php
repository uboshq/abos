<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase\Direct;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Services\DirectPurchaseService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * দর ০ বা বিক্রয়দর ছাড়া সারি কার্টে ঢুকত — মালিক, ৩ অক্টোবর ২০২৬: *"0 price e add hobe na"*।
 *
 * ⓘ পর্দার দেয়াল `direct-purchase.test.js`-এ মাপা; এখানে সার্ভারের দেয়াল — পর্দা এড়িয়ে পাঠানো অনুরোধ,
 * আর পর্দা ছাড়া সরাসরি [[DirectPurchaseService::complete()]] ডাকা। ⭐ উপহারের সারি দর ০-ই, ওটা চলে।
 */
final class AZeroPricedLineGotIntoTheCartTest extends TestCase
{
    use RefreshDatabase;

    private User $buyer;

    private Product $plain;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        app(StandardChart::class)->install();

        $this->buyer = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        // ⓘ লট-ছাড়া পণ্য — লট ধরা পণ্য লটের নিয়মে আগেই থামত, তখন এই দাবি দামের নিয়ম মাপত না
        $this->plain = Product::query()->where('track_batch', false)->firstOrFail();
    }

    public function test_the_door_refuses_a_line_at_rate_zero(): void
    {
        $this->actingAs($this->buyer)
            ->post(route('purchase.direct.store'), $this->payload(rate: '0', salesPrice: '100'))
            ->assertSessionHasErrors('lines.0.rate');

        $this->assertSame(0, PurchaseBill::query()->count(), '⛔ দর ০-র সারিসহ বিল বসে গেছে।');
    }

    public function test_the_door_refuses_a_line_without_a_sales_price(): void
    {
        $this->actingAs($this->buyer)
            ->post(route('purchase.direct.store'), $this->payload(rate: '100', salesPrice: null))
            ->assertSessionHasErrors('lines.0.sales_price');

        $this->assertSame(0, PurchaseBill::query()->count(), '⛔ বিক্রয়দর ছাড়া সারিসহ বিল বসে গেছে।');
    }

    /** ⛔ পর্দা ছাড়া ডাকলেও — সার্ভিসটাই আসল দেয়াল */
    public function test_the_service_itself_refuses_both(): void
    {
        $this->actingAs($this->buyer);

        foreach ([['0', '100', 'lines.0.rate'], ['100', '0', 'lines.0.sales_price'], ['100', '', 'lines.0.sales_price']] as [$rate, $sp, $key]) {
            try {
                app(DirectPurchaseService::class)->complete($this->billHead(), [$this->line($rate, $sp)]);
                $this->fail("⛔ দর {$rate} / বিক্রয়দর '{$sp}' — সার্ভিস সারিটা নিয়ে নিল।");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($key, $e->errors(), "ভুলটা {$key}-এ বসার কথা।");
            }
        }

        $this->assertSame(0, PurchaseBill::query()->count());
    }

    /** ⭐ দাম দেওয়া সারি আর দর ০-র উপহার একসাথে চলে — দেয়ালটা উপহার আটকায় না */
    public function test_a_priced_line_with_a_free_gift_still_goes_through(): void
    {
        $gift = Product::query()->where('track_batch', false)->whereKeyNot($this->plain->id)->firstOrFail();

        $this->actingAs($this->buyer)
            ->post(route('purchase.direct.store'), $this->payload(rate: '100', salesPrice: '120') + [
                'gifts' => [['product_id' => $gift->id, 'qty' => '2', 'against_product_id' => $this->plain->id]],
            ])
            ->assertSessionHasNoErrors();

        $bill = PurchaseBill::query()->latest('id')->firstOrFail();
        $this->assertCount(1, $bill->giftLines, '⛔ উপহারের সারিটা বিলে বসেনি।');
    }

    /** @return array<string, mixed> */
    private function payload(string $rate, ?string $salesPrice): array
    {
        return $this->billHead() + ['lines' => [$this->line($rate, $salesPrice)]];
    }

    /** @return array<string, mixed> */
    private function billHead(): array
    {
        return [
            'supplier_id' => Supplier::query()->firstOrFail()->id,
            'warehouse_id' => Warehouse::query()->where('is_default', true)->firstOrFail()->id,
            'trx_date' => now()->toDateString(),
        ];
    }

    /** @return array<string, mixed> */
    private function line(string $rate, ?string $salesPrice): array
    {
        return array_filter(
            ['product_id' => $this->plain->id, 'qty' => '10', 'rate' => $rate, 'sales_price' => $salesPrice],
            fn ($v) => $v !== null,
        );
    }
}
