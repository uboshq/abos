<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\QualityInspection;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchase\Events\GoodsReceived;
use App\Modules\Purchase\Models\PurchaseReceipt;
use App\Modules\Purchase\Services\PurchaseReceiptService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * পরিদর্শনের টিকটা কিছুই থামাত না।
 *
 * ── ⛔ যে ফাঁকটা ছিল ─────────────────────────────────────────────────
 * পণ্যের গায়ে `qc_required` ঘরটা ছিল, পরিদর্শনের পর্দাও ছিল, আর পর্দাটা
 * ঐ ঘর ধরে পণ্যের তালিকা ছাঁকতও। ⚠️ কিন্তু **মাল এলে কিছুই হত না** —
 * গুদামের লোককে মনে করে কাগজটা খুলতে হত।
 *
 * ⓘ অর্থাৎ ঘরটা কার্যত সাজসজ্জা: টিক দেওয়া থাক বা না থাক, মাল একইভাবে
 * গুদামে উঠত। ⛔ আর এটাই এই কোডবেসের চেনা রোগ — যন্ত্রটা তৈরি, জোড়াটা
 * নেই, আর কোথাও লাল হয় না।
 *
 * ── ⚠️ এই ফাইল যা পাহারা দেয় ────────────────────────────────────────
 *   ১. টিক দেওয়া পণ্যের মাল এলে কাগজটা নিজেই খোলে
 *   ২. টিক না থাকলে খোলে **না** — নাহলে তালিকাটা সব পণ্যে ভরে যেত
 *   ৩. কাগজটা চালানের দিকে ফেরত দেখায় (উৎস বসে)
 *   ৪. একই চালান দুইবার শুনলেও কাগজ একটাই
 *   ৫. ঘটনাটা সত্যিই ছোড়া হয় — জোড়ার নিচের প্রান্ত
 *
 * ⓘ (৫) আলাদা করে দেখা হয়, কারণ উপরের চারটা সবুজ থাকত যদি কেউ
 * পরে শ্রোতাটাকে সরাসরি ডাকত — আর তখন ইভেন্টের গোটা যুক্তিটাই (ব্যর্থ
 * হলে গ্রহণ ফিরে যাবে না) নীরবে হারিয়ে যেত।
 */
final class TheInspectionFlagNeverStoppedAnythingTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Supplier $supplier;

    private Warehouse $warehouse;

    /** টিক দেওয়া — এর মাল এলে কাগজ খোলার কথা। */
    private Product $watched;

    /** টিক ছাড়া — এর জন্য কিছুই হওয়ার কথা নয়। */
    private Product $plain;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        $this->supplier = Supplier::query()->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();

        [$this->watched, $this->plain] = Product::query()
            ->where('track_batch', false)->orderBy('id')->take(2)->get()->all();

        $this->watched->forceFill(['qc_required' => true])->save();
        $this->plain->forceFill(['qc_required' => false])->save();
    }

    // ── ১ ও ৩ · কাগজটা খোলে, আর চালানের দিকে দেখায় ──────────────────

    public function test_receiving_watched_goods_opens_the_paper_by_itself(): void
    {
        $receipt = $this->receive($this->watched, '40');

        $paper = $this->paperFor($receipt, $this->watched);

        $this->assertNotNull($paper,
            'টিক দেওয়া পণ্যের ৪০ বস্তা গুদামে উঠেছে, অথচ পরিদর্শনের কাগজ '
            .'খোলেনি — টিকটা তাহলে সাজসজ্জা, আর মালটা কেউ না দেখেই '
            .'বিক্রি হয়ে যেতে পারত।');

        $this->assertSame(QualityInspection::PENDING, $paper->status,
            'কাগজটা খুলেই কোনো একটা রায় নিয়ে বসেছে — রায় দেওয়ার কথা '
            .'পরিদর্শকের, যন্ত্রের নয়।');

        $this->assertSame(0, bccomp((string) $paper->inspected_qty, '40', 4),
            'কাগজে পরিমাণটা যা এসেছে তা নয়।');
    }

    public function test_the_paper_points_back_at_the_receipt(): void
    {
        /*
         * ⓘ উৎস ছাড়া কাগজটা অনাথ: *"এই পরিদর্শনটা কোন চালানের"* প্রশ্নের
         * উত্তর তখন কেবল তারিখ মিলিয়ে আন্দাজ। ⛔ আর "দুইবার নয়" শর্তটাও
         * ঠিক এই দুইটা ঘরের উপরেই দাঁড়ানো।
         */
        $receipt = $this->receive($this->watched, '40');

        $paper = $this->paperFor($receipt, $this->watched);

        $this->assertNotNull($paper);
        $this->assertSame(PurchaseReceipt::STOCK_SOURCE, $paper->source_type);
        $this->assertSame($receipt->id, (int) $paper->source_id);
    }

    // ── ২ · টিক ছাড়া পণ্যে কিছুই হয় না ───────────────────────────────

    public function test_goods_nobody_asked_to_inspect_open_nothing(): void
    {
        /*
         * ⚠️ উপরের পাহারাটা উল্টো দিকেও সবুজ থাকত: কোড যদি **প্রতিটা**
         * পণ্যের জন্য কাগজ খুলত, তালিকাটা এত লম্বা হত যে কেউ আর পড়ত না,
         * আর যে তিনটা সত্যিই দেখার দরকার সেগুলো ভিড়ে হারাত।
         */
        $receipt = $this->receive($this->plain, '40');

        $this->assertNull($this->paperFor($receipt, $this->plain),
            'পরিদর্শন লাগে না এমন পণ্যের জন্যও কাগজ খুলেছে।');
    }

    // ── ৪ · দুইবার শুনলেও কাগজ একটাই ─────────────────────────────────

    public function test_hearing_the_same_arrival_twice_opens_only_one_paper(): void
    {
        /*
         * ⓘ আজ ঘটনা একবারই আসে, কিন্তু কিউ এলে *"at least once"*
         * পৌঁছানোই স্বাভাবিক। ⛔ তখন এই শর্তটা না থাকলে প্রতিটা
         * পুনঃচেষ্টায় একটা করে নতুন QC নম্বর পুড়ত, আর তালিকাটা একই
         * মালে ভরে যেত।
         */
        $receipt = $this->receive($this->watched, '40');

        event(GoodsReceived::from($receipt->fresh()));

        $this->assertSame(1, $this->papersFor($receipt, $this->watched)->count(),
            'একই চালানের একই মালের জন্য একাধিক পরিদর্শনের কাগজ খুলেছে।');
    }

    // ── ৫ · জোড়ার নিচের প্রান্ত ──────────────────────────────────────

    public function test_confirming_a_receipt_really_fires_the_event(): void
    {
        /*
         * ⛔ কেউ যদি শ্রোতাটাকে [[PurchaseReceiptService]] থেকে সরাসরি
         * ডাকত, উপরের চারটা দাবিই সবুজ থাকত — ⚠️ অথচ ইভেন্টের গোটা
         * যুক্তিটা (শ্রোতা ব্যর্থ হলে গ্রহণ ফিরে যাবে না) নীরবে হারাত।
         */
        Event::fake([GoodsReceived::class]);

        $receipt = $this->receive($this->watched, '40');

        Event::assertDispatched(
            GoodsReceived::class,
            fn (GoodsReceived $event) => (int) ($event->payload['source_id'] ?? 0) === $receipt->id
                && ($event->payload['source_type'] ?? null) === PurchaseReceipt::STOCK_SOURCE,
        );
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    private function receive(Product $product, string $qty): PurchaseReceipt
    {
        $service = app(PurchaseReceiptService::class);

        $receipt = $service->create(
            [
                'supplier_id' => $this->supplier->id,
                'warehouse_id' => $this->warehouse->id,
                'trx_date' => now()->toDateString(),
            ],
            [['product_id' => $product->id, 'received_qty' => $qty, 'rate' => '100']],
        );

        return $service->confirm($receipt);
    }

    private function paperFor(PurchaseReceipt $receipt, Product $product): ?QualityInspection
    {
        return $this->papersFor($receipt, $product)->first();
    }

    /** @return \Illuminate\Support\Collection<int, QualityInspection> */
    private function papersFor(PurchaseReceipt $receipt, Product $product)
    {
        return QualityInspection::query()
            ->where('source_type', PurchaseReceipt::STOCK_SOURCE)
            ->where('source_id', $receipt->id)
            ->where('product_id', $product->id)
            ->get();
    }
}
