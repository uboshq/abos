<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\PackBarcode;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * কাউন্টারে ২ডি বারকোড স্ক্যান।
 *
 * ── কেন এই সংযোগটা ছাড়া বাকি সব অর্থহীন ──────────────────────────────
 * ব্যাচ, মেয়াদ আর FEFO — তিনটাই আছে যাতে আগে-মেয়াদ-শেষ পাতাটা আগে
 * বেরোয়। কিন্তু কাউন্টারে ক্যাশিয়ার যদি পণ্যটাই খুঁজে না পান, তিনি
 * হাতে নাম লিখে বেচেন, আর প্যাকেটের গায়ের লট-মেয়াদ কেউ কখনো পড়ে না।
 *
 * PackBarcode-এর নিজের ১৪টা টেস্ট ছিল, সব সবুজ — অথচ POS-এর lookup
 * কাঁচা স্ট্রিংটাই `barcode` কলামে খুঁজত, তাই ওষুধের কার্টন স্ক্যান
 * করলে "পণ্য নেই" আসত।
 */
class PosScanTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->product = Product::query()->firstOrFail();
        $this->product->forceFill([
            'track_batch' => true,
            'barcode' => '08901234567890',
        ])->save();

        // কাউন্টারের পর্দা ডিফল্ট বন্ধ (Sales module.php) — স্ক্যানের
        // পথটাও ওই সুইচের পেছনে, তাই আগে চালু করে নেওয়া হয়।
        app(SettingsService::class)->set('sales.screen_pos', true);
    }

    /** ওষুধের কার্টনের গায়ে যেমন থাকে — GTIN, মেয়াদ, লট। */
    private function gs1(string $gtin = '08901234567890', string $expiry = '271231', string $lot = 'AB12'): string
    {
        return ']d2'.'01'.$gtin.'17'.$expiry.'10'.$lot.PackBarcode::SEPARATOR;
    }

    private function scan(string $code)
    {
        return $this->getJson(route('sales.pos.lookup', ['code' => $code]));
    }

    // ── পণ্য চেনা ────────────────────────────────────────────────

    /**
     * ২ডি স্ক্যানে পণ্যটা পাওয়া যায়।
     *
     * এটাই সেই সংযোগ যা না থাকলে বাকি সব ইঞ্জিন কাউন্টারে অদৃশ্য।
     */
    public function test_a_two_d_scan_finds_the_product(): void
    {
        $this->scan($this->gs1())
            ->assertOk()
            ->assertJsonPath('id', $this->product->id);
    }

    /**
     * সাধারণ ১ডি বারকোড আগের মতোই চলে।
     *
     * ডিপোর সাবান-বিস্কুটে EAN-১৩ ছাড়া কিছু নেই। ওটা ভাঙতে গেলে
     * একটা ফার্মেসি-সুবিধা যোগ করে গোটা ডিপোর কাউন্টার বন্ধ হত।
     */
    public function test_a_plain_barcode_still_works(): void
    {
        $this->scan('08901234567890')
            ->assertOk()
            ->assertJsonPath('id', $this->product->id);
    }

    /** যে কোড কোনো পণ্যের নয়, তাতে ৪০৪ — আগের মতোই। */
    public function test_an_unknown_code_is_still_not_found(): void
    {
        $this->scan('00000000000000')->assertNotFound();
    }

    /**
     * অপাঠ্য স্ক্যানেও ৪০৪ — কাউন্টার ভাঙে না।
     *
     * ── এটা একটা সত্যিকারের ভাঙন ছিল ────────────────────────────────
     * GS1 পার্সার অচেনা অংশ পেলে ব্যতিক্রম ছোঁড়ে, আর সেটা মজুদের
     * পর্দায় ঠিক: হাতে বসানো ভুল বারকোড জানানো দরকার। কিন্তু এই রুট
     * `api/*`-এ নয়, তাই Laravel JSON না বানিয়ে রিডাইরেক্ট বানাতে যেত
     * — আর স্ক্যানারের একটা এলোমেলো পাঠানো লেখাই গোটা কাউন্টার ভেঙে
     * দিত। পূর্ণ সুইট চালিয়ে ধরা পড়েছে; আলাদা টেস্টে নয়।
     *
     * কাউন্টারে প্রশ্নটা "এই কোডে কোনো পণ্য আছে?" — অপাঠ্য কোডের সৎ
     * উত্তর "নেই"।
     */
    public function test_an_unreadable_scan_answers_not_found_instead_of_breaking(): void
    {
        $this->scan('কিছুই-নেই')->assertNotFound();

        // অর্ধেক লেখা GS1 — স্ক্যানার ঠিকমতো সেট না থাকলে যা আসে
        $this->scan(']d2'.'99'.'garbage')->assertNotFound();
    }

    // ── লট ও মেয়াদ ───────────────────────────────────────────────

    /**
     * স্ক্যান করা লট গুদামে থাকলে নামটা ফেরত আসে।
     *
     * ক্যাশিয়ার হাতের প্যাকেটের সাথে মিলিয়ে নিতে পারেন — বিশেষত
     * মেয়াদটা, যেটা খালি চোখে ছোট ছাপায় পড়া কঠিন।
     */
    public function test_a_known_lot_comes_back_by_name(): void
    {
        Batch::query()->create([
            'product_id' => $this->product->id,
            'batch_no' => 'AB12',
            'expiry_date' => '2027-12-31',
        ]);

        $this->scan($this->gs1())
            ->assertOk()
            ->assertJsonPath('scanned_batch', 'AB12')
            ->assertJsonPath('batch_known', true);
    }

    /** মেয়াদটা মাস/বছরে — প্যাকেটের গায়ে যেভাবে ছাপা থাকে। */
    public function test_the_expiry_comes_back_as_month_and_year(): void
    {
        $this->scan($this->gs1(expiry: '271231'))
            ->assertOk()
            ->assertJsonPath('scanned_expiry', '12/2027');
    }

    /**
     * যে লট এখনো গুদামে ওঠেনি, তার নাম পাঠানো হয় না।
     *
     * পাঠালে পর্দায় এমন একটা লট দেখা যেত যা ব্যবস্থার কাছে নেই, আর
     * ক্যাশিয়ার ভাবতেন ওখান থেকেই মাল যাচ্ছে। কোন লট বেরোবে সেটা
     * FEFO ঠিক করে, স্ক্যানার নয়।
     */
    public function test_an_unknown_lot_is_not_echoed_back(): void
    {
        $this->scan($this->gs1(lot: 'NOPE'))
            ->assertOk()
            ->assertJsonPath('scanned_batch', null)
            ->assertJsonPath('batch_known', false);
    }

    /** লট ছাড়া ১ডি স্ক্যানে লটের ঘরগুলো খালি — কিছু আবিষ্কার করা হয় না। */
    public function test_a_plain_scan_reports_no_lot(): void
    {
        $this->scan('08901234567890')
            ->assertOk()
            ->assertJsonPath('scanned_batch', null)
            ->assertJsonPath('scanned_expiry', null);
    }

    // ── পাহারা ───────────────────────────────────────────────────

    /** কাউন্টারের অনুমতি ছাড়া স্ক্যানও চলে না। */
    public function test_the_counter_permission_is_required(): void
    {
        $clerk = User::factory()->create();
        $clerk->companies()->attach(CompanyContext::id(), ['is_active' => true]);
        $clerk->forceFill(['current_company_id' => CompanyContext::id()])->save();

        $this->actingAs($clerk)
            ->getJson(route('sales.pos.lookup', ['code' => '08901234567890']))
            ->assertForbidden();
    }
}
