<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\SalesInvoiceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * কোন পণ্যে আসলে লাভ হচ্ছে।
 *
 * ── কেন গ্রাহকভিত্তিক রিপোর্টটা যথেষ্ট নয় ───────────────────────────
 * ওটা বলে **কে** লাভজনক। কিন্তু সিদ্ধান্তগুলো পণ্য ধরে নিতে হয়: কোনটার
 * দর বাড়াতে হবে, কোনটা তাকের জায়গা নিচ্ছে অথচ কিছু দিচ্ছে না, কোনটার
 * ছাড় পুরো মার্জিনটাই খেয়ে ফেলেছে। ডিপোতে এই প্রশ্নটাই রোজকার।
 */
class WhichProductActuallyPaysTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Product $cheap;

    private Product $dear;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        [$this->cheap, $this->dear] = Product::query()->orderBy('id')->take(2)->get()->all();
    }

    /**
     * একটা নিশ্চিত বিল — জানা ক্রয়মূল্যসহ।
     *
     * ক্রয়মূল্য হাতে বসানো, কারণ প্রশ্নটা "স্তর ঠিক আছে কি না" নয়,
     * "মুনাফার অঙ্কটা ঠিক কি না"। স্তরের নিজের পরীক্ষা আলাদা আছে।
     */
    private function sell(Product $product, string $qty, string $rate, string $cost,
        string $status = DocumentStatus::CONFIRMED): SalesInvoice
    {
        $invoice = app(SalesInvoiceService::class)->create(
            [
                'customer_id' => Customer::query()->value('id'),
                'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
                'trx_date' => now()->toDateString(),
            ],
            [['product_id' => $product->id, 'qty' => $qty, 'rate' => $rate]],
        );

        if ($status !== DocumentStatus::DRAFT) {
            $invoice = app(SalesInvoiceService::class)->confirm($invoice);
        }

        $invoice->lines()->update(['unit_cost' => $cost]);

        return $invoice->fresh(['lines']);
    }

    /** @return array<string, array<string, mixed>> পণ্যের নাম ধরে সারি */
    private function rows(): array
    {
        $result = app(ReportEngine::class)->run('sales.by_product', [
            'from' => now()->subDay()->toDateString(),
            'to' => now()->addDay()->toDateString(),
        ]);

        $out = [];

        foreach ($result->rows as $row) {
            $row = (array) $row;
            $out[$row['product_name']] = $row;
        }

        return $out;
    }

    private function nameOf(Product $product): string
    {
        return $product->code.' - '.($product->name_bn ?: $product->name_en);
    }

    // ── অঙ্কটা ──────────────────────────────────────────────────────

    /** বিক্রয় বাদ ক্রয়মূল্য — সারিটার মুনাফা। */
    public function test_the_profit_is_what_was_sold_less_what_it_cost(): void
    {
        $this->sell($this->cheap, '10', '100', '60');   // ১,০০০ − ৬০০ = ৪০০

        $row = $this->rows()[$this->nameOf($this->cheap)] ?? null;

        $this->assertNotNull($row, 'পণ্যটার সারিই রিপোর্টে নেই।');
        $this->assertSame(0, bccomp((string) $row['revenue'], '1000', 2));
        $this->assertSame(0, bccomp((string) $row['cost'], '600', 2));
        $this->assertSame(0, bccomp((string) $row['gross_profit'], '400', 2));
    }

    /**
     * ভ্যাট মুনাফা নয়।
     *
     * ── কেন এটা সহজে চোখ এড়ায় ───────────────────────────────────────
     * লাইনের `amount` = (পরিমাণ × দর) − ছাড় **+ ভ্যাট**। ওটাকেই
     * "বিক্রয়" ধরলে ভ্যাটটা মুনাফায় ঢুকে পড়ে, অথচ ওটা সরকারের টাকা —
     * আমরা কেবল আদায় করে জমা দিই। ফল: ৫% ভ্যাটওয়ালা পণ্য ৫% বেশি
     * লাভজনক দেখাত, আর ওই ভুল তুলনার উপরেই দর ঠিক হত।
     *
     * প্রথম রূপে এই রিপোর্টে ঠিক ওই ভুলটাই ছিল।
     */
    public function test_vat_is_not_counted_as_profit(): void
    {
        $invoice = app(SalesInvoiceService::class)->create(
            [
                'customer_id' => Customer::query()->value('id'),
                'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
                'trx_date' => now()->toDateString(),
            ],
            [['product_id' => $this->cheap->id, 'qty' => '10', 'rate' => '100', 'tax' => '150']],
        );

        app(SalesInvoiceService::class)->confirm($invoice);
        $invoice->lines()->update(['unit_cost' => '60']);

        $row = $this->rows()[$this->nameOf($this->cheap)];

        // ১,০০০ বিক্রয় (১৫০ ভ্যাট বাদে), ৬০০ ক্রয়মূল্য → ৪০০ মুনাফা
        $this->assertSame(0, bccomp((string) $row['revenue'], '1000', 2),
            "ভ্যাটটা বিক্রয়ে গোনা হয়েছে — {$row['revenue']}, ১,০০০ নয়।");
        $this->assertSame(0, bccomp((string) $row['gross_profit'], '400', 2),
            "ভ্যাটটা মুনাফায় গোনা হয়েছে — {$row['gross_profit']}, ৪০০ নয়।");
        $this->assertSame(0, bccomp((string) $row['margin_percent'], '40', 2));
    }

    /**
     * মার্জিন শতাংশে — টাকার অঙ্ক একা যথেষ্ট নয়।
     *
     * ৪০০ টাকা লাভ ১,০০০ টাকার বিক্রিতে ৪০%; ১০,০০০ টাকার বিক্রিতে ৪%।
     * কেবল টাকা দেখলে বড় পণ্যগুলো সবসময় উপরে থাকত।
     */
    public function test_the_margin_is_shown_as_a_percentage(): void
    {
        $this->sell($this->cheap, '10', '100', '60');

        $row = $this->rows()[$this->nameOf($this->cheap)];

        $this->assertSame(0, bccomp((string) $row['margin_percent'], '40', 2),
            "মার্জিন {$row['margin_percent']}%, ৪০% নয়।");
    }

    /**
     * লোকসানের সারিটা লুকানো হয় না।
     *
     * যে পণ্যে লোকসান হচ্ছে সেটাই সবচেয়ে জরুরি সারি, আর সেটাই সবচেয়ে
     * সহজে চোখ এড়ায় — কারণ তালিকা সাজানো থাকে লাভ ধরে, আর সে থাকে
     * সবার নিচে।
     */
    public function test_a_product_sold_at_a_loss_still_appears(): void
    {
        $this->sell($this->dear, '5', '80', '100');   // ৪০০ − ৫০০ = −১০০

        $row = $this->rows()[$this->nameOf($this->dear)] ?? null;

        $this->assertNotNull($row, 'লোকসানের সারিটা রিপোর্ট থেকে বাদ পড়েছে।');
        $this->assertSame(-1, bccomp((string) $row['gross_profit'], '0', 2),
            'লোকসান ঋণাত্মক দেখানো হয়নি।');
    }

    /** সবচেয়ে লাভজনকটা উপরে — তালিকা না সাজালে বিশ সারিতে খুঁজতে হত। */
    public function test_the_most_profitable_product_is_first(): void
    {
        $this->sell($this->dear, '1', '100', '95');    // ৫ টাকা
        $this->sell($this->cheap, '10', '100', '60');  // ৪০০ টাকা

        $names = array_keys($this->rows());

        $this->assertSame($this->nameOf($this->cheap), $names[0],
            'সবচেয়ে লাভজনক পণ্যটা উপরে নেই।');
    }

    // ── খসড়া ───────────────────────────────────────────────────────

    /**
     * খসড়া বিল গোনা হয় না — হোম পর্দার সাথে একই নিয়ম।
     *
     * ── কেন এটা এখানে আলাদা করে পরীক্ষা ─────────────────────────────
     * গ্রাহকভিত্তিক রিপোর্টে ঠিক এই ভুলটাই ছিল: "বাতিল ছাড়া সব" মানে
     * খসড়াও। কাউন্টারে ধরে রাখা একটা বিল — ক্রেতা টাকা আনতে গেছেন —
     * রিপোর্টে যোগ হয়ে বসে থাকত, অথচ ড্যাশবোর্ড ওটা গুনত না। একই
     * প্রশ্নে দুইটা উত্তর, আর তার উপর মুনাফা হিসাব হত এমন বিল ধরে যেটা
     * এখনো বিক্রয়ই নয়।
     */
    public function test_a_draft_bill_is_not_counted(): void
    {
        $this->sell($this->cheap, '10', '100', '60');
        $this->sell($this->cheap, '10', '100', '60', DocumentStatus::DRAFT);

        $row = $this->rows()[$this->nameOf($this->cheap)];

        $this->assertSame(0, bccomp((string) $row['revenue'], '1000', 2),
            "খসড়াটাও গোনা হয়েছে — বিক্রয় {$row['revenue']}, ১,০০০ নয়।");
    }

    /** গ্রাহকভিত্তিক রিপোর্টেও একই নিয়ম — যে ভুলটা ওখানে ছিল। */
    public function test_the_by_customer_report_does_not_count_drafts_either(): void
    {
        $this->sell($this->cheap, '10', '100', '60');
        $this->sell($this->cheap, '10', '100', '60', DocumentStatus::DRAFT);

        $result = app(ReportEngine::class)->run('sales.by_customer', [
            'from' => now()->subDay()->toDateString(),
            'to' => now()->addDay()->toDateString(),
        ]);

        $total = '0';

        foreach ($result->rows as $row) {
            $total = bcadd($total, (string) ((array) $row)['total'], 4);
        }

        $this->assertSame(0, bccomp($total, '1000', 2),
            "গ্রাহকভিত্তিক রিপোর্টে খসড়াও গোনা হয়েছে — মোট {$total}, ১,০০০ নয়।");
    }

    // ── ক্রয়মূল্য আড়াল (নিয়ম ২৪) ──────────────────────────────────

    /** @param  list<string>  $extra */
    private function clerk(array $extra = []): User
    {
        foreach (['sales.report', 'sales.cost.view'] as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $user = User::factory()->create();
        $user->companies()->attach($this->company, ['is_active' => true]);
        $user->forceFill(['current_company_id' => $this->company->id])->save();
        $user->givePermissionTo(array_merge(['sales.report'], $extra));

        return $user->fresh();
    }

    /**
     * বিক্রয়কর্মী পরিমাণ ও বিক্রয় দেখেন — ক্রয়মূল্য, মুনাফা, মার্জিন নয়।
     *
     * মার্জিনটাও ঢাকতে হয়: শতাংশ আর বিক্রয় জানা থাকলে ক্রয়মূল্য এক
     * ভাগেই বেরিয়ে আসে। একটা ঢেকে অন্যটা খোলা রাখা মানে কিছুই না ঢাকা।
     */
    public function test_a_clerk_sees_what_sold_but_not_what_it_cost(): void
    {
        $this->sell($this->cheap, '10', '100', '60');

        $this->actingAs($this->clerk())
            ->get(route('sales.report.show', ['slug' => 'by-product']))
            ->assertOk()
            ->assertSee(__('sales::field.revenue'))
            ->assertDontSee(__('sales::field.cost'))
            ->assertDontSee(__('sales::field.gross_profit'))
            ->assertDontSee(__('sales::field.margin_percent'));
    }

    /** অনুমতি থাকলে তিনটাই দেখা যায় — নাহলে উপরেরটা কিছুই প্রমাণ করত না। */
    public function test_with_permission_all_three_are_there(): void
    {
        $this->sell($this->cheap, '10', '100', '60');

        $this->actingAs($this->clerk(['sales.cost.view']))
            ->get(route('sales.report.show', ['slug' => 'by-product']))
            ->assertOk()
            ->assertSee(__('sales::field.cost'))
            ->assertSee(__('sales::field.gross_profit'))
            ->assertSee(__('sales::field.margin_percent'));
    }

    /**
     * বিক্রয়ের প্রতিটা নিবন্ধিত রিপোর্টের একটা দরজা আছে।
     *
     * ── কেন এই পাহারাটা ─────────────────────────────────────────────
     * এই রিপোর্টটা লেখার সময় ঠিক এটাই ঘটেছিল: রিপোর্ট নিবন্ধিত, মেনুতে
     * সারি বসানো, অথচ কন্ট্রোলারের slug-তালিকায় নাম নেই — লিংকে ক্লিক
     * করলে 404। রিপোর্টটা আছে, শুধু কেউ পৌঁছাতে পারে না, আর কোথাও কোনো
     * ভুলের চিহ্ন নেই।
     */
    public function test_every_sales_report_has_a_door(): void
    {
        $engine = app(ReportEngine::class);

        $sales = array_filter($engine->keys(), fn (string $k) => str_starts_with($k, 'sales.'));

        $this->assertNotSame([], $sales);

        foreach ($sales as $key) {
            $slug = str_replace('_', '-', substr($key, strlen('sales.')));

            $this->get(route('sales.report.show', ['slug' => $slug]))
                ->assertOk();
        }
    }

    /** রপ্তানিতেও ঢাকা — পর্দায় ঢেকে CSV-তে খোলা রাখা মানে কিছুই না ঢাকা। */
    public function test_the_export_hides_it_too(): void
    {
        $this->sell($this->cheap, '10', '100', '60');

        $response = $this->actingAs($this->clerk())
            ->get(route('sales.report.show', ['slug' => 'by-product', 'export' => 'csv']))
            ->assertOk();

        /*
         * সত্যিই একটা ফাইল, পাতা নয়।
         *
         * ── এটাই আসল দাবি ───────────────────────────────────────────
         * এই পরীক্ষাটার আগের রূপে এই লাইনটা ছিল না, আর তাতে সে
         * **HTML পাতাটাই পড়ত** — কারণ রিপোর্টের পর্দায় রপ্তানি কোনোদিন
         * কাজই করেনি, `?export=csv` চুপচাপ পাতাটাই ফেরত দিত। পাতায়
         * কলামটা এমনিতেই অনুমতির পেছনে ঢাকা, তাই "রপ্তানিতে ঢাকা"
         * পরীক্ষাটা পাশ করত — রপ্তানি নামের জিনিসটা না থাকলেও।
         */
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('content-type'),
            'রপ্তানি একটা ফাইল দেয়নি — পাতাটাই ফিরেছে, আর তখন নিচের দাবিগুলো কিছুই প্রমাণ করে না।');

        $csv = $response->getContent();

        $this->assertStringNotContainsString(__('sales::field.gross_profit'), $csv);
        $this->assertStringNotContainsString(__('sales::field.margin_percent'), $csv);
        $this->assertStringNotContainsString('600', $csv, 'ক্রয়মূল্যের অঙ্কটা CSV-তে রয়ে গেছে।');

        // ফাইলটা সত্যিই বেরিয়েছে — নাহলে উপরের দাবিগুলো খালি স্ট্রিং
        // দেখেও পাশ করত, আর পরীক্ষাটা কিছুই প্রমাণ করত না
        $this->assertStringContainsString(__('sales::field.revenue'), $csv);
    }
}
