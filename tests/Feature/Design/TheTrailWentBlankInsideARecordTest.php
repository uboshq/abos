<?php

declare(strict_types=1);

namespace Tests\Feature\Design;

use App\Core\Services\MenuBuilder;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * রেকর্ডে ঢুকলেই "আমি কোথায়" প্রশ্নের উত্তরটা হারিয়ে যেত।
 *
 * ── কী ভাঙা ছিল, ২৯ আগস্ট ২০২৬ ───────────────────────────────────────
 * জমার পর্দাগুলো ব্রাউজারে খুলে দেখতে গিয়ে ধরা পড়ল: তালিকার পাতায় পথ
 * ছিল "ড্যাশবোর্ড / অর্থ / ব্যাংক আমানত", কিন্তু একটা FD-তে ঢুকলেই পথটা
 * হয়ে যেত কেবল "ড্যাশবোর্ড" — মডিউলের নামটাও নেই।
 *
 * `/accounts/vouchers/1`-এও একই — অর্থাৎ দোষটা নতুন পর্দার নয়, খোলসের,
 * আর প্রতিটা রেকর্ড পাতায় এটা এতদিন ছিল।
 *
 * কারণটা এক লাইনের: breadcrumb পথটা মেনুর `active` পতাকা থেকে বানায়
 * ([[shell/crumbbar]]), আর ওই পতাকা ছিল কেবল `routeIs($item['route'])` —
 * `finance.deposit.index` আর `finance.deposit.show` দুইটা আলাদা নাম।
 *
 * ── কেন এটার নিজের টেস্ট ─────────────────────────────────────────────
 * ভুলটা নীরব: কোনো পাতা ৫০০ দেয় না, কোনো টেস্ট লাল হয় না। কেবল
 * ব্যবহারকারী প্রতিবার উপরে ফেরার লিংকটা খুঁজে পান না, আর ব্রাউজারের
 * back বোতাম ছাড়া উপায় থাকে না।
 */
class TheTrailWentBlankInsideARecordTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->user = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs($this->user);
    }

    /**
     * এই অনুরোধে কোন সারিগুলো জ্বলছে।
     *
     * @return list<string>
     */
    private function litRows(): array
    {
        $lit = [];

        foreach (app(MenuBuilder::class)->forUser($this->user) as $module) {
            foreach ($module['groups'] as $items) {
                foreach ($items as $item) {
                    if ($item['active']) {
                        $lit[] = $item['route'].'|'.$item['label'];
                    }
                }
            }
        }

        return $lit;
    }

    /**
     * রেকর্ডের পাতায় তার তালিকার সারিটাই জ্বলে।
     */
    public function test_a_record_page_lights_up_the_list_it_came_from(): void
    {
        $this->get('/finance/deposits/bank');
        $this->assertSame(['finance.deposit.index|ব্যাংক আমানত'], $this->litRows(),
            'তালিকার পাতাতেই সারিটা জ্বলছে না।');

        /*
         * এবার একটা রেকর্ডে — সারিটা জ্বলতে থাকা চাই।
         *
         * সারিটা আসলেই থাকতে হবে না: প্রশ্নটা রুটের নাম নিয়ে, ডেটা নিয়ে
         * নয়। `/finance/deposits/bank/1` না থাকলে ৪০৪, কিন্তু রুটটা
         * তখনো `finance.deposit.show`, আর মেনু ওটাই পড়ে।
         */
        $this->get('/finance/deposits/bank/1');
        $this->assertSame(['finance.deposit.index|ব্যাংক আমানত'], $this->litRows(),
            'রেকর্ডে ঢুকতেই সারিটা নিভে গেছে — breadcrumb-ও তাই ফাঁকা হবে।');
    }

    /**
     * এক পরিবারের দুইটা সারি একসাথে জ্বলে না।
     *
     * ── কেন এই পাহারাটা লাগে ────────────────────────────────────────
     * "একই উপসর্গ হলেই জ্বলবে" নিয়মে পণ্যের তালিকা আর লেবেল ছাপা —
     * দুইটাই `inventory.product.*` — একসাথে জ্বলত, আর breadcrumb-এ
     * ভুল নামটা বসতে পারত। তাই নিয়মটা সরু: কেবল `.index` সারিটা, আর
     * কেবল তখন যখন এই রুটের নিজের কোনো সারি নেই।
     */
    public function test_only_one_row_can_be_lit_at_a_time(): void
    {
        foreach (['/inventory/products', '/finance/deposits/bank/1',
            '/accounts/loans', '/finance/deposits/national_savings'] as $url) {
            $this->get($url);

            $this->assertLessThanOrEqual(1, count($this->litRows()),
                "{$url}-এ একের বেশি মেনু সারি জ্বলছে: ".implode(' · ', $this->litRows()));
        }
    }

    /**
     * যে রুটের নিজের সারি আছে, সেটাই জ্বলে — তার তালিকা নয়।
     *
     * পণ্যের তালিকায় দাঁড়িয়ে "লেবেল ছাপা" জ্বলা মানে ব্যবহারকারীকে
     * বলা সে অন্য পর্দায় আছে।
     */
    public function test_a_screen_with_its_own_row_lights_that_row(): void
    {
        $this->get('/inventory/products');

        $lit = $this->litRows();

        $this->assertCount(1, $lit);
        $this->assertStringStartsWith('inventory.product.index|', $lit[0]);
    }
}
