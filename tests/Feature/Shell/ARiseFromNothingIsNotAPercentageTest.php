<?php

declare(strict_types=1);

namespace Tests\Feature\Shell;

use App\Core\Dashboard\DashboardRegistry;
use App\Core\Dashboard\Widget;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Services\SalesInvoiceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * শূন্য থেকে বাড়াকে শতাংশে লেখা যায় না।
 *
 * ── কার্ডের তুলনার চিপ ──────────────────────────────────────────────
 * "আজ ৪,০৫০" একটা বিন্দু। পাশে "↑ ১২.৪%" বসলে মালিক এক নজরে জানেন
 * ব্যবসাটা কোন দিকে যাচ্ছে। কিন্তু তুলনাটার দুইটা ফাঁদ আছে, আর
 * দুইটাতেই সংখ্যাটা মিথ্যা হয়ে যায়:
 *
 *   • **গতকালের সাথে তুলনা।** ডিপোর সপ্তাহে ছক আছে — শুক্রবার ফাঁকা,
 *     বৃহস্পতিবার ভরা। গতকাল ধরে গুনলে প্রতি শনিবার "৮০% পড়ে গেছে"
 *     দেখাত, অথচ কিছুই ঘটেনি।
 *   • **আগের দিন শূন্য।** শূন্য থেকে বাড়া শতাংশে অসীম। "নতুন" আর
 *     "১০০% বেড়েছে" এক কথা নয়, আর দ্বিতীয়টা লিখলে সেটা মিথ্যা।
 *
 * ── আর করণীয় সারির আইকন ─────────────────────────────────────────────
 * অচেনা নাম দিলে আইকন কম্পোনেন্ট চুপ করে কিছুই আঁকে না — ভাঙে না।
 * তাই "আইকন দিয়েছি" বলা যথেষ্ট নয়; নামটা সেটে সত্যিই আছে কি না
 * সেটাই এখানে পরখ করা হয়।
 */
class ARiseFromNothingIsNotAPercentageTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
    }

    private function sell(string $rate, ?string $on = null): void
    {
        $invoice = app(SalesInvoiceService::class)->create(
            [
                'customer_id' => Customer::query()->value('id'),
                'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
                'trx_date' => $on ?? now()->toDateString(),
            ],
            [['product_id' => Product::query()->value('id'), 'qty' => '1', 'rate' => $rate]],
        );

        app(SalesInvoiceService::class)->confirm($invoice);
    }

    /** @return list<Widget> */
    private function today(): array
    {
        return app(DashboardRegistry::class)->forUser($this->owner)['today'] ?? [];
    }

    private function salesCard(): ?Widget
    {
        foreach ($this->today() as $widget) {
            if ($widget->label === __('sales::dashboard.sales_today')) {
                return $widget;
            }
        }

        return null;
    }

    // ── তুলনা ───────────────────────────────────────────────────────

    /** গত সপ্তাহের একই বারের সাথে মিলিয়ে শতাংশটা সত্যিই বেরোয়। */
    public function test_today_is_compared_with_the_same_weekday_last_week(): void
    {
        $this->sell('1000', now()->subWeek()->toDateString());
        $this->sell('1500');

        $card = $this->salesCard();

        $this->assertNotNull($card, 'আজকের বিক্রয়ের কার্ডটাই নেই।');
        $this->assertSame('+50.0%', $card->delta,
            '১০০০ থেকে ১৫০০ মানে ৫০% — তুলনাটা হয়নি বা ভুল দিন ধরেছে।');
    }

    /** কমলে চিহ্নটা ঋণাত্মক — দুই দিকেই সত্যি বলতে হয়। */
    public function test_a_fall_is_shown_as_a_fall(): void
    {
        $this->sell('2000', now()->subWeek()->toDateString());
        $this->sell('1000');

        $this->assertSame('-50.0%', $this->salesCard()?->delta);
    }

    /**
     * আগের বার শূন্য হলে কোনো শতাংশ নেই।
     *
     * ── কেন এই টেস্টটা আসল ──────────────────────────────────────────
     * শূন্য দিয়ে ভাগ করলে PHP-তে ব্যতিক্রম ওঠে না, bcdiv চুপ করে
     * থাকে বা শূন্য দেয় — অর্থাৎ ভুলটা পর্দায় গিয়ে একটা মিথ্যা
     * সংখ্যা হয়ে বসত, কোনো এররে নয়।
     */
    public function test_nothing_last_week_means_no_percentage_at_all(): void
    {
        $this->sell('1500');

        $card = $this->salesCard();

        $this->assertNotNull($card);
        $this->assertNull($card->delta,
            'গত সপ্তাহে কিছু বিক্রি হয়নি, তবু একটা শতাংশ দেখানো হচ্ছে।');
    }

    /**
     * চিপ না থাকলেও "কিসের তুলনায়" কথাটা থাকে।
     *
     * শতাংশ ছাড়া কেবল সংখ্যা থাকলে পাঠক জানেন না তুলনাটা আদৌ হয়েছে
     * কি না — "গত রবিবারের তুলনায়" লেখাটা বলে দেয় দেখা হয়েছিল,
     * আর সেদিন কিছু ছিল না।
     */
    public function test_the_reader_is_told_what_the_comparison_was_against(): void
    {
        $this->sell('1500');

        $expected = __('core.dashboard.against_last', [
            'day' => now()->subWeek()->locale(app()->getLocale())->dayName,
        ]);

        $this->assertSame($expected, $this->salesCard()?->hint);

        $this->get(route('dashboard'))->assertOk()->assertSee($expected);
    }

    /** চিপটা সত্যিই পর্দায় আঁকা হয় — কেবল বস্তুতে থেকে লাভ নেই। */
    public function test_the_chip_reaches_the_screen(): void
    {
        $this->sell('1000', now()->subWeek()->toDateString());
        $this->sell('1500');

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('50.0%');
    }

    /**
     * সংজ্ঞাটা টুলটিপে, তুলনার লেখাটা পর্দায় — দুইটা আলাদা ঘর।
     *
     * একটাই ঘরে দুইটা রাখতে গিয়ে আগে সংজ্ঞাটা তুলনার লেখাটাকে
     * সরিয়ে দিয়েছিল, আর কার্ডে "কিসের তুলনায়" আর লেখা হত না।
     */
    public function test_the_definition_and_the_comparison_are_two_different_things(): void
    {
        $this->sell('1500');

        $card = $this->salesCard();

        $this->assertNotNull($card->definition, 'সংখ্যাটার সংজ্ঞা হারিয়ে গেছে।');
        $this->assertNotSame($card->definition, $card->hint);
    }

    // ── আইকন ────────────────────────────────────────────────────────

    /** করণীয়ের প্রতিটা সারির নিজের আইকন আছে। */
    public function test_every_todo_row_carries_an_icon(): void
    {
        $todo = app(DashboardRegistry::class)->forUser($this->owner)['todo'] ?? [];

        $this->assertNotEmpty($todo);

        foreach ($todo as $widget) {
            $this->assertNotNull($widget->icon,
                "\"{$widget->label}\" সারিতে কোনো আইকন নেই — নমুনায় প্রতিটাতেই আছে।");
        }
    }

    /**
     * প্রতিটা নাম সত্যিই সেটে আছে।
     *
     * অচেনা নাম দিলে কম্পোনেন্ট চুপ করে কিছুই আঁকে না। তাই নাম বসিয়ে
     * দিলেই ঘরটা ভরে যায় না — এখানে সত্যিই কিছু বেরোয় কি না দেখা হয়।
     *
     * ── কেন দাবিটা `<svg` থেকে সরে এল, ২৮ আগস্ট ২০২৬ ────────────────
     * মডিউলের চিহ্ন এখন ইমোজি, কাজেরগুলো আঁকা — দুইটা আলাদা markup।
     * `<svg` খুঁজলে এই পাহারা মডিউলের নামগুলোকে "নেই" বলত, অথচ ওরা
     * ঠিকই আঁকা হচ্ছে।
     *
     * আসল দাবিটা কখনোই "SVG" ছিল না; ছিল **"ঘরটা খালি থাকে না"**।
     * সেটাই লেখা হলো, আর তাতে পাহারাটা markup বদলালেও টিকে যায়।
     *
     * কোনটা ইমোজি আর কোনটা আঁকা — সেই নিয়মটা [[IconSetTest]]-এর কাজ।
     */
    public function test_the_icon_names_are_ones_the_set_actually_has(): void
    {
        $groups = app(DashboardRegistry::class)->forUser($this->owner);

        $checked = 0;

        foreach ($groups as $widgets) {
            foreach ($widgets as $widget) {
                if ($widget->icon === null) {
                    continue;
                }

                $drawn = trim(Blade::render('<x-ui.icon :name="$name" />', ['name' => $widget->icon]));

                $this->assertNotSame('', $drawn,
                    "\"{$widget->icon}\" নামের কোনো আইকন সেটে নেই — ঘরটা নীরবে খালি থাকত।");

                $checked++;
            }
        }

        $this->assertGreaterThan(0, $checked, 'একটা উইজেটেও আইকন নেই — পরখ করার কিছু ছিল না।');
    }
}
