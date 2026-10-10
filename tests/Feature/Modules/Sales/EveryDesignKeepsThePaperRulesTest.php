<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Engines\Print\PrintProfile;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\DeliveryChallanLine;
use App\Modules\Sales\Services\SalesOrderService;
use App\Modules\Sales\Support\PaperDesigns;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * প্রতিটা নকশা কাগজের নিয়ম মানে — কেবল আঁকে না — ৩০ সেপ্টেম্বর ২০২৬।
 *
 * ── ⛔ ফাঁকটা ────────────────────────────────────────────────────────
 * মালিকের নির্দেশে বিল ও চালানের ডিফল্ট এখন একটা নকশা ("মোনো ক্লাসিক
 * হালকা"), সাধারণ কাগজ নয়। কাগজের নিয়মগুলোর পুরনো পাহারা —
 * [[TheDriverCouldReadEveryPriceOnThePaperTest]] — কেবল সাধারণ কাগজটাই
 * পড়ে, আর [[EveryPaperDesignPrintsTheRealPaperTest]] দেখে কেবল **কোন
 * ছাঁচ** আঁকা হলো, **কী লেখা** হলো তা নয়। ⚠️ তাই মালিক দাম বন্ধ করলেও
 * কোনো নকশা দর ছাপত কি না, সেটা কোনো পরীক্ষা জিজ্ঞেস করত না — অথচ
 * চালানটাই চালকের হাতে যায়।
 *
 * ⭐ তাই এখানে প্রতিটা চালান-নকশা, প্রতিটা মাপে, সত্যিই আঁকা হয় আর লেখাটা
 * পড়া হয় — একই চালান, একই মালিক, কেবল দামের সুইচটা আলাদা।
 */
final class EveryDesignKeepsThePaperRulesTest extends TestCase
{
    use RefreshDatabase;

    private const RATE = '777.77';

    private const TOTAL = '1,555.54';

    private const PAPER = ['a4' => 'a4', 'a5' => 'a5', 'thermal' => '80mm'];

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
    }

    public function test_every_challan_design_drops_the_prices_when_the_owner_switches_them_off(): void
    {
        $this->assertSame(66, $this->everyDesignObeysThePriceSwitch('challan', route('sales.print.challan', $this->aChallanWithARate())),
            'তিন মাপে ২২টা করে চালান-নকশা থাকার কথা।');
    }

    public function test_every_order_design_drops_the_prices_when_the_owner_switches_them_off(): void
    {
        $service = app(SalesOrderService::class);
        $order = $service->confirm($service->create([
            'customer_id' => Customer::query()->value('id'),
            'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
            'trx_date' => now()->toDateString(),
        ], [['product_id' => Product::query()->value('id'), 'ordered_qty' => '2', 'rate' => self::RATE]]));

        $this->assertSame(60, $this->everyDesignObeysThePriceSwitch('order', route('sales.print.order', $order)),
            'তিন মাপে ২০টা করে অর্ডার-নকশা থাকার কথা।');
    }

    /**
     * ⓘ একই কাগজ, প্রতিটা নকশা, প্রতিটা মাপ — দাম চালু হলে দর আছে, বন্ধ হলে দর ও মোট নেই।
     *
     * @return int কয়টা নকশা দেখা হলো — ⚠️ তালিকা খালি হলে লুপ কিছু না দেখেই সবুজ হত
     */
    private function everyDesignObeysThePriceSwitch(string $paper, string $route): int
    {
        $checked = 0;

        foreach (PaperDesigns::SIZES as $size) {
            foreach (PaperDesigns::codes($paper, $size) as $code) {
                app(SettingsService::class)->set(PaperDesigns::key($paper, $size), $code);
                $view = (string) PaperDesigns::template($paper, $size, $code);
                $url = $route.'?paper='.self::PAPER[$size];
                $what = "{$paper}/{$size}/{$code}";

                $this->prices($paper, true);
                $this->assertStringContainsString(self::RATE, $this->paperIn($view, $url, $what),
                    "{$what}: দাম চালু, তবু দর নেই — তাহলে নিচের মাপটা একটা খালি পাতা মাপত।");

                $this->prices($paper, false);
                $html = $this->paperIn($view, $url, $what);
                $this->assertStringNotContainsString(self::RATE, $html, "{$what}: দাম বন্ধ, তবু দর ছাপা হয়েছে।");
                $this->assertStringNotContainsString(self::TOTAL, $html, "{$what}: দর গেছে, মোটটা রয়ে গেছে।");

                $checked++;
            }
        }

        return $checked;
    }

    // ── হাতিয়ার ────────────────────────────────────────────────────────

    /** ⓘ ঐ কাগজের দামের সুইচ — মালিকের ছাপা-নিয়ন্ত্রণের একটা টিক */
    private function prices(string $paper, bool $on): void
    {
        app(SettingsService::class)->set("print.{$paper}.parts",
            $on ? PrintProfile::PARTS : array_values(array_diff(PrintProfile::PARTS, ['prices'])));
    }

    /**
     * ⓘ বাছা নকশাটাই আঁকা হলো কি না, আর আঁকা HTML-টা।
     * ⚠️ composer-এর ভিতরে আবার রেন্ডার করা যায় না (নিজেকেই ডাকে) — তাই আগে ডেটা ধরা, রেন্ডার পরে।
     */
    private function paperIn(string $view, string $url, string $what): string
    {
        $data = null;
        View::composer($view, function ($v) use (&$data) {
            $data = $v->getData();
        });

        $this->actingAs($this->owner)->get($url)->assertOk();
        $this->assertNotNull($data, "{$what}: বাছা নকশা ({$view}) আঁকা হয়নি।");

        $html = (string) view($view, $data)->render();

        /* ⓘ পরের ডাকের composer যেন এই ফলটা না ছোঁয় */
        View::getFacadeRoot()->getDispatcher()->forget('composing: '.$view);

        // ⓘ বাংলা নকশা অঙ্ক বাংলায় ছাপে (মালিক, ১০ অক্টোবর ২০২৬; [[BanglaDigits]]) — মাপ অঙ্কের মানে, অক্ষরে নয়
        return strtr($html, array_flip(['০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯']));
    }

    private function aChallanWithARate(): DeliveryChallan
    {
        $here = Company::query()->where('code', 'TDEPOT')->firstOrFail();

        $challan = DeliveryChallan::query()->create([
            'branch_id' => $here->defaultBranch()?->id,
            'document_no' => 'CHL-DESIGN-0001',
            'customer_id' => Customer::query()->firstOrFail()->id,
            'warehouse_id' => Warehouse::query()->firstOrFail()->id,
            'trx_date' => now()->toDateString(),
            'vehicle_no' => 'ঢাকা মেট্রো-ট ১১-১১১১',
            'driver_name' => 'চালক',
            'total' => '1555.5400',
            'status' => DocumentStatus::CONFIRMED,
        ]);

        DeliveryChallanLine::query()->create([
            'delivery_challan_id' => $challan->id,
            'product_id' => Product::query()->firstOrFail()->id,
            'line_no' => 1,
            'delivered_qty' => '2.0000',
            'rate' => self::RATE,
            'amount' => '1555.5400',
        ]);

        return $challan->fresh('lines');
    }
}
