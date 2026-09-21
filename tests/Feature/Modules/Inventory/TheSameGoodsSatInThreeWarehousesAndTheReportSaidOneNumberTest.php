<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Engines\Report\ReportEngine;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\MasterData\Models\ReasonCode;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * একই মাল তিন গুদামে, আর রিপোর্ট একটা সংখ্যা বলত।
 *
 * ── ⛔ কেন দুইটা নতুন রিপোর্ট, ২১ সেপ্টেম্বর ২০২৬ ────────────────────
 * [[StockReports::stockSummary()]] প্রতি পণ্যে **এক সারি** দেয়, আর
 * সংখ্যাটা সব গুদামের যোগফল। ⓘ "৫০ কার্টন আছে" শুনে কেউ অর্ডার নেন,
 * পরে দেখা যায় ৪৫টা অন্য গুদামে — যোগফল দিয়ে চালান পাঠানো যায় না।
 *
 * ⚠️ আর সমন্বয়ের দিকটা কোথাও ছিলই না: কে কবে কেন মজুদ বদলেছে সেই
 * প্রশ্নের উত্তর বের করতে খতিয়ানের প্রতিটা সারি চোখে পড়তে হত।
 *
 * ── ⭐ কেন এই পরীক্ষাটা সংখ্যা ধরে ধরে মেলায় ───────────────────────
 * রিপোর্ট "খোলে" — এটুকু দাবি প্রায় কিছুই বলে না। ⛔ একটা রিপোর্ট
 * নিখুঁত খুলে **ভুল সংখ্যা** দেখাতে পারে, আর কেউ টের পায় না, কারণ
 * মেলানোর মতো দ্বিতীয় কোনো জায়গা নেই।
 *
 * ⓘ তাই এখানে মজুদটা পরীক্ষাটা **নিজে বসায়** ([[StockService]] দিয়ে,
 * সরাসরি সারি লিখে নয়), তারপর রিপোর্টের ঘরে ঘরে ঐ সংখ্যাগুলোই খোঁজে।
 */
final class TheSameGoodsSatInThreeWarehousesAndTheReportSaidOneNumberTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Product $product;

    /** @var array<string, Warehouse> */
    private array $stores = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->owner->switchCompany($company->id);

        $this->product = Product::query()->orderBy('id')->firstOrFail();

        /*
         * ⛔ ডেমোর গুদাম ব্যবহার করা হয় না — ২১ সেপ্টেম্বর ২০২৬।
         *
         * ⚠️ প্রথমে ডেমোর দুইটা গুদাম নেওয়া হয়েছিল, আর দাবি লাল হলো:
         * "৪০ বসানো হয়েছে, রিপোর্ট বলছে ১৬০"। ⓘ কারণ বীজেই ওই পণ্যের
         * ১২০ বসানো ছিল — কোড ঠিকই ছিল, আমার অনুমানটা ভুল ছিল।
         *
         * ⭐ নিজের গুদাম মানে শুরুর অবস্থা নিশ্চিতভাবে শূন্য, আর তখন
         * প্রতিটা সংখ্যা পরম। বীজে কেউ মজুদ যোগ করলেও এই পরীক্ষা
         * মিথ্যা লাল হবে না।
         */
        $this->stores = [
            'a' => $this->store('TST-WH-A', 'Test store A'),
            'b' => $this->store('TST-WH-B', 'Test store B'),
        ];
    }

    /**
     * ⭐ একই পণ্য দুই গুদামে — দুইটা সারি, আর যার যার নিজের সংখ্যা।
     */
    public function test_each_warehouse_gets_its_own_row_with_its_own_number(): void
    {
        $this->receive('a', '40');
        $this->receive('b', '15');

        $rows = $this->rowsOf('stock-by-warehouse');

        $mine = array_values(array_filter(
            $rows,
            fn ($r) => $this->isMine($r),
        ));

        $this->assertCount(2, $mine, implode("\n", [
            'পণ্যটা দুই গুদামে আছে, কিন্তু রিপোর্টে '.count($mine).'টা সারি।',
            '',
            'ⓘ গুদামভিত্তিক মজুদের গোটা কারণটাই এই ভাগটা।',
        ]));

        $byStore = [];

        foreach ($mine as $row) {
            $byStore[(string) $row['warehouse_name']] = (string) $row['floor'];
        }

        foreach (['a' => '40', 'b' => '15'] as $key => $expected) {
            $name = $this->storeName($key);

            $this->assertArrayHasKey($name, $byStore,
                'গুদাম "'.$name.'"-এর সারিটাই নেই।');

            $this->assertSame(0, bccomp($byStore[$name], $expected, 4), implode("\n", [
                'গুদাম "'.$name.'"-এ বসানো হয়েছে '.$expected.', রিপোর্ট বলছে '.$byStore[$name].'।',
                '',
                '⛔ সংখ্যাটা ভুল হলে রিপোর্টটা খোলা অবস্থাতেই মিথ্যা বলে।',
            ]));
        }
    }

    /**
     * ⛔ যে জোড়া শূন্যে নেমেছে, তার সারি আসে না।
     *
     * ── ⚠️ কেন এটা আলাদা দাবি ───────────────────────────────────────
     * পণ্য × গুদাম মানে সারির সংখ্যা গুণফল। ⓘ ছাঁকনিটা না থাকলে যত
     * পণ্য কোনোদিন যে গুদামে ছিল সবগুলোর শূন্য সারি ছাপা হত, আর আসল
     * সারিগুলো তার ভিতরে হারাত — তালিকাটা পড়ার অযোগ্য হয়ে যেত।
     */
    public function test_a_pair_that_netted_to_zero_does_not_get_a_row(): void
    {
        $this->receive('a', '20');
        $this->receive('b', '7');
        $this->receive('b', '-7');

        $rows = $this->rowsOf('stock-by-warehouse');

        $names = array_map(fn ($r) => (string) ($r['warehouse_name'] ?? ''), array_filter(
            $rows,
            fn ($r) => $this->isMine($r),
        ));

        $this->assertContains($this->storeName('a'), $names,
            'যে গুদামে মাল আছে তার সারিটাই নেই।');

        $this->assertNotContains($this->storeName('b'), $names, implode("\n", [
            'যে গুদামে সব মাল বেরিয়ে গেছে তার শূন্য সারিটাও ছাপা হচ্ছে।',
            '',
            '⛔ পণ্য × গুদাম গুণফল — এভাবে তালিকাটা শূন্যে ভরে যাবে।',
        ]));
    }

    /**
     * ⭐ সমন্বয়ের ইতিহাস বলে কে, কবে, কেন।
     */
    public function test_the_history_names_the_person_and_the_reason(): void
    {
        $this->receive('a', '30');

        $reason = $this->adjustTo('a', '25');

        $rows = $this->historyOfMine();

        $this->assertNotSame([], $rows, implode("\n", [
            'সমন্বয় হয়েছে, অথচ ইতিহাসের রিপোর্টে একটাও সারি নেই।',
            '',
            'ⓘ ছাঁকনিটা `reason_code_id` আছে কি না — সমন্বয়ে ওটা বাধ্যতামূলক।',
        ]));

        $row = $rows[0];

        $this->assertSame($this->owner->name, (string) $row['changed_by'],
            'কে বদলেছে সেটা ভুল — "'.$row['changed_by'].'" এসেছে।');

        /*
         * ⚠️ রিপোর্ট কারণের নাম ব্যবহারকারীর ভাষায় দেয়, তাই `name_en`
         * ধরে মিলালে বাংলা লোকেলে মিথ্যা লাল হয়। ⓘ নিয়মটা হুবহু
         * [[StockReports::reasonName()]]-এরই নকল।
         */
        $this->assertSame($this->reasonLabel($reason), (string) $row['reason_name'],
            'কারণটা ভুল — "'.$row['reason_name'].'" এসেছে।');

        $this->assertSame(0, bccomp((string) $row['floor_change'], '-5', 4),
            '৩০ থেকে ২৫ করা হয়েছে, তাই বদলটা −৫ হওয়ার কথা; এসেছে '.$row['floor_change'].'।');
    }

    /**
     * ⭐ কারণ-কোড মুছে গেলেও সারিটা থাকে।
     *
     * ── ⛔ কেন এটাই সবচেয়ে জরুরি দাবি ───────────────────────────────
     * `INNER JOIN` হলে মুছে ফেলা কোড ব্যবহার করা **সব পুরনো সারি নীরবে
     * উধাও** হত। ⚠️ আর তখন ইতিহাসটাই মিথ্যা: মাল বদলেছে, অথচ রিপোর্ট
     * বলছে কেউ কিছু বদলায়নি — কোনো ভুলবার্তা ছাড়াই।
     */
    public function test_a_deleted_reason_code_does_not_erase_the_history(): void
    {
        $this->receive('a', '30');

        $reason = $this->adjustTo('a', '22');

        $this->assertCount(1, $this->historyOfMine(), 'সমন্বয়ের সারিটা আগেই নেই।');

        /*
         * ⛔ `delete()` নয়, `forceDelete()` — ২১ সেপ্টেম্বর ২০২৬।
         *
         * ⚠️ [[ReasonCode]] soft-delete ব্যবহার করে। ⓘ তাই `delete()`
         * কেবল `deleted_at` বসায়, সারিটা টেবিলেই থেকে যায় — আর
         * `INNER JOIN` দিব্যি মিলে যায়।
         *
         * ⛔ ফল: এই দাবিটা **মিউটেশনে সবুজ থেকে গিয়েছিল**। যোগটা
         * ইচ্ছা করে INNER করে দেওয়ার পরেও পরীক্ষাটা পাস করেছে,
         * কারণ তাকে যে বিপদটা মাপার কথা সেটা কখনো দেখানোই হয়নি।
         */
        ReasonCode::query()->whereKey($reason->id)->forceDelete();

        $after = $this->historyOfMine();

        $this->assertCount(1, $after, implode("\n", [
            'কারণ-কোডটা মুছতেই সমন্বয়ের সারিটা উধাও হয়ে গেছে।',
            '',
            '⛔ মানে যোগটা `INNER JOIN` — আর তখন ইতিহাস মিথ্যা বলে:',
            '   মাল বদলেছে, অথচ রিপোর্ট বলছে কেউ কিছু বদলায়নি।',
        ]));

        $this->assertSame(0, bccomp((string) $after[0]['floor_change'], '-8', 4),
            'সারিটা আছে, কিন্তু সংখ্যাটা বদলে গেছে।');
    }

    /**
     * ⭐ দুইটা সারিতাই মেনুতে আছে, আর চাপলে সত্যিই খোলে।
     *
     * ── ⛔ কেন জোড়াটা আলাদা করে মাপা হয়, ১৮ সেপ্টেম্বরের শিক্ষা ──────
     * `expiring` রিপোর্টটা লেখা হয়েছিল, ইঞ্জিনে নিবন্ধিতও হত, মেনুতে
     * সারিটাও ছিল — কেবল কন্ট্রোলারের `SLUGS`-এ একটা সারি না থাকায়
     * চাপলে **৪০৪** আসত। ⓘ তিনটা অংশই ছিল, জোড়াটা ছিল না।
     */
    public function test_both_menu_rows_actually_open(): void
    {
        foreach (['stock-by-warehouse', 'adjustments'] as $slug) {
            $response = $this->actingAs($this->owner)->get('/inventory/reports/'.$slug);

            $this->assertSame(200, $response->status(), implode("\n", [
                'সারিতা "'.$slug.'" খুলছে না — সাড়া '.$response->status().'।',
                '',
                '⛔ ৪০৪ মানে কন্ট্রোলারের SLUGS-এ সারিটা নেই।',
                '⛔ ৫০০ মানে কোয়েরিটাই ভাঙা।',
            ]));
        }
    }

    /**
     * ⭐ মেনুর প্রতিটা সারির আইকন আঁকার তালিকায় সত্যিই আছে।
     *
     * ── ⛔ আগের লেখাটা অন্ধ ছিল, ২১ সেপ্টেম্বর ২০২৬ ──────────────────
     * প্রথম দফায় এটা module.php-র লেখাটা ধরে সারিতার আগের ৪০০ অক্ষরে
     * `'icon' => '...'` খুঁজত, আর regex-টা **প্রথম** মিলটা নিত — যা
     * উপরের সারির আইকন। ⚠️ ফলে অচেনা একটা আইকন বসিয়ে দেওয়ার পরেও
     * মিউটেশনে দাবিটা **সবুজ** থেকে গেছে: সে ভুল ঘরটা মাপছিল।
     *
     * ⭐ এখন লেখাটা পড়া হয় না — `module.php` নিজেই একটা array ফেরত
     * দেয়, তাই সেটা `require` করে সারি ধরে ধরে দেখা হয়। ⓘ আর কেবল
     * আজকের দুইটা নয়, **সবগুলো** রিপোর্ট-সারি — নতুন সারি যোগ হলেও
     * সে নিজে থেকেই এই দাবির আওতায় আসবে।
     */
    public function test_every_report_menu_row_uses_an_icon_the_set_has(): void
    {
        $module = require app_path('Modules/Inventory/module.php');

        $rows = $module['menu']['reports'] ?? [];

        // ⚠️ খালি তালিকায় নিচের লুপটা কিছুই মাপত না
        $this->assertGreaterThan(5, count($rows),
            'মেনুর reports অংশে সারি পাওয়া গেল না — দাবিটা তাহলে ফাঁকা।');

        $known = $this->iconNames();

        $this->assertGreaterThan(30, count($known),
            'আইকনের তালিকাই পড়া গেল না — তখন সবকিছুকেই "নেই" বলা হত।');

        $seen = [];

        foreach ($rows as $row) {
            $icon = (string) ($row['icon'] ?? '');
            $slug = (string) ($row['route_params']['slug'] ?? $row['label'] ?? '?');

            $this->assertNotSame('', $icon, 'মেনুর "'.$slug.'" সারিতে কোনো আইকনই নেই।');

            $this->assertContains($icon, $known, implode('\\n', [
                'মেনুর "'.$slug.'" সারিতে আইকন "'.$icon.'" — আঁকার তালিকায় ওটা নেই।',
                '',
                'ⓘ ভাঙে না, কেবল সারিটা ফাঁকা আইকন নিয়ে বসে।',
            ]));

            $seen[] = $slug;
        }

        // ⭐ আজকের দুইটা সারি সত্যিই ঐ তালিকার ভিতরে ছিল কি না
        foreach (['stock-by-warehouse', 'adjustments'] as $slug) {
            $this->assertContains($slug, $seen, 'মেনুতে "'.$slug.'" সারিটাই নেই।');
        }
    }

    /**
     * আঁকার তালিকার নামগুলো।
     *
     * @return list<string>
     */
    private function iconNames(): array
    {
        $blade = (string) file_get_contents(resource_path('views/components/ui/icon.blade.php'));

        preg_match_all("/^\\s*'([a-z0-9-]+)' =>/m", $blade, $found);

        return array_values(array_unique($found[1]));
    }

    private function isMine(array $row): bool
    {
        if (! str_contains((string) ($row['product_name'] ?? ''), $this->product->code)) {
            return false;
        }

        return in_array((string) ($row['warehouse_name'] ?? ''), [
            $this->storeName('a'),
            $this->storeName('b'),
        ], true);
    }

    /** পরীক্ষার নিজের একটা গুদাম — শুরুর অবস্থা শূন্য। */
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

    /** কারণের নাম, রিপোর্ট যেভাবে দেখায় ঠিক সেভাবে। */
    private function reasonLabel(ReasonCode $reason): string
    {
        return app()->getLocale() === 'bn' && ($reason->name_bn ?? '') !== ''
            ? (string) $reason->name_bn
            : (string) $reason->name_en;
    }

    private function receive(string $store, string $qty): void
    {
        $this->be($this->owner);

        app(StockService::class)->move(
            product: $this->product,
            warehouse: $this->stores[$store],
            sourceType: 'test_seed',
            sourceId: $this->product->id,
            floor: $qty,
        );
    }

    private function adjustTo(string $store, string $counted): ReasonCode
    {
        $this->be($this->owner);

        $reason = ReasonCode::query()->firstOrFail();

        app(StockService::class)->adjust(
            product: $this->product,
            warehouse: $this->stores[$store],
            countedQty: $counted,
            reason: $reason,
        );

        return $reason;
    }

    private function storeName(string $key): string
    {
        $w = $this->stores[$key];

        return app()->getLocale() === 'bn' && $w->name_bn !== '' && $w->name_bn !== null
            ? $w->name_bn
            : $w->name_en;
    }

    /**
     * সমন্বয়ের ইতিহাস, কেবল **এই** পরীক্ষার পণ্যটার।
     *
     * ── ⛔ কেন ছাঁকনিটা লাগে ─────────────────────────────────────────
     * ডেমোর বীজেই কয়েকটা সমন্বয় বসানো থাকে। ⚠️ প্রথমে ধরে নিয়েছিলাম
     * ইতিহাসটা খালি, আর তাতে দাবিটা তিনটা সারি পেয়ে লাল হয়েছিল —
     * ⓘ কোডের দোষে নয়, আমার অনুমানের দোষে।
     *
     * ⭐ নিজের পণ্যে ছেঁকে নিলে দাবিটা ডেমোর তথ্যের উপর নির্ভর করে না,
     * আর কেউ বীজে একটা সমন্বয় যোগ করলেই মিথ্যা লাল হয় না।
     *
     * @return array<int, array<string, mixed>>
     */
    private function historyOfMine(): array
    {
        return array_values(array_filter(
            $this->rowsOf('adjustments'),
            fn ($r) => $this->isMine($r),
        ));
    }

    /**
     * রিপোর্টটা চালিয়ে সারিগুলো — পর্দার HTML নয়, আসল সংখ্যা।
     *
     * @return array<int, array<string, mixed>>
     */
    private function rowsOf(string $slug): array
    {
        $engine = app(ReportEngine::class);

        $key = $slug === 'adjustments'
            ? 'inventory.adjustments'
            : 'inventory.stock_by_warehouse';

        $result = $engine->run($key, [
            'from' => now()->subYear()->toDateString(),
            'to' => now()->addDay()->toDateString(),
        ]);

        return array_values($result->rows);
    }
}
