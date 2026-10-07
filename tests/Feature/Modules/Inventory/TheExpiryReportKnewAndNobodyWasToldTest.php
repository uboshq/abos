<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Services\SettingsService;
use App\Core\Services\StatusNotices;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * মেয়াদের রিপোর্ট সব জানত, আর কাউকে বলত না।
 *
 * ── ⛔ যে ফাঁকটা ছিল ─────────────────────────────────────────────────
 * মেয়াদের রিপোর্ট (`inventory.expiring`) আগে থেকেই আছে, দিন-গোনা সহ
 * নিখুঁত। ⚠️ কিন্তু **কেউ ওটা নিজে থেকে খোলে না** — খোলে তখনই, যখন কেউ
 * বলে খুলতে। ⓘ ফল: রিপোর্টটা ঠিকঠাক জানাত মাল মেয়াদ পেরিয়ে গেছে, আর
 * ফেরত পাঠানোর সময়টা ততক্ষণে চলে গেছে।
 *
 * ⭐ এটা ABOS-এর সবচেয়ে চেনা রোগ: **হিসাবটা আছে, জোড়াটা নেই**, আর
 * কোথাও কিছু লাল হয় না।
 *
 * ── ⚠️ এই ফাইল যা পাহারা দেয় ────────────────────────────────────────
 *   ১. কাছাকাছি মেয়াদের লট থাকলে বার্তাটা সত্যিই ওঠে
 *   ২. দূরের মেয়াদে ওঠে না (নাহলে বার্তাটা সবসময় থাকত, আর কেউ পড়ত না)
 *   ৩. সুইচ শূন্য করলে সত্যিই বন্ধ হয়
 *   ৪. যে লটে মাল নেই তার জন্য ওঠে না
 *
 * ⓘ (৪) সবচেয়ে সহজে ভাঙে: গত বছরের ফুরিয়ে যাওয়া প্রতিটা লট গুনলে
 * সংখ্যাটা এত বড় হত যে কেউ আর পড়ত না।
 */
final class TheExpiryReportKnewAndNobodyWasToldTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();

        app(SettingsService::class)->set('inventory.expiry_alert_days', 30);
    }

    // ── ১ ও ২ · কখন ওঠে, কখন ওঠে না ──────────────────────────────────

    public function test_a_lot_expiring_soon_reaches_the_ticker(): void
    {
        $this->aLotExpiringIn(10, onHand: '25');

        $this->assertTrue($this->tickerWarnsAboutExpiry(),
            'দশ দিনে মেয়াদ শেষ হওয়া মাল গুদামে পড়ে আছে, তবু নিচের বারে '
            .'কিছুই ওঠেনি — অর্থাৎ রিপোর্টটা জানে, কিন্তু কেউ জানে না।');
    }

    public function test_a_lot_expiring_far_away_stays_quiet(): void
    {
        /*
         * ⛔ বার্তাটা সবসময় থাকলে ওটা আসবাব হয়ে যায়, আর কেউ পড়ে না —
         * ⓘ ঠিক যে কারণে সংরক্ষণের পটিটা শর্ত ছাড়া ভাসে না।
         */
        $this->aLotExpiringIn(200, onHand: '25');

        $this->assertFalse($this->tickerWarnsAboutExpiry(),
            'দুইশো দিন পরের মেয়াদেও সতর্কবার্তা উঠছে — তাহলে বার্তাটা '
            .'সবসময়ই থাকত, আর কেউ ওটা আর পড়ত না।');
    }

    // ── ৩ · সুইচটা সত্যিই বন্ধ করে ────────────────────────────────────

    public function test_setting_the_days_to_zero_really_silences_it(): void
    {
        /*
         * ⚠️ এই পরীক্ষাটা সুইচটাকে **বিপরীত দিক থেকে** ভাঙে: আগে
         * প্রমাণ করা হয় বার্তাটা উঠছে, তারপর সুইচ নামিয়ে দেখা হয়
         * সত্যিই থামে। ⛔ কেবল "শূন্যে ওঠে না" মাপলে পরীক্ষাটা এমন
         * কোডেও সবুজ থাকত যেখানে বার্তাটা কোনোদিনই ওঠে না।
         */
        $this->aLotExpiringIn(10, onHand: '25');

        $this->assertTrue($this->tickerWarnsAboutExpiry(),
            'সুইচ চালু থাকতেই বার্তাটা ওঠেনি — তাহলে নিচের পরীক্ষাটা কিছুই প্রমাণ করে না।');

        app(SettingsService::class)->set('inventory.expiry_alert_days', 0);

        $this->assertFalse($this->tickerWarnsAboutExpiry(),
            'শূন্য দিয়েও সতর্কতাটা বন্ধ হয়নি — যে প্রতিষ্ঠান ব্যাচ ধরে না '
            .'তার পর্দায় ওটা চিরকাল ঝুলে থাকত।');
    }

    // ── ৪ · খালি লট গোনা হয় না ───────────────────────────────────────

    public function test_a_lot_with_nothing_left_in_it_is_not_counted(): void
    {
        $this->aLotExpiringIn(10, onHand: '0');

        $this->assertFalse($this->tickerWarnsAboutExpiry(),
            'যে লটে এক পিসও পড়ে নেই তার জন্যও সতর্কতা উঠছে — তাহলে গত '
            .'বছরের প্রতিটা ফুরিয়ে যাওয়া লট গোনা হত, আর সংখ্যাটা এত বড় '
            .'হত যে কেউ পড়ত না।');
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    private function tickerWarnsAboutExpiry(): bool
    {
        /*
         * ⚠️ বারটা এক মিনিটের জন্য ক্যাশ হয় ([[StatusNotices::TTL]])।
         * ⛔ না মুছলে দ্বিতীয় ডাকে আগের উত্তরটাই ফিরত, আর সুইচের
         * পরীক্ষাটা **সবসময় সবুজ** হত — কারণ কিছুই বদলাত না।
         */
        Cache::flush();

        $wanted = route('inventory.report.show', ['slug' => 'expiring']);

        foreach (app(StatusNotices::class)->all() as $notice) {
            if (($notice['url'] ?? null) === $wanted) {
                return true;
            }
        }

        return false;
    }

    private function aLotExpiringIn(int $days, string $onHand): Batch
    {
        $product = Product::query()->create([
            'code' => 'EXP-'.mb_substr(md5($days.$onHand.microtime()), 0, 8),
            'name_en' => 'Expiry test item',
            'name_bn' => 'মেয়াদের পণ্য',
            'unit_id' => Unit::query()->orderBy('id')->firstOrFail()->id,
            'track_batch' => true,
            'is_active' => true,
        ]);

        $lot = Batch::query()->create([
            'product_id' => $product->id,
            'batch_no' => 'EXP-'.$days,
            'expiry_date' => now()->addDays($days)->toDateString(),
        ]);

        if (bccomp($onHand, '0', 4) > 0) {
            app(StockService::class)->move(
                product: $product,
                warehouse: $this->warehouse,
                sourceType: 'test.opening',
                sourceId: $product->id,
                floor: $onHand,
                batch: $lot,
            );
        }

        return $lot;
    }
}
