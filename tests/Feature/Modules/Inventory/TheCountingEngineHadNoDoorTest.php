<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockCount;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\CostLayerService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\MasterData\Models\ReasonCode;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * গোনার ইঞ্জিনটা লেখা ছিল, দরজাটা ছিল না।
 *
 * ── ⛔ কী অবস্থায় পাওয়া গেল, ২৪ সেপ্টেম্বর ২০২৬ ───────────────────────
 * [[StockCountService]]-এ `record()` ও `approve()` দুইটাই সম্পূর্ণ, সই ও
 * কারণ-কোড সহ। ⚠️ কিন্তু **কোনো রুট ওটাকে ডাকত না** — গোনার একমাত্র পথ
 * ছিল সমন্বয়ের পর্দার ভিতরে এক সারি, অর্থাৎ একবারে একটা পণ্য।
 *
 * ⛔ গুদাম গোনা মানে একশো পণ্য, আর একশোবার সমন্বয়ের পাতা খোলা কেউ করে
 * না। ⓘ তাই ব্যবস্থাটা ছিল, ব্যবহার হত না — আর কোথাও কিছু লাল হত না।
 *
 * ── ⚠️ এই ফাইল যা পাহারা দেয় ────────────────────────────────────────
 *   ১. দরজাটা সত্যিই আছে, আর গোনা শিট থেকে সেভ হয়
 *   ২. **খালি ঘর ≠ শূন্য** — না-গোনা পণ্যের খাতা অক্ষত থাকে
 *   ৩. গোনায় নিজে থেকে খাতা নড়ে না
 *   ৪. মেনে নেওয়ার চাবি আলাদা — যিনি গোনেন তিনি নিজে মেনে নিতে পারেন না
 *   ৫. মেনে নিলে খাতা সত্যিই বদলায়
 *
 * ⓘ (২) সবচেয়ে বিপজ্জনক। ⛔ খালিকে শূন্য ধরলে একটা অর্ধেক-ভরা শিট
 * সংরক্ষণ করামাত্র বাকি পুরো গুদাম শূন্য হয়ে যেত, আর অনুমোদনের পর সেটা
 * আর ফেরানো যেত না।
 */
final class TheCountingEngineHadNoDoorTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
    }

    // ── ১ · দরজাটা আছে ───────────────────────────────────────────────

    public function test_the_sheet_opens_with_every_product_of_the_warehouse(): void
    {
        $product = $this->stocked('গোনার পণ্য', '40');

        $this->actingAs($this->owner)
            ->get(route('inventory.count.create', ['warehouse' => $this->warehouse->id]))
            ->assertOk()
            ->assertSee($product->name())
            ->assertSee('lines[', escape: false);
    }

    public function test_a_blind_sheet_hides_what_the_books_say(): void
    {
        /*
         * ⚠️ খাতার সংখ্যা চোখের সামনে থাকলে গণনাকারী প্রায়ই ওটাই লিখে
         * দেন। ⓘ অন্ধ গণনার পুরো কথাই সেটা ঠেকানো — তাই সংখ্যাটা
         * পাতায় **থাকা চলবে না**, কেবল লুকানো নয়।
         */
        $this->stocked('অন্ধ গণনার পণ্য', '7777');

        $open = (string) $this->actingAs($this->owner)
            ->get(route('inventory.count.create', ['warehouse' => $this->warehouse->id]))
            ->getContent();

        $blind = (string) $this->actingAs($this->owner)
            ->get(route('inventory.count.create', [
                'warehouse' => $this->warehouse->id,
                'blind' => 1,
            ]))
            ->getContent();

        $this->assertStringContainsString('7777', $open,
            'সাধারণ শিটে খাতার সংখ্যাটাই নেই — তাহলে মেলানোর কিছু থাকে না।');

        $this->assertStringNotContainsString('7777', $blind,
            'অন্ধ গণনার শিটেও খাতার সংখ্যাটা পাতায় রয়ে গেছে, আর ওটাই '
            .'অন্ধ গণনার একমাত্র কারণ ছিল।');
    }

    // ── ২ · খালি ঘর মানে শূন্য নয় ─────────────────────────────────────

    public function test_a_row_left_blank_never_reaches_the_count(): void
    {
        $counted = $this->stocked('যেটা গোনা হলো', '40');
        $skipped = $this->stocked('যেটা গোনা হয়নি', '25');

        $count = $this->record([
            ['product_id' => $counted->id, 'counted_qty' => '38'],
            ['product_id' => $skipped->id, 'counted_qty' => ''],
        ]);

        $this->assertCount(1, $count->lines,
            'খালি ঘরওয়ালা সারিটাও গোনায় ঢুকে গেছে — অর্থাৎ "গোনা হয়নি" আর '
            .'"শূন্য পাওয়া গেছে" এক হয়ে গেছে, আর ওটাই সবচেয়ে ব্যয়বহুল ভুল।');

        $this->assertSame((int) $counted->id, (int) $count->lines->first()->product_id);
    }

    public function test_a_skipped_product_keeps_its_stock_after_the_count_is_settled(): void
    {
        /*
         * ⛔ উপরের পরীক্ষাটা দেখে সারিটা ঢোকেনি। ⚠️ এটা দেখে **মালটা
         * সত্যিই রয়ে গেছে** — কারণ সারি না ঢোকা আর স্টক অক্ষত থাকা
         * এক কথা নয়, যদি অনুমোদন গোটা গুদাম ধরে কাজ করত।
         */
        $counted = $this->stocked('গোনা হলো', '40');
        $skipped = $this->stocked('বাদ পড়ল', '25');

        $count = $this->record([
            ['product_id' => $counted->id, 'counted_qty' => '38'],
        ]);

        $this->settle($count);

        $stock = app(StockService::class);

        $this->assertSame(0, bccomp($stock->floorQty($skipped, $this->warehouse), '25', 4),
            'যে পণ্যটা গোনাই হয়নি তার মজুদ বদলে গেছে — অর্থাৎ একটা '
            .'অর্ধেক-ভরা শিট গোটা গুদাম মুছে দিত।');
    }

    // ── ৩ · গোনায় খাতা নড়ে না ────────────────────────────────────────

    public function test_recording_a_count_leaves_the_books_alone(): void
    {
        $product = $this->stocked('খাতা নড়ে না', '40');

        $this->record([['product_id' => $product->id, 'counted_qty' => '31']]);

        $this->assertSame(0, bccomp(
            app(StockService::class)->floorQty($product, $this->warehouse), '40', 4,
        ), 'কেবল গোনাতেই মজুদ বদলে গেছে — তাহলে গণনাকারী নিজেই খাতা '
            .'বদলে ফেলতে পারতেন, আর অনুমোদনের কোনো মানে থাকত না।');
    }

    // ── ৪ · মেনে নেওয়ার চাবি আলাদা ───────────────────────────────────

    public function test_the_one_who_counts_cannot_settle_the_difference(): void
    {
        $product = $this->stocked('চাবির পরীক্ষা', '40');
        $count = $this->record([['product_id' => $product->id, 'counted_qty' => '31']]);

        $counter = $this->aUserWhoCanOnlyCount();

        $this->actingAs($counter)
            ->post(route('inventory.count.approve', $count), [
                'reason_code_id' => $this->aReason()->id,
            ])
            ->assertForbidden();

        $this->assertSame(DocumentStatus::DRAFT, $count->fresh()->status,
            'গোনার চাবি নিয়েই পার্থক্যটা মেনে নেওয়া গেছে — তাহলে গোনার '
            .'কোনো মানেই থাকে না, কারণ যিনি গোনেন তিনিই নিষ্পত্তি করেন।');
    }

    public function test_the_counter_can_still_open_the_sheet(): void
    {
        /*
         * ⚠️ উপরের পাহারাটা উল্টো দিকেও ভাঙতে পারত: চাবিটা এত শক্ত করে
         * বসানো যে গুদামের লোক গোনার পাতাই খুলতে পারেন না। ⓘ তখন
         * পরীক্ষাটা সবুজ থাকত, আর কাজটা অচল।
         */
        $this->actingAs($this->aUserWhoCanOnlyCount())
            ->get(route('inventory.count.create', ['warehouse' => $this->warehouse->id]))
            ->assertOk();
    }

    // ── ৫ · মেনে নিলে খাতা বদলায় ──────────────────────────────────────

    public function test_settling_the_difference_moves_the_stock(): void
    {
        $product = $this->stocked('মীমাংসার পণ্য', '40');
        $count = $this->record([['product_id' => $product->id, 'counted_qty' => '31']]);

        $this->settle($count);

        $this->assertSame(DocumentStatus::CONFIRMED, $count->fresh()->status);

        $this->assertSame(0, bccomp(
            app(StockService::class)->floorQty($product, $this->warehouse), '31', 4,
        ), 'পার্থক্যটা মেনে নেওয়ার পরেও খাতা আগের সংখ্যাই বলছে — অর্থাৎ '
            .'গোনার দ্বিতীয় অর্ধেকটা কিছুই করে না।');
    }

    public function test_settling_without_a_reason_is_refused(): void
    {
        /*
         * ⛔ মাল কম পাওয়া গেছে — চুরি, ভাঙা, মেয়াদ, নাকি গোনার ভুল?
         * ⓘ উত্তরটা ছাড়া সংখ্যাটা কেবল একটা ক্ষতি, আর মাস শেষে "কোন
         * কারণে কত গেল" প্রশ্নের কোনো জবাব থাকে না।
         */
        $product = $this->stocked('কারণহীন', '40');
        $count = $this->record([['product_id' => $product->id, 'counted_qty' => '31']]);

        $this->actingAs($this->owner)
            ->from(route('inventory.count.show', $count))
            ->post(route('inventory.count.approve', $count), [])
            ->assertSessionHasErrors('reason_code_id');

        $this->assertSame(DocumentStatus::DRAFT, $count->fresh()->status);
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function record(array $lines): StockCount
    {
        $payload = [];

        foreach ($lines as $i => $line) {
            $payload[$i] = $line;
        }

        $this->actingAs($this->owner)
            ->post(route('inventory.count.store'), [
                'warehouse_id' => $this->warehouse->id,
                'count_date' => now()->toDateString(),
                'lines' => $payload,
            ])
            ->assertRedirect();

        return StockCount::query()->latest('id')->with('lines')->firstOrFail();
    }

    private function settle(StockCount $count): void
    {
        $this->actingAs($this->owner)
            ->post(route('inventory.count.approve', $count), [
                'reason_code_id' => $this->aReason()->id,
            ])
            ->assertRedirect();
    }

    /**
     * ⓘ যেকোনো কারণ নয় — **সমন্বয়ের** কারণ।
     *
     * ⚠️ প্রতিটা কারণ-কোড একটা প্রসঙ্গে বাঁধা, আর ভুল প্রসঙ্গেরটা নিলে
     * পরীক্ষাটা এমন একটা পথ মাপত যা আসল পর্দায় কেউ কোনোদিন নেয় না।
     */
    private function aReason(): ReasonCode
    {
        return ReasonCode::query()
            ->inContext(ReasonCode::STOCK_ADJUSTMENT)
            ->active()->orderBy('id')->firstOrFail();
    }

    /**
     * গোনার চাবি আছে, মেনে নেওয়ার নেই — ঠিক গুদামের লোকের মতো।
     */
    private function aUserWhoCanOnlyCount(): User
    {
        /*
         * ⚠️ নতুন রোল বানানো হয় না, বিদ্যমান একজনকে দুইটা চাবি দেওয়া হয়।
         *
         * ── ⛔ প্রথম চেষ্টাটা এখানেই ভুল ছিল ──────────────────────────
         * `Role::findOrCreate()` দিয়ে রোল বানিয়ে `assignRole()` করা
         * হয়েছিল, আর অনুমতিগুলো **দলে (company) বাঁধা** — রোলটা তৈরি
         * হত দল বসানোর আগে, তাই চাবিগুলো কোথাও পৌঁছাত না।
         *
         * ⓘ ফল: গুদামের লোক গোনার পাতাই খুলতে পারতেন না (৪০৩), আর
         * পরীক্ষাটা এমন একটা রোগ দেখাত যা কোডে নেই।
         */
        $user = User::query()->where('email', 'sales@abos.test')->firstOrFail();

        CompanyContext::forCompany(
            (int) CompanyContext::id(),
            fn () => $user->givePermissionTo('inventory.count.view', 'inventory.count.create'),
        );

        return $user->fresh();
    }

    private function stocked(string $name, string $onHand, string $unitCost = '10.00'): Product
    {
        $product = Product::query()->create([
            'code' => 'CNT-'.mb_substr(md5($name.microtime()), 0, 8),
            'name_en' => $name,
            'name_bn' => $name,
            'unit_id' => Unit::query()->orderBy('id')->firstOrFail()->id,
            'is_active' => true,
        ]);

        app(StockService::class)->move(
            product: $product,
            warehouse: $this->warehouse,
            sourceType: 'test.opening',
            sourceId: $product->id,
            floor: $onHand,
        );

        app(CostLayerService::class)->receive(
            product: $product,
            qty: $onHand,
            unitCost: $unitCost,
            sourceType: 'test.opening',
            sourceId: $product->id,
        );

        return $product;
    }
}
