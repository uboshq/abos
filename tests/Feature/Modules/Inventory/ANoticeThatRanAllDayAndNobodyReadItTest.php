<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Services\MenuBuilder;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\GoodsWaitingToBePlaced;
use App\Modules\Inventory\Services\StockService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * একটা নোটিশ সারাদিন চলত, আর কেউ পড়ত না।
 *
 * ── মালিকের আপত্তি, ৫ সেপ্টেম্বর ২০২৬ ─────────────────────────────────
 * সরাসরি ক্রয়ের পর্দায় গুদামের ঘরের নিচে একটা **স্থায়ী** লেখা বসানো
 * ছিল: *"মাল ঢোকে বসানো হয়নি অবস্থায়। স্টক প্লেসমেন্ট-এ গিয়ে বসিয়ে
 * দিন…"* — কোনো শর্ত ছাড়াই, প্রতিবার।
 *
 * তিনি ধরলেন: *"এটার জন্য Notice board আছে কেন? নিচে Footer-এ All time
 * Notice চলছে কী জন্য?"*
 *
 * ⚠️ **আর কথাটা ঠিক।** যে লেখা রোজ ওঠে তা কেউ পড়ে না, আর তখন একই
 * জায়গার **আসল** সতর্কতাগুলোও পড়া বন্ধ হয়ে যায় — একই রঙ, একই ছোট
 * হরফ, একই জায়গা। ⓘ কাউন্টারের লোক দিনে পঞ্চাশবার ঐ পর্দা খোলেন।
 *
 * ⭐ বদলে একটা **সংখ্যা**, আর সেটা মেনুর সারিতে: *সংখ্যা তথ্য, বাক্য
 * উপদেশ।* "৩" দেখলে মানুষ ক্লিক করেন; একটা উপদেশ পড়ে কেউ কিছু করেন না।
 */
class ANoticeThatRanAllDayAndNobodyReadItTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->orderBy('id')->firstOrFail();
    }

    /**
     * ⭐ কিছু পড়ে না থাকলে **কোনো ব্যাজই নেই**।
     *
     * ⚠️ এটাই সবচেয়ে জরুরি দাবি, আর এটাই ঐ স্থায়ী নোটিশের সাথে
     * পার্থক্য। একটা "০" ব্যাজ প্রতিদিন চোখে পড়ত আর কিছুই বলত না —
     * অর্থাৎ ঐ নোটিশেরই আরেক রূপ।
     */
    public function test_when_nothing_waits_the_menu_says_nothing(): void
    {
        $this->clearTheFloor();

        $this->assertSame(0, app(GoodsWaitingToBePlaced::class)->pendingCount());
        $this->assertNull($this->placementRow()['badge'] ?? null,
            'কিছু পড়ে না থাকলেও ব্যাজ দেখাচ্ছে — তাহলে ওটা আরেকটা স্থায়ী নোটিশ।');
    }

    /** আর মাল অপেক্ষায় থাকলে সংখ্যাটা সারিতেই দেখা যায়। */
    public function test_when_goods_wait_the_row_carries_the_count(): void
    {
        $this->clearTheFloor();

        foreach ([['bill', 91], ['bill', 92]] as [$type, $id]) {
            app(StockService::class)->move(
                product: $this->product,
                warehouse: $this->warehouse,
                sourceType: $type,
                sourceId: $id,
                unplaced: '5',
            );
        }

        $this->assertSame(2, app(GoodsWaitingToBePlaced::class)->pendingCount());
        $this->assertSame(2, $this->placementRow()['badge'] ?? null,
            'দুইটা কাগজ অপেক্ষায়, অথচ মেনুর সারিতে সংখ্যাটা নেই।');
    }

    /**
     * ⭐ গোনা হয় **কাগজ**, কার্টন নয়।
     *
     * ⚠️ গুদামের লোকের প্রশ্ন "আজ কয়টা কাগজ বাকি" — তিনি কাগজ ধরে ধরে
     * বুঝে নেন। ⛔ কার্টন গুনলে সংখ্যাটা হাজার ছাড়াত আর ব্যাজে ধরত না,
     * অথচ কাজ হয়তো একটা কাগজের।
     */
    public function test_it_counts_papers_not_cartons(): void
    {
        $this->clearTheFloor();

        app(StockService::class)->move(
            product: $this->product,
            warehouse: $this->warehouse,
            sourceType: 'bill',
            sourceId: 93,
            unplaced: '900',
        );

        $this->assertSame(1, app(GoodsWaitingToBePlaced::class)->pendingCount(),
            'নয়শো কার্টন মানে নয়শো নয় — একটাই কাগজ।');
    }

    /**
     * ⛔ আর ঐ স্থায়ী নোটিশটা যেন ফিরে না আসে।
     *
     * ⓘ পাহারাটা লেখা হয়েছে কারণ লাইনটা তুলে দেওয়া সহজ, আর ফিরিয়ে
     * আনাও সহজ — "একটু বুঝিয়ে দিই" ভেবে কেউ আবার বসিয়ে দিতে পারেন।
     */
    public function test_the_permanent_notice_does_not_come_back(): void
    {
        $screen = (string) file_get_contents(
            app_path('Modules/Purchase/Resources/views/direct/index.blade.php'),
        );

        /*
         * ⚠️ লেখাটা `title`-এ থাকতে পারে (মাউস নিলে দেখা যায়), কিন্তু
         * পর্দায় **ছাপা** হতে পারে না। ⓘ তাই `{{ __(…) }}` রূপটাই
         * খোঁজা হয় — `title="{{ __(…) }}"` নয়।
         */
        $this->assertDoesNotMatchRegularExpression(
            '/^\s*\{\{\s*__\(\s*\'purchase::message\.goods_wait_for_placement\'\s*\)\s*\}\}\s*$/m',
            $screen,
            'বসানোর স্থায়ী নোটিশটা পর্দায় ফিরে এসেছে — সংখ্যাটা মেনুতেই থাকার কথা।',
        );
    }

    /** @return array<string, mixed> */
    private function placementRow(): array
    {
        $menu = app(MenuBuilder::class)->forUser($this->owner);

        foreach ($menu as $module) {
            foreach ($module['groups'] ?? [] as $rows) {
                foreach ($rows as $row) {
                    if (($row['route'] ?? null) === 'inventory.stock.placement') {
                        return $row;
                    }
                }
            }
        }

        $this->fail('মেনুতে বসানোর সারিটাই পাওয়া গেল না।');
    }

    /**
     * ডেমো ডেটায় আগে থেকে অপেক্ষমাণ মাল থাকতে পারে — সরিয়ে নেওয়া হয়।
     *
     * ⓘ নাহলে সংখ্যাগুলো সিডারের উপর নির্ভর করত, আর সিডার বদলালেই
     * পরীক্ষাটা লাল হত — কারণটা এই কোডের সাথে কোনো সম্পর্ক না রেখেই।
     */
    private function clearTheFloor(): void
    {
        \Illuminate\Support\Facades\DB::table('inv_stock_movements')
            ->where('company_id', CompanyContext::id())
            ->update(['unplaced_change' => 0, 'unplaced_free_change' => 0]);
    }
}
