<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\LedgerEntry;
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
 * গুদাম থেকে গুদামে স্টক স্থানান্তর।
 *
 * ── এখানকার আসল প্রশ্ন ──────────────────────────────────────────────
 * রওনা আর পৌঁছানোর মাঝখানে মালটা কোথায় থাকে? এক ধাপে করলে উত্তর হত
 * "গন্তব্যে" — যা মিথ্যা, আর ওই মিথ্যার উপর দাঁড়িয়ে গন্তব্যের লোক
 * এমন মাল বেচার কথা দিতেন যা তখনো ট্রাকে।
 */
class StockTransferTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    private Warehouse $from;

    private Warehouse $to;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->user = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs($this->user);

        $this->from = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->to = Warehouse::query()->where('id', '<>', $this->from->id)->firstOrFail();
        $this->product = Product::query()->orderBy('id')->firstOrFail();
    }

    /**
     * খসড়ায় কিছুই নড়ে না।
     */
    public function test_a_draft_moves_nothing(): void
    {
        $available = $this->availableAt($this->from);

        $this->transfers()->create($this->document(), [['product_id' => $this->product->id, 'qty' => '5']]);

        $this->assertSame(0, bccomp($available, $this->availableAt($this->from), 4));
    }

    /**
     * রওনা দিলে মাল উৎসেই থাকে, কিন্তু আর বেচা যায় না।
     *
     * ── কেন floor কমে না ────────────────────────────────────────────
     * ট্রাক ছাড়লেও মালটা কোম্পানির, আর গন্তব্যে পৌঁছায়নি। floor কমিয়ে
     * দিলে ওই সময়টুকুতে মালটা কোথাও থাকত না — না উৎসে, না গন্তব্যে —
     * আর মাস শেষে গুনতে গিয়ে ঘাটতি দেখাত।
     */
    public function test_dispatch_holds_the_goods_at_the_source(): void
    {
        $floorBefore = $this->floorAt($this->from);
        $availableBefore = $this->availableAt($this->from);
        $destinationBefore = $this->availableAt($this->to);

        $transfer = $this->transfers()->dispatch(
            $this->transfers()->create($this->document(), [['product_id' => $this->product->id, 'qty' => '5']])
        );

        $this->assertSame(DocumentStatus::CONFIRMED, $transfer->status);

        // তাকে মাল আগের মতোই
        $this->assertSame(0, bccomp($floorBefore, $this->floorAt($this->from), 4));

        // কিন্তু বিক্রয়যোগ্য পাঁচটা কমেছে
        $this->assertSame(0, bccomp(bcsub($availableBefore, '5', 4), $this->availableAt($this->from), 4));

        // আর গন্তব্যে এখনো কিছুই যায়নি
        $this->assertSame(0, bccomp($destinationBefore, $this->availableAt($this->to), 4));
    }

    /**
     * বুঝে নিলে উৎস ছাড়ে, গন্তব্যে ঢোকে।
     */
    public function test_receiving_moves_the_goods_across(): void
    {
        $sourceBefore = $this->floorAt($this->from);
        $destinationBefore = $this->floorAt($this->to);

        /*
         * আটকানো মালের হিসাব শূন্য ধরে নেওয়া যায় না।
         *
         * ডেমো গুদামে আগে থেকেই কিছু মাল আটকানো আছে (ক্ষতি, মেয়াদ,
         * দাম-হোল্ড)। "শেষে hold শূন্য" ধরলে পরীক্ষাটা স্থানান্তরের
         * নয়, ডেমো ডাটার আকার পরীক্ষা করত — আর কেউ একটা হোল্ড যোগ
         * করলেই ভাঙত।
         */
        $holdBefore = $this->holdAt($this->from);

        $transfer = $this->transfers()->receive(
            $this->transfers()->dispatch(
                $this->transfers()->create($this->document(), [['product_id' => $this->product->id, 'qty' => '5']])
            )
        );

        $this->assertSame(DocumentStatus::CLOSED, $transfer->status);

        $this->assertSame(0, bccomp(bcsub($sourceBefore, '5', 4), $this->floorAt($this->from), 4));
        $this->assertSame(0, bccomp(bcadd($destinationBefore, '5', 4), $this->floorAt($this->to), 4));

        // স্থানান্তরের আটকানো মালটাও ছেড়ে গেছে — আগের অবস্থায় ফিরেছে
        $this->assertSame(0, bccomp($holdBefore, $this->holdAt($this->from), 4));
    }

    /**
     * যা নেই তা পাঠানো যায় না।
     */
    public function test_stock_that_is_not_there_cannot_be_sent(): void
    {
        $transfer = $this->transfers()->create(
            $this->document(),
            [['product_id' => $this->product->id, 'qty' => '99999']],
        );

        $this->expectException(ValidationException::class);

        $this->transfers()->dispatch($transfer);
    }

    /**
     * একই গুদামে স্থানান্তর হয় না।
     *
     * হতে দিলে একটা কাগজ তৈরি হত যা কিছুই করে না, আর কেউ ভাবত মাল
     * সরানো হয়েছে।
     */
    public function test_a_warehouse_cannot_transfer_to_itself(): void
    {
        $this->expectException(ValidationException::class);

        $this->transfers()->create(
            [
                'from_warehouse_id' => $this->from->id,
                'to_warehouse_id' => $this->from->id,
                'trx_date' => now()->toDateString(),
            ],
            [['product_id' => $this->product->id, 'qty' => '5']],
        );
    }

    /**
     * রওনার পর বাতিল করলে আটকানো মাল ছাড়া পায়।
     */
    public function test_cancelling_a_dispatch_releases_the_hold(): void
    {
        $availableBefore = $this->availableAt($this->from);

        $transfer = $this->transfers()->dispatch(
            $this->transfers()->create($this->document(), [['product_id' => $this->product->id, 'qty' => '5']])
        );

        $this->transfers()->cancel($transfer, 'ট্রাক ফিরে এসেছে');

        $this->assertSame(DocumentStatus::CANCELLED, $transfer->fresh()->status);
        $this->assertSame(0, bccomp($availableBefore, $this->availableAt($this->from), 4));
    }

    /**
     * পৌঁছে যাওয়ার পর বাতিল নয়।
     *
     * মালটা সত্যিই অন্য গুদামে চলে গেছে — কাগজ ছিঁড়ে সেটা ফেরত আসে না।
     * ফেরাতে হলে উল্টো দিকে আরেকটা স্থানান্তর, আর তাতে দুইটা যাত্রাই
     * খাতায় থাকে।
     */
    public function test_a_received_transfer_cannot_be_cancelled(): void
    {
        $transfer = $this->transfers()->receive(
            $this->transfers()->dispatch(
                $this->transfers()->create($this->document(), [['product_id' => $this->product->id, 'qty' => '5']])
            )
        );

        $this->expectException(ValidationException::class);

        $this->transfers()->cancel($transfer, 'ভুল হয়েছিল');
    }

    /**
     * খতিয়ানে কিছুই বসে না।
     *
     * একই কোম্পানির দুই গুদামের মধ্যে মাল সরলে মজুদের মূল্য বদলায় না।
     * দাখিলা বসালে ডেবিট-ক্রেডিট দুইটাই একই খাতে যেত — একটা অর্থহীন সারি,
     * যা পরে কেউ রিপোর্টে দেখে ব্যাখ্যা খুঁজত।
     */
    public function test_nothing_reaches_the_ledger(): void
    {
        $transfer = $this->transfers()->receive(
            $this->transfers()->dispatch(
                $this->transfers()->create($this->document(), [['product_id' => $this->product->id, 'qty' => '5']])
            )
        );

        $this->assertSame(0, LedgerEntry::query()
            ->where('source_type', StockTransfer::drillSourceType())
            ->where('source_id', $transfer->id)
            ->count());
    }

    /**
     * পর্দাগুলো খোলে।
     */
    public function test_the_screens_open(): void
    {
        $transfer = $this->transfers()->create(
            $this->document(),
            [['product_id' => $this->product->id, 'qty' => '5']],
        );

        $this->get(route('inventory.transfer.index'))->assertOk()->assertSee($transfer->document_no);
        $this->get(route('inventory.transfer.create'))->assertOk();
        $this->get(route('inventory.transfer.show', $transfer))->assertOk();
        $this->get(route('inventory.transfer.edit', $transfer))->assertOk();
    }

    // ── সহায়ক ───────────────────────────────────────────────────────────

    private function transfers(): StockTransferService
    {
        return app(StockTransferService::class);
    }

    /** @return array<string, mixed> */
    private function document(): array
    {
        return [
            'from_warehouse_id' => $this->from->id,
            'to_warehouse_id' => $this->to->id,
            'trx_date' => now()->toDateString(),
        ];
    }

    private function availableAt(Warehouse $warehouse): string
    {
        return app(StockService::class)->availableQty($this->product, $warehouse);
    }

    private function floorAt(Warehouse $warehouse): string
    {
        return app(StockService::class)->floorQty($this->product, $warehouse);
    }

    private function holdAt(Warehouse $warehouse): string
    {
        return app(StockService::class)->holdQty($this->product, $warehouse);
    }
}
