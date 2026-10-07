<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Services\SalesInvoiceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * কে আসল, আর আগের চেয়ে কেমন।
 *
 * ── দুইটা প্রশ্ন যার উত্তর তালিকা দেখে পাওয়া যায় না ─────────────────
 * ১. **অবদান** — তালিকাটা বড় থেকে ছোট সাজানো, কিন্তু "কতটা বড়" বলে না।
 *    এক ক্রেতা একাই ৪০% হলে সেটা একটা ঝুঁকি: তিনি চলে গেলে ব্যবসার
 *    প্রায় অর্ধেক যায়। সংখ্যাটা পাশে না বসলে কেউ কোনোদিন গুনত না।
 * ২. **তুলনা** — "এই মাসে ৫ লাখ" ভালো না খারাপ, সেটা গত মাস বা গত
 *    বছরের একই মাস ছাড়া বলা অসম্ভব।
 *
 * দুইটাই `ReportEngine`-এ একবার, ২২টা রিপোর্টে নয়।
 */
class WhoActuallyMattersTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    /** একটা নিশ্চিত বিল, দেওয়া ক্রেতার নামে, দেওয়া তারিখে। */
    private function sell(Customer $customer, string $amount, ?string $on = null): void
    {
        $invoice = app(SalesInvoiceService::class)->create(
            [
                'customer_id' => $customer->id,
                'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
                'trx_date' => $on ?? now()->toDateString(),
            ],
            [['product_id' => Product::query()->value('id'), 'qty' => '1', 'rate' => $amount]],
        );

        app(SalesInvoiceService::class)->confirm($invoice);
    }

    /** @return array<string, array<string, mixed>> ক্রেতার নাম ধরে সারি */
    private function rows(array $filters = []): array
    {
        $result = app(ReportEngine::class)->run('sales.by_customer', [
            'from' => now()->subYears(2)->toDateString(),
            'to' => now()->addDay()->toDateString(),
            ...$filters,
        ]);

        $out = [];

        foreach ($result->rows as $row) {
            $out[$row['customer_name']] = $row;
        }

        return $out;
    }

    private function customers(int $count): array
    {
        return Customer::query()->orderBy('id')->take($count)->get()->all();
    }

    private function nameOf(Customer $customer): string
    {
        return $customer->name_bn ?: $customer->name_en;
    }

    // ── অবদান ───────────────────────────────────────────────────────

    /** প্রতিটা সারি মোটের কত অংশ। */
    public function test_each_row_says_how_much_of_the_whole_it_is(): void
    {
        [$big, $small] = $this->customers(2);

        $this->sell($big, '750');
        $this->sell($small, '250');

        $rows = $this->rows();

        $this->assertSame(0, bccomp((string) $rows[$this->nameOf($big)]['contribution_percent'], '75', 2));
        $this->assertSame(0, bccomp((string) $rows[$this->nameOf($small)]['contribution_percent'], '25', 2));
    }

    /**
     * শতাংশগুলো মোটের বিপরীতে, দেখানো সারিগুলোর বিপরীতে নয়।
     *
     * ── এটাই পুরো ব্যাপারটার কেন্দ্র ─────────────────────────────────
     * উপরের একটা সারি চাইলে যদি শতাংশ ওই একটার বিপরীতেই গোনা হত, তবে
     * সে সবসময় ১০০% দেখাত, আর "প্রথম দশজন মোটের ৬৮%" বাক্যটা কখনো
     * লেখাই যেত না। যোগফল ও শতাংশ তাই ছাঁকার **আগে** বের হয়।
     */
    public function test_the_share_is_of_the_whole_not_of_what_is_shown(): void
    {
        [$big, $small] = $this->customers(2);

        $this->sell($big, '750');
        $this->sell($small, '250');

        $rows = $this->rows(['top' => 1]);

        $this->assertCount(1, $rows, 'উপরের একটা সারি চাওয়া হয়েছিল।');
        $this->assertSame(0, bccomp((string) $rows[$this->nameOf($big)]['contribution_percent'], '75', 2),
            'শতাংশটা দেখানো সারিগুলোর বিপরীতে গোনা হয়েছে — তাহলে সবসময় ১০০% দেখাত।');
    }

    /** শূন্য বিক্রয়ে ভাগ করা হয় না — সেখানে "কত অংশ" প্রশ্নটারই উত্তর নেই। */
    public function test_a_zero_total_does_not_divide_by_zero(): void
    {
        $rows = $this->rows();

        $this->assertSame([], $rows);
    }

    // ── Top N ───────────────────────────────────────────────────────

    /** উপরের কয়টা — বড় থেকে ছোট। */
    public function test_only_the_top_rows_come_back(): void
    {
        [$a, $b, $c] = $this->customers(3);

        $this->sell($a, '100');
        $this->sell($b, '300');
        $this->sell($c, '200');

        $rows = array_keys($this->rows(['top' => 2]));

        $this->assertSame([$this->nameOf($b), $this->nameOf($c)], $rows);
    }

    /**
     * কতগুলো বাদ পড়ল, সেটা লুকানো হয় না।
     *
     * চুপচাপ দুইটা দেখালে তালিকাটা পড়ে মনে হত ওইটুকুই সব — অথচ যোগফলের
     * সারিটা তিনটারই। দুইটা সংখ্যা মেলাতে গিয়ে কেউ ভাবত হিসাব ভুল।
     */
    public function test_it_says_how_many_rows_were_left_out(): void
    {
        [$a, $b, $c] = $this->customers(3);

        foreach ([$a, $b, $c] as $i => $customer) {
            $this->sell($customer, (string) (100 * ($i + 1)));
        }

        $result = app(ReportEngine::class)->run('sales.by_customer', [
            'from' => now()->subYears(2)->toDateString(),
            'to' => now()->addDay()->toDateString(),
            'top' => 2,
        ]);

        $this->assertTrue($result->isTopOnly());
        $this->assertSame(2, $result->totalRows);
        $this->assertSame(3, $result->fullRowCount);

        // যোগফল পুরো তালিকার, দেখানো দুইটার নয়
        $this->assertSame(0, bccomp($result->totals['total'], '600', 2),
            'যোগফলটা কেবল দেখানো সারিগুলোর — তাহলে "মোট" শব্দটা মিথ্যা।');
    }

    /** `?top=100000` পুরো তালিকা এক পাতায় নামানোর পথ হতে পারে না। */
    public function test_top_is_capped(): void
    {
        [$a] = $this->customers(1);
        $this->sell($a, '100');

        $result = app(ReportEngine::class)->run('sales.by_customer', [
            'from' => now()->subYears(2)->toDateString(),
            'to' => now()->addDay()->toDateString(),
            'top' => 100000,
        ]);

        $this->assertSame(50, $result->perPage);
    }

    // ── তুলনা ───────────────────────────────────────────────────────

    /**
     * গত বছরের একই সময় — ঠিক এক বছর পিছিয়ে, দুই প্রান্তই।
     *
     * ── কেন এখানে সংখ্যা নয়, কেবল পরিসর ─────────────────────────────
     * গত বছরের তারিখে একটা বিল বসানো যায় না — ওই অর্থবছরটা খোলা নেই,
     * আর ব্যবস্থা সেটা ঠিকই আটকায় (পিছিয়ে-তারিখের তালা)। তাই এখানে
     * যাচাই হয় **কোন সময়টার সাথে তুলনা হচ্ছে**, আর সংখ্যার অঙ্কটা
     * যাচাই হয় আগের-পরিসরের পরীক্ষায়, যেখানে সত্যিকারের দুইটা বিল
     * বসানো যায়।
     */
    public function test_it_compares_with_the_same_period_last_year(): void
    {
        [$a] = $this->customers(1);

        $this->sell($a, '400');

        $result = app(ReportEngine::class)->run('sales.by_customer', [
            'from' => now()->startOfMonth()->toDateString(),
            'to' => now()->endOfMonth()->toDateString(),
            'compare' => ReportEngine::COMPARE_LAST_YEAR,
        ]);

        $this->assertSame(
            now()->startOfMonth()->subYear()->toDateString(),
            $result->comparison['from'],
        );

        $this->assertSame(
            now()->endOfMonth()->subYear()->toDateString(),
            $result->comparison['to'],
        );
    }

    /**
     * আগের পরিসর ঠিক ততদিনের, ক্যালেন্ডারের মাস নয়।
     *
     * ── কেন এটা আলাদা করে পরীক্ষা ───────────────────────────────────
     * ১–১০ তারিখের একটা পরিসর গোটা আগের মাসের সাথে তুলনা হলে দশ দিনের
     * সাথে ত্রিশ দিনের তুলনা হত, আর সংখ্যাটা সবসময় ভয়ংকর কমে যাওয়া
     * দেখাত — অথচ ব্যবসায় কিছুই কমেনি।
     */
    public function test_the_previous_period_is_the_same_number_of_days(): void
    {
        [$a] = $this->customers(1);

        $today = Carbon::today();

        $this->sell($a, '300', $today->toDateString());
        $this->sell($a, '100', $today->copy()->subDays(3)->toDateString());

        // তিন দিনের পরিসর → আগের তিন দিন
        $result = app(ReportEngine::class)->run('sales.by_customer', [
            'from' => $today->copy()->subDays(2)->toDateString(),
            'to' => $today->toDateString(),
            'compare' => ReportEngine::COMPARE_PREVIOUS,
        ]);

        $this->assertSame($today->copy()->subDays(5)->toDateString(), $result->comparison['from']);
        $this->assertSame($today->copy()->subDays(3)->toDateString(), $result->comparison['to']);
        $this->assertSame(0, bccomp((string) $result->rows[0]['previous_value'], '100', 2));

        // ১০০ → ৩০০ মানে ২০০% বেড়েছে, ৩০০% নয়
        $this->assertSame(0, bccomp((string) $result->rows[0]['change_percent'], '200', 2),
            "পরিবর্তন {$result->rows[0]['change_percent']}%, ২০০% নয়।");
    }

    /**
     * আগের সময়ে যে ছিল না, তার পরিবর্তন খালি — "১০০% বেড়েছে" নয়।
     *
     * শূন্য থেকে বাড়া শতাংশে অসীম। "নতুন" আর "দ্বিগুণ হয়েছে" এক কথা নয়,
     * আর দ্বিতীয়টা লিখলে সেটা মিথ্যা।
     */
    public function test_someone_new_is_not_reported_as_a_hundred_percent_rise(): void
    {
        [$a] = $this->customers(1);

        $this->sell($a, '400', now()->toDateString());

        $result = app(ReportEngine::class)->run('sales.by_customer', [
            'from' => now()->startOfMonth()->toDateString(),
            'to' => now()->endOfMonth()->toDateString(),
            'compare' => ReportEngine::COMPARE_LAST_YEAR,
        ]);

        $this->assertNull($result->rows[0]['change_percent']);
        $this->assertSame(0, bccomp((string) $result->rows[0]['previous_value'], '0', 2));
    }

    /** তুলনা না চাইলে কলামগুলো বসে না — পর্দা অকারণে চওড়া হয় না। */
    public function test_without_asking_there_is_no_comparison(): void
    {
        [$a] = $this->customers(1);
        $this->sell($a, '100');

        $result = app(ReportEngine::class)->run('sales.by_customer', [
            'from' => now()->subYears(2)->toDateString(),
            'to' => now()->addDay()->toDateString(),
        ]);

        $this->assertNull($result->comparison);
        $this->assertArrayNotHasKey('previous_value', $result->rows[0]);
    }

    /**
     * যে রিপোর্টে "সবচেয়ে বড়" বলে কিছু নেই, সেখানে ঘর দুইটাও নেই।
     *
     * ডে বুক সময় ধরে সাজানো, আকার ধরে নয় — ওখানে "উপরের দশটা" মানে
     * "প্রথম দশটা লেনদেন", যা কেউ চায়নি। আর জোড়া বাঁধার চাবি না থাকায়
     * তুলনাও অসম্ভব।
     */
    public function test_a_report_with_no_ranking_gets_neither(): void
    {
        $result = app(ReportEngine::class)->run('accounts.day_book', [
            'from' => now()->subYears(2)->toDateString(),
            'to' => now()->addDay()->toDateString(),
            'top' => 2,
            'compare' => ReportEngine::COMPARE_LAST_YEAR,
        ]);

        $this->assertNull($result->comparison);
        $this->assertFalse($result->isTopOnly());
    }

    // ── পর্দা ───────────────────────────────────────────────────────

    /** ঘর দুইটা পর্দায় আছে, আর কলামগুলোও বসে। */
    public function test_the_screen_offers_both_and_shows_the_columns(): void
    {
        [$a] = $this->customers(1);
        $this->sell($a, '100');

        $this->get(route('sales.report.show', [
            'slug' => 'by-customer',
            'from' => now()->startOfMonth()->toDateString(),
            'to' => now()->endOfMonth()->toDateString(),
            'compare' => ReportEngine::COMPARE_LAST_YEAR,
        ]))
            ->assertOk()
            ->assertSee(__('core.report.compare'))
            ->assertSee(__('core.report.top'))
            ->assertSee(__('core.report.contribution'))
            ->assertSee(__('core.report.change'));
    }

    /** যে রিপোর্টে সাজানোর কিছু নেই, সেখানে ঘর দুইটা পর্দাতেও নেই। */
    public function test_a_report_with_no_ranking_does_not_offer_them(): void
    {
        $this->get(route('accounts.report.show', ['slug' => 'day-book']))
            ->assertOk()
            ->assertDontSee(__('core.report.compare_none'));
    }
}
