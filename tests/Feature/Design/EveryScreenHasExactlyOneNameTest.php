<?php

declare(strict_types=1);

namespace Tests\Feature\Design;

use App\Core\Support\CompanyContext;
use App\Core\Support\PageHeading;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ পাতার মূল শিরোনাম (h1) কোথাও দুটো, কোথাও একটাও নয় — ওয়েব-মান ধাপ ২, ২০ অক্টোবর ২০২৬ ([[PageHeading]])।
 *
 * <p>লাইভ নিরীক্ষা: ১৪টা পাতায় দুটো (page-header আর toolbar দুজনেই h1 আঁকত), ৬টা কাজের পাতায় একটাও নয়।
 *
 * দাবি:
 *  - নিরীক্ষার সেই ২০টা পাতা আসল দরজা দিয়ে খুললে প্রতিটায় ঠিক একটা `<h1`;
 *  - যে পাতায় আগে দুটো ছিল, সেখানে দ্বিতীয়টা এখন h2 — শিরোনামের লেখা হারায় না;
 *  - এক অনুরোধের h1 পরের অনুরোধে গোনা হয় না (প্রথম পাতা খোলার পর দ্বিতীয় পাতাও h1 পায়)।
 */
final class EveryScreenHasExactlyOneNameTest extends TestCase
{
    use RefreshDatabase;

    /** নিরীক্ষার তালিকা — আগে দুটো h1 */
    private const TWICE = [
        'sales.price_list.index', 'sales.price_book.index', 'accounts.custody', 'sales.direct.depot_check',
        'sales.lead.index', 'sales.opportunity.index', 'sales.scheme.index', 'purchase.payment_schedule.index',
        'finance.bank_charge.index', 'finance.insurance.index', 'finance.budget.report', 'accounts.note.index',
        'approval.inbox.mine', 'approval.inbox.signed_unposted',
    ];

    /** নিরীক্ষার তালিকা — আগে একটাও নয় */
    private const NEVER = [
        'sales.delivery_order.create', 'sales.direct.create', 'purchase.direct.create', 'finance.profit.index',
        'approval.delegation.index', 'approval.exception.index',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    public function test_each_audited_screen_has_exactly_one_h1(): void
    {
        $opened = 0;
        $wrong = [];

        foreach ([...self::TWICE, ...self::NEVER] as $name) {
            $response = $this->get(route($name));

            /*
             * ⓘ ডিফল্টে বন্ধ মডিউলের পর্দা ৪০৪ দেয় — সেটা এই দাবির বিষয় নয়; কিন্তু কতগুলো সত্যিই খোলা হলো তা নিচে গোনা,
             * যাতে সব বন্ধ হয়ে দাবিটা ফাঁকা সবুজ না হয়।
             */
            if (in_array($response->status(), [403, 404], true)) {
                continue;
            }

            $response->assertOk();
            $opened++;

            $count = preg_match_all('/<h1[\s>]/i', (string) $response->getContent());
            if ($count !== 1) {
                $wrong[] = "{$name}: {$count}টা h1";
            }
        }

        $this->assertGreaterThanOrEqual(14, $opened, 'মাত্র '.$opened.'টা পাতা খুলল — দাবির ভিত্তি নেই');
        $this->assertSame([], $wrong, "⛔ এই পাতাগুলোয় মূল শিরোনাম ঠিক একটা নয়:\n  ".implode("\n  ", $wrong));
    }

    public function test_the_second_heading_keeps_its_words_as_an_h2(): void
    {
        $html = (string) $this->get(route('sales.lead.index'))->assertOk()->getContent();

        $this->assertSame(1, preg_match_all('/<h1[\s>]/i', $html));
        // ⓘ toolbar-এর শিরোনাম চেহারা ভেদে সরাসরি লেখা বা দৃশ্যের মেনু — দুই রূপেই h2-র ভেতরে, লেখাসহ
        $this->assertMatchesRegularExpression('/<h2[\s>]/i', $html, '⛔ দ্বিতীয় শিরোনামটা h2 হলো না');
        $this->assertGreaterThanOrEqual(2, substr_count($html, e(__('sales::crm.leads'))),
            '⛔ দ্বিতীয় শিরোনামের লেখা হারিয়ে গেল');
    }

    public function test_one_request_does_not_use_up_the_next_ones_h1(): void
    {
        $this->assertSame('h1', PageHeading::tag());
        $this->assertSame('h2', PageHeading::tag());

        $this->get(route('sales.lead.index'))->assertOk();
        $html = (string) $this->get(route('approval.inbox.mine'))->assertOk()->getContent();

        $this->assertSame(1, preg_match_all('/<h1[\s>]/i', $html), '⛔ আগের অনুরোধের h1 পরের পাতা খেয়ে ফেলল');
        $this->assertTrue(PageHeading::present('<h1 class="x">a</h1>'));
        $this->assertFalse(PageHeading::present('<h2>a</h2>', '<header>'));
    }
}
