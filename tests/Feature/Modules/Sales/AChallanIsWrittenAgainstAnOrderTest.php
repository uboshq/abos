<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\SalesOrderService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * চালান কাটা যায় কেবল আদেশের গায়ে।
 *
 * ── ⭐ মালিকের নিয়ম, ২১ সেপ্টেম্বর ২০২৬ ──────────────────────────────
 * *"invoice hobe only duti dorjate: ek direct sales, e dui order asbe
 * delivery hobe order ref.-e. kono direct challan kata zabena, order
 * ref.-e challan hobe tar por invoice hobe"*।
 *
 * ── ⓘ কেন নিয়মটা ন্যায্য ────────────────────────────────────────────
 * আদেশ ছাড়া চালান মানে **কেউ চায়নি এমন মাল বেরিয়ে গেছে**, আর তখন
 * *"কে বলেছিল পাঠাতে"* প্রশ্নের কোনো কাগজ থাকে না। ⚠️ বিলটা চালানের
 * গায়ে বসে, তাই ভিত্তিহীন চালান মানে ভিত্তিহীন বিল — গ্রাহক অস্বীকার
 * করলে আমাদের হাতে কিছুই নেই।
 *
 * ⓘ ছাঁচটা হুবহু [[AnInvoiceHasOnlyTwoDoorsTest]]-এর, কারণ নিয়ম দুইটা
 * একই শৃঙ্খলের দুইটা কড়া: আদেশ → চালান → বিল।
 */
final class AChallanIsWrittenAgainstAnOrderTest extends TestCase
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

    public function test_a_challan_without_an_order_is_refused(): void
    {
        $before = DeliveryChallan::query()->count();

        $this->post(route('sales.challan.store'), $this->paperwork())
            ->assertForbidden();

        $this->assertSame($before, DeliveryChallan::query()->count(), implode("\n", [
            '⛔ আদেশ ছাড়াই একটা চালান তৈরি হয়ে গেছে।',
            '',
            '⚠️ অর্থাৎ কেউ চায়নি এমন মাল বেরিয়ে গেল, আর "কে বলেছিল',
            'পাঠাতে" প্রশ্নের কোনো কাগজ রইল না।',
        ]));
    }

    /**
     * ⭐ আর পর্দাটাও নিয়মটা বলে — বন্ধ দরজা আগে থেকে জানা যায়।
     *
     * ⛔ ঘরটা ঐচ্ছিক দেখালে মানুষ গোটা ফর্মটা ভরে সংরক্ষণে গিয়ে তবেই
     * জানতেন পথটা বন্ধ। ⚠️ আর ওটা সবচেয়ে বিরক্তিকর রূপ: কাজটা হারায়
     * শেষ ধাপে।
     */
    public function test_the_form_says_the_order_is_required(): void
    {
        $html = (string) $this->get(route('sales.challan.create'))->assertOk()->getContent();

        $at = strpos($html, 'name="sales_order_id"');

        $this->assertNotFalse($at, '⛔ চালানের ফর্মে আদেশের ঘরটাই নেই।');

        /*
         * ⓘ `required`-টা ঘরটার নিজের ট্যাগে আছে কি না — গোটা পাতায়
         * খুঁজলে দাবিটা কিছুই প্রমাণ করত না, কারণ পাতায় আরো চারটা
         * `required` ঘর আছে। ⚠️ ঠিক এই ফাঁদে আজ রাতে দুইবার পড়েছি।
         */
        $tag = substr($html, $at, (int) (strpos($html, '>', $at) - $at));

        $this->assertStringContainsString('required', $tag,
            '⛔ আদেশের ঘরটা এখনো ঐচ্ছিক দেখাচ্ছে।');
    }

    /**
     * পাহারাটা সত্যিই তাকায়।
     *
     * ⓘ প্রথম দাবিটা চিরকাল সবুজ থাকত যদি কাগজপত্রটাই ত্রুটিপূর্ণ হত —
     * তখন ৪০৩-এর বদলে যাচাইয়ের ভুল আসত, আর গোনাও বাড়ত না। ⚠️ তাই
     * হুবহু একই কাগজে **আদেশটা জুড়ে** দেখা হয় চালানটা সত্যিই তৈরি হয়।
     */
    public function test_the_same_paperwork_with_an_order_goes_through(): void
    {
        /*
         * ⚠️ আদেশটা পরীক্ষা নিজেই বানায় — ডেমোতে একটাও নেই।
         *
         * ⛔ প্রথম চালে ডেমোর উপর ভরসা করা হয়েছিল, আর দাবিটা থেমে গেছে।
         * ⓘ থেমে যাওয়াটাই ঠিক ছিল (`fail`, `markTestSkipped` নয়): এই
         * দাবিটা না চললে উপরের প্রত্যাখ্যানটা **কিছুই প্রমাণ করে না** —
         * কাগজটা এমনিতেই ত্রুটিপূর্ণ হলেও ৪০৩ আসত।
         */
        $order = $this->orderFor($this->paperwork());

        $before = DeliveryChallan::query()->count();

        $this->post(route('sales.challan.store'), [
            ...$this->paperwork(),
            'sales_order_id' => $order->id,
            'customer_id' => $order->customer_id,
        ]);

        $this->assertGreaterThan($before, DeliveryChallan::query()->count(), implode("\n", [
            '⛔ আদেশ জুড়ে দেওয়ার পরেও চালানটা তৈরি হয়নি।',
            '',
            '⚠️ তার মানে উপরের প্রত্যাখ্যানটা আদেশের অভাবে নয়, কাগজের',
            'অন্য কোনো ভুলে — আর তখন পাহারাটা কিছুই প্রমাণ করে না।',
        ]));
    }

    /**
     * হুবহু ঐ কাগজের জন্য একটা বিক্রয় আদেশ।
     *
     * @param  array<string, mixed>  $paperwork
     */
    private function orderFor(array $paperwork): SalesOrder
    {
        /*
         * ⚠️ আদেশটা **নিশ্চিত** হতে হবে — খসড়া আদেশে চালান হয় না।
         *
         * ℹ প্রথম চালে কেবল তৈরি করা হয়েছিল, আর পর্দা বলেছে
         * *"SO-0001 অর্ডারটা নিশ্চিত অবস্থায় নেই"*। ⛔ দাবিটা তখন
         * লাল হয়েছে বলেই এটা গুরুত্বপূর্ণ: আদেশ → চালান শৃঙ্খলে এই
         * ধাপটাও আছে, আর পরীক্ষাটা সেটা শিখিয়েছে।
         */
        $order = app(SalesOrderService::class)->create(
            [
                'customer_id' => $paperwork['customer_id'],
                'warehouse_id' => $paperwork['warehouse_id'],
                'trx_date' => $paperwork['trx_date'],
            ],
            [[
                'product_id' => $paperwork['lines'][0]['product_id'],
                'ordered_qty' => '1',
                'rate' => '100',
            ]],
        );

        return app(SalesOrderService::class)->confirm($order);
    }

    /** @return array<string, mixed> */
    private function paperwork(): array
    {
        $product = Product::query()->orderBy('id')->firstOrFail();

        return [
            'customer_id' => Customer::query()->firstOrFail()->id,
            'warehouse_id' => Warehouse::query()->firstOrFail()->id,
            'trx_date' => now()->toDateString(),
            'lines' => [[
                'product_id' => $product->id,
                'delivered_qty' => '1',
                'rate' => '100',
            ]],
        ];
    }
}
