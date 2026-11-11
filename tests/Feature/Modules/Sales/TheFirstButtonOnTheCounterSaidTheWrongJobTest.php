<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Engines\Dashboard\DashboardEngine;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * কাউন্টারের প্রথম বোতামটা ভুল কাজের নাম বলত।
 *
 * ── ⓘ মালিকের নির্দেশ, ২১ সেপ্টেম্বর ২০২৬ ────────────────────────────
 * *"New invoice name kicui thakbena eta direct sales hobe"* — বিক্রয়ের
 * ড্যাশবোর্ডের প্রথম টাইলটা "নতুন বিল"-এ নিয়ে যেত।
 *
 * ── ⛔ কেন ওটা ভুল কাজ ───────────────────────────────────────────────
 * কাউন্টারে দিনের কাজটা বিল **লেখা** নয়, মাল **বেচা**। সরাসরি বিক্রয়ে
 * এক চাপে তিনটাই হয় — চালান, বিল আর জমা। ⚠️ বিলের ফর্ম আলাদা একটা
 * কাজ: মাল আগেই গেছে, এখন কেবল কাগজ। দিনের সবচেয়ে বেশি চাপা
 * বোতামটা ভুল দরজায় নামলে প্রতিটা বিক্রয়ে কয়েকটা বাড়তি ক্লিক।
 */
final class TheFirstButtonOnTheCounterSaidTheWrongJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    /**
     * ⭐ প্রথম বোতামটা সরাসরি বিক্রয়ে নামে।
     *
     * ⚠️ দাবিটা দুইমুখী: সরাসরি বিক্রয়ের ঠিকানা **থাকে**, আর বিলের
     * ফর্মের ঠিকানাটা প্রথম টাইলে **থাকে না**। ⓘ কেবল প্রথমটা দেখলে
     * পরীক্ষাটা এমন পর্দাতেও সবুজ থাকত যেখানে দুইটা বোতামই বসানো।
     */
    public function test_the_first_action_opens_the_direct_sale_counter(): void
    {
        $first = $this->firstTile();

        $this->assertStringContainsString(route('sales.direct.create'), $first, 'প্রথম টাইলটা সরাসরি বিক্রয়ে নামে না।');
        $this->assertStringNotContainsString(route('sales.invoice.create'), $first);
    }

    /**
     * ⛔ পর্দাটা বন্ধ রাখলে ড্যাশবোর্ড তার দরজা খুলে রাখে না।
     *
     * ⚠️ মেনুর সারিটা `sales.screen_direct` মানে; টাইলটা না মানলে
     * ড্যাশবোর্ড মালিকের নিজের সিদ্ধান্তকেই অগ্রাহ্য করত।
     */
    public function test_a_company_that_hid_the_direct_screen_gets_the_order_button(): void
    {
        app(SettingsService::class)->set('sales.screen_direct', false);

        $first = $this->firstTile();

        /*
         * ⭐ এখানে আগে বিলের ফর্ম ছিল — ২১ সেপ্টেম্বর ২০২৬-এ বদলেছে।
         *
         * ⓘ দাবিটার উদ্দেশ্য বদলায়নি: সুইচ বন্ধ থাকলে ড্যাশবোর্ড যেন
         * **বন্ধ দরজায়** না পাঠায়। কেবল গন্তব্যটা বদলেছে।
         *
         * ⛔ মালিকের নিয়ম: বিলের দুইটাই দরজা — আদেশ আর সরাসরি বিক্রয়।
         * ⚠️ INV-0002 (৫৬,৯৬,৫৯,০৭,৪১২ টাকা) ঠিক এই টাইল থেকেই হয়েছিল:
         * একটা খালি ফর্ম, পিছনে কোনো কাগজ নেই, মেলানোর কিছু নেই।
         *
         * ⓘ এখন খালি ফর্মটা ৪০৪ দেয়, তাই টাইলটা রেখে দিলে ব্যবহারকারী
         * একটা বন্ধ দরজায় গিয়ে পড়তেন — পুরনো দাবিটা তখন **ভাঙা আচরণই**
         * পাহারা দিত। দাবি: [[AnInvoiceHasOnlyTwoDoorsTest]]।
         */
        $this->assertStringContainsString(route('sales.order.create'), $first);
        $this->assertStringNotContainsString(route('sales.invoice.create'), $first);
        $this->assertStringNotContainsString(route('sales.direct.create'), $first);
    }

    /**
     * প্রথম দ্রুত-কাজের বোতাম — ফোনের ড্যাশবোর্ড যা পায় ([[DashboardApiController]]-এর 'tiles')।
     *
     * ⓘ মালিক, 10 নভেম্বর 2026 রাত: ওয়েবের বিক্রয় ড্যাশবোর্ড এখন চূড়ান্ত নমুনার বোর্ড (H8JXFPzh8m7zpsQSedmPDT) — বোতামের সারি নেই,
     *   সরাসরি বিক্রয় মেনুতে। বোতামগুলো বাঁচে ফোনে, তাই দাবিটা সেখানে: প্রথম বোতাম কোন দরজায়।
     */
    private function firstTile(): string
    {
        $tiles = app(DashboardEngine::class)->for('sales', auth()->user())->tiles;

        $this->assertNotEmpty($tiles, 'ড্যাশবোর্ডে বোতামই পাওয়া গেল না — পরীক্ষাটা কিছু দেখছে না।');

        return $tiles[0]->href;
    }
}
