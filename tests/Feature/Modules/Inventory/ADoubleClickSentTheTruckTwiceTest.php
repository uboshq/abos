<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Inventory\Services\StockTransferService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * দুইবার চাপ দিলে ট্রাক দুইবার ছাড়ত — ২৯ সেপ্টেম্বর ২০২৬।
 *
 * ── ⛔ যে ফাঁকটা ছিল ─────────────────────────────────────────────────
 * রওনা, বুঝে নেওয়া আর বাতিল — তিনটাতেই অবস্থার প্রশ্নটা করা হত
 * লেনদেনের **বাইরে**, হাতে থাকা পুরনো মডেলের উপর, সারি না আটকে।
 * ⚠️ দুইবার চাপ (বা দুইজন একসাথে) মানে দুইটা অনুরোধই পুরনো অবস্থা
 * দেখে, আর দুইটাই মাল সরায়:
 *   • দুইবার বুঝে নেওয়া — উৎস থেকে দুইবার বেরোয়, গন্তব্যে দুইবার ঢোকে
 *   • দুইবার রওনা — আটকানো মাল দ্বিগুণ
 *   • দুইবার বাতিল — আটকানো মাল শূন্যের নিচে
 *
 * ⓘ দুই অনুরোধকে এখানে পরপর চালানো হয়: দুইটা মডেল আগে থেকে পড়ে রাখা,
 * তারপর একটা দিয়ে কাজ, তারপর পুরনো অবস্থার অন্যটা দিয়ে আবার।
 * দ্বিতীয়টা প্রত্যাখ্যাত হওয়া উচিত, আর মজুদ একবারের মতোই থাকা উচিত।
 */
final class ADoubleClickSentTheTruckTwiceTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Product $product;

    private Warehouse $from;

    private Warehouse $to;

    private StockService $stock;

    private StockTransferService $transfers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        $this->from = $this->store('DBL-WH-A', 'Double click store A');
        $this->to = $this->store('DBL-WH-B', 'Double click store B');

        $this->product = Product::query()->where('track_batch', false)->orderBy('id')->firstOrFail();

        $this->stock = app(StockService::class);
        $this->transfers = app(StockTransferService::class);

        $this->stock->move(
            product: $this->product,
            warehouse: $this->from,
            sourceType: 'test_seed',
            sourceId: $this->product->id,
            floor: '40',
        );
    }

    public function test_a_second_dispatch_from_a_stale_page_does_not_hold_the_goods_twice(): void
    {
        $draft = $this->draft();

        [$first, $second] = $this->twoCopies($draft);

        $this->transfers->dispatch($first);
        $refused = $this->refused(fn () => $this->transfers->dispatch($second));

        $this->assertStock(from: '40', fromHold: '10', to: '0', label: 'দুইবার রওনা');
        $this->assertTrue($refused, 'দ্বিতীয় রওনা প্রত্যাখ্যাত হয়নি।');
    }

    public function test_a_second_receive_from_a_stale_page_does_not_move_the_goods_twice(): void
    {
        $dispatched = $this->transfers->dispatch($this->draft());

        [$first, $second] = $this->twoCopies($dispatched);

        $this->transfers->receive($first);
        $refused = $this->refused(fn () => $this->transfers->receive($second));

        $this->assertStock(from: '30', fromHold: '0', to: '10', label: 'দুইবার বুঝে নেওয়া');
        $this->assertTrue($refused, 'দ্বিতীয় বুঝে নেওয়া প্রত্যাখ্যাত হয়নি।');
    }

    public function test_a_second_cancel_from_a_stale_page_does_not_release_the_hold_twice(): void
    {
        $dispatched = $this->transfers->dispatch($this->draft());

        [$first, $second] = $this->twoCopies($dispatched);

        $this->transfers->cancel($first, 'ট্রাক ফিরে এসেছে');
        $refused = $this->refused(fn () => $this->transfers->cancel($second, 'আবার'));

        $this->assertStock(from: '40', fromHold: '0', to: '0', label: 'দুইবার বাতিল');
        $this->assertTrue($refused, 'দ্বিতীয় বাতিল প্রত্যাখ্যাত হয়নি।');
    }

    public function test_a_stale_cancel_cannot_undo_a_transfer_that_has_already_arrived(): void
    {
        $dispatched = $this->transfers->dispatch($this->draft());

        [$first, $second] = $this->twoCopies($dispatched);

        $this->transfers->receive($first);
        $refused = $this->refused(fn () => $this->transfers->cancel($second, 'পুরনো পাতা'));

        $this->assertStock(from: '30', fromHold: '0', to: '10', label: 'পৌঁছানোর পর বাতিল');
        $this->assertTrue($refused, 'পৌঁছে যাওয়া স্থানান্তর পুরনো পাতা থেকে বাতিল হয়ে গেল।');
        $this->assertSame(DocumentStatus::CLOSED, $dispatched->fresh()->status);
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    private function draft(): StockTransfer
    {
        return $this->transfers->create(
            [
                'from_warehouse_id' => $this->from->id,
                'to_warehouse_id' => $this->to->id,
                'trx_date' => now()->toDateString(),
            ],
            [['product_id' => $this->product->id, 'qty' => '10']],
        );
    }

    /** @return array{0: StockTransfer, 1: StockTransfer} */
    private function twoCopies(StockTransfer $transfer): array
    {
        return [
            StockTransfer::query()->findOrFail($transfer->id),
            StockTransfer::query()->findOrFail($transfer->id),
        ];
    }

    private function refused(callable $action): bool
    {
        try {
            $action();
        } catch (ValidationException) {
            return true;
        }

        return false;
    }

    private function assertStock(string $from, string $fromHold, string $to, string $label): void
    {
        $this->assertSame(0, bccomp($this->stock->floorQty($this->product, $this->from), $from, 4),
            "{$label}: উৎসের তাকে ".$this->stock->floorQty($this->product, $this->from)." — থাকার কথা {$from}।");

        $this->assertSame(0, bccomp($this->stock->holdQty($this->product, $this->from), $fromHold, 4),
            "{$label}: উৎসে আটকানো ".$this->stock->holdQty($this->product, $this->from)." — থাকার কথা {$fromHold}।");

        $this->assertSame(0, bccomp($this->stock->floorQty($this->product, $this->to), $to, 4),
            "{$label}: গন্তব্যের তাকে ".$this->stock->floorQty($this->product, $this->to)." — থাকার কথা {$to}।");
    }

    private function store(string $code, string $name): Warehouse
    {
        return Warehouse::query()->create([
            'branch_id' => $this->owner->branch_id ?? Branch::query()->firstOrFail()->id,
            'code' => $code,
            'name_en' => $name,
            'name_bn' => $name,
            'is_active' => true,
        ]);
    }
}
