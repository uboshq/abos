<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * একটা বিল এল, আর বসানোর পর্দায় দুইটা কাগজ হয়ে বসল।
 *
 * ── ⓘ মালিকের প্রশ্ন, ২২ সেপ্টেম্বর ২০২৬ ────────────────────────────
 * *"একটা বিল ক্রয় হলো, কিন্তু দুটো ভাগ কেন?"* — একই বিল (PBL-0004)
 * পর্দায় দুইটা কার্ড: একটার আইডি `purchase_bill-free:8`, অন্যটার
 * `purchase_bill-8`।
 *
 * ── ⚠️ কেন হত ───────────────────────────────────────────────────────
 * ফ্রি মাল নিজের উৎস-নামে চলে (`…:free`), আর কারণটা ঠিকই আছে: ফ্রি
 * কার্টনের ক্রয়মূল্য নেই, তাই সে আলাদা ভাণ্ডারে ঢোকে আর বাতিলের সময়
 * আলাদা করে চেনা যায়।
 *
 * ⛔ কিন্তু ওটা **হিসাবের ভাগ**, আর বসানোর পর্দা **কাজের পর্দা**।
 * গুদামের লোকের কাছে একটাই লরি, একটাই কাগজ — তাঁকে একই বিল দুইবার
 * খুঁজে বের করতে হত। ⚠️ আর দুইটা কার্ডেই অর্ধেক ঘর মৃত: ফ্রি কার্ডে
 * টাকার ঘরে `0.0000`, টাকার কার্ডে ফ্রির ঘরে `—`।
 */
final class OneBillArrivedAsTwoPapersToPutAwayTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->orderBy('id')->firstOrFail();
    }

    /**
     * ⭐ টাকার মাল আর ফ্রি মাল — একই বিল, একটাই কার্ড।
     */
    public function test_the_paid_and_the_free_goods_share_one_paper(): void
    {
        $this->goodsArrived();

        $papers = $this->papersOnScreen();

        $this->assertCount(
            1,
            $papers,
            'একই বিল এখনো '.count($papers).'টা কার্ড হয়ে বসছে।',
        );
    }

    /**
     * ⛔ আর কার্ডটায় দুই রকম মালই থাকে — একটা বাদ পড়ে না।
     *
     * ── ⚠️ কেন এই দাবিটা আলাদা ──────────────────────────────────────
     * ⓘ উপরেরটা কেবল গোনে। ⛔ দল বাঁধার সময় ফ্রি সারিটা **চাপা পড়ে
     * গেলেও** ওটা সবুজ থাকত — একটাই কার্ড, কিন্তু ফ্রি মাল উধাও, আর
     * সেটা আগের অবস্থার চেয়েও খারাপ।
     */
    public function test_neither_kind_of_goods_goes_missing(): void
    {
        $this->goodsArrived();

        $paper = $this->papersOnScreen()[0];

        $waiting = collect($paper['lines'])->sum(fn (array $l) => (float) $l['waiting']);
        $free = collect($paper['lines'])->sum(fn (array $l) => (float) $l['waiting_free']);

        $this->assertSame(10.0, $waiting, 'টাকার মালটা কার্ডে নেই।');
        $this->assertSame(2.0, $free, 'ফ্রি মালটা কার্ডে নেই।');
    }

    /**
     * ⭐ আর ফ্রি সারিটা নিজের উৎস-নামটা সাথে নিয়েই চলে।
     *
     * ── ⛔ কেন এটাই সবচেয়ে জরুরি দাবি ──────────────────────────────
     * বসানোর সারিটা **যে উৎসে এসেছিল ঠিক সেই উৎসেই** লিখতে হয়।
     * ⚠️ কার্ড এক করতে গিয়ে সারিটাকেও মূল কাগজের নামে পাঠালে আসা আর
     * বসানো দুইটা আলাদা দলে পড়ত, যোগফল কাটাকাটি হত না, আর কাগজটা
     * তালিকা থেকে **কোনোদিন সরত না** — মাল বসে যাওয়ার পরেও।
     *
     * ⓘ ঐ ভুলটা এই রিপোতে একবার হয়েই গেছে (৪ সেপ্টেম্বর ২০২৬), আর
     * কোড পড়ে ধরা পড়েনি — ব্রাউজারে বসিয়ে তারপর পাতা খুলে ধরা পড়ে।
     */
    public function test_the_free_line_keeps_its_own_source(): void
    {
        $this->goodsArrived();

        $sources = collect($this->papersOnScreen()[0]['lines'])
            ->pluck('source_type')
            ->unique()
            ->values();

        $this->assertTrue(
            $sources->contains(fn (string $s) => str_contains($s, ':free')),
            'ফ্রি সারিটা নিজের উৎস-নাম হারিয়েছে — বসানোর পর কাগজটা আর সরবে না।',
        );

        $this->assertTrue(
            $sources->contains(fn (string $s) => ! str_contains($s, ':free')),
            'টাকার সারিটার উৎস-নাম হারিয়েছে।',
        );
    }

    /** একই কাগজে দশটা টাকার মাল আর দুইটা ফ্রি — দুই উৎসে, যেভাবে ক্রয় লেখে। */
    private function goodsArrived(): void
    {
        $stock = app(StockService::class);

        $stock->move(
            product: $this->product,
            warehouse: $this->warehouse,
            sourceType: 'purchase_bill',
            sourceId: 4242,
            documentNo: 'PBL-4242',
            unplaced: '10',
        );

        $stock->move(
            product: $this->product,
            warehouse: $this->warehouse,
            sourceType: 'purchase_bill:free',
            sourceId: 4242,
            documentNo: 'PBL-4242',
            unplacedFree: '2',
        );
    }

    /**
     * পর্দার কাগজগুলো — কেবল আমাদের বসানো বিলটা।
     *
     * ⓘ ডেমোতে আগে থেকেই কিছু কাগজ থাকতে পারে, তাই গোনার আগে ছাঁকা
     * হয় — নাহলে দাবিটা ডেমোর অবস্থা মাপত, সারাইটা নয়।
     *
     * @return list<array<string, mixed>>
     */
    private function papersOnScreen(): array
    {
        $papers = $this->get(route('inventory.stock.placement'))
            ->assertOk()
            ->viewData('papers');

        return collect($papers)
            ->filter(fn (array $p) => (int) $p['source_id'] === 4242)
            ->values()
            ->all();
    }
}
