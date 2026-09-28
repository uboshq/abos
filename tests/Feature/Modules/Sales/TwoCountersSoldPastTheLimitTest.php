<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\CreditExposure;
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Sales\Services\DirectSaleService;
use App\Modules\Sales\Services\SalesInvoiceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

/**
 * দুই কাউন্টার, একই গ্রাহক, একই মুহূর্ত — আর সীমাটা পার হয়ে যেত।
 *
 * ── ⛔ যা খোলা ছিল ────────────────────────────────────────────────────
 * বিল আর চালানের দেয়াল ([[CreditExposure::assertRoom()]]) চলত খাতায়
 * লেখার লেনদেনের **বাইরে**, আর গ্রাহকের সারিতে কোনো তালা ছিল না।
 * ১,০০০ সীমার গ্রাহককে দুই কাউন্টার একসাথে ৬০০ করে দিলে দুইজনেই
 * "৪০০ জায়গা আছে… না, ১,০০০ আছে" দেখত — কারণ কেউ তখনো লেখেনি — আর
 * দুইজনেই খাতায় লিখত। ⓘ মোট ১,২০০: মালিকের "limit mane limit 100%"
 * ভাঙল, অথচ কোনো পরীক্ষা লাল হলো না, কারণ প্রতিটা কাগজ একা একা
 * সীমার ভিতরেই ছিল।
 *
 * ── ⓘ কেন RefreshDatabase নয় ─────────────────────────────────────────
 * ওটা পুরো পরীক্ষাকে একটা লেনদেনে মুড়ে রাখে — দ্বিতীয় সংযোগ সিডের
 * সারিগুলো দেখতেই পায় না, আর তখন "দৌড়" বলে কিছু ঘটে না। ⭐ তাই
 * [[TwoCountersRaceTest]]-এর ছাঁচ: সত্যিকারের দুইটা সংযোগ, কমিট হওয়া
 * সারি, আর শেষে নিজের আবর্জনা নিজে তোলা।
 *
 * ── ⓘ দৌড়টা কীভাবে সাজানো ────────────────────────────────────────────
 * PHP এক সুতোয় চলে। ⓘ দ্বিতীয় কাউন্টারের কাজ শুরু হয়, তার আগের দেয়াল
 * (তালা ছাড়া) পার হয়, আর খাতায় লেখার লেনদেন **শুরু হওয়ার ঠিক মুহূর্তে**
 * ([[TransactionBeginning]]) প্রথম কাউন্টার আরেক সংযোগে পুরো কাজটা করে
 * কমিট করে ফেলে। ⚠️ অর্থাৎ দ্বিতীয়জনের আগের দেখা তখন বাসি — ঠিক
 * দুই কাউন্টারের দৌড়ে যা ঘটে।
 */
final class TwoCountersSoldPastTheLimitTest extends TestCase
{
    use DatabaseMigrations;

    private const SECOND = 'counter_two';

    private Customer $customer;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        app(StandardChart::class)->install();

        $this->actingAs(User::query()->where('email', 'sales@abos.test')->firstOrFail());

        $this->customer = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->orderBy('id')->firstOrFail();

        app(SettingsService::class)->set('customer.credit_limit_enabled', true);
        app(SettingsService::class)->set('customer.zero_limit_blocks', false);

        $this->customer->forceFill(['credit_limit' => '1000'])->save();
        $this->customer->refresh();

        /*
         * দ্বিতীয় কাউন্টার — আলাদা সংযোগ, একই ডাটাবেজ (abos_test_…)।
         *
         * ⓘ অপেক্ষা এক সেকেন্ড: তালা আছে কি না জানতে সেটুকুই যথেষ্ট; না
         * থাকলে পরীক্ষাটা পঞ্চাশ সেকেন্ড ঝুলে থেকে তারপর ব্যর্থ হত।
         */
        config(['database.connections.'.self::SECOND => config('database.connections.'.DB::getDefaultConnection())]);
        DB::purge(self::SECOND);
        DB::connection(self::SECOND)->statement('SET SESSION innodb_lock_wait_timeout = 1');
    }

    /**
     * ⚠️ কমিট হওয়া সারি তুলে নেওয়া — [[TwoCountersRaceTest::tearDown()]]-এর কারণেই।
     *
     * ⛔ না তুললে DemoSeeder-এর দুই কোম্পানি ডাটাবেজে পড়ে থাকত, আর পরের যে
     * ক্লাস `DemoSeeder` সিড করে সে `companies_code_unique`-এ ধাক্কা খেত —
     * কেবল ক্রমের উপর নির্ভর করে, এলোমেলোভাবে।
     */
    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        foreach (DB::select('SHOW TABLES') as $row) {
            $table = array_values((array) $row)[0];

            if ($table === 'migrations') {
                continue;
            }

            DB::table($table)->truncate();
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        DB::purge(self::SECOND);

        parent::tearDown();
    }

    // ── ⓵ তালাটা সত্যিই পড়ে ─────────────────────────────────────────────

    /**
     * ⭐ লেনদেনের ভিতরের দেয়াল গ্রাহকের সারিতে তালা দেয়, আর কমিট পর্যন্ত ধরে রাখে।
     *
     * ⚠️ পূর্বশর্ত আগে: সারিটা দ্বিতীয় সংযোগ দেখতে পায়। ⛔ না পেলে নিচের
     * "আটকাল না" আর "আটকাল" দুইটাই অর্থহীন — ফাঁকা সারিতে তালা পড়ে না।
     */
    public function test_the_locked_check_holds_the_customer_row_until_the_counter_commits(): void
    {
        $this->assertSame(
            (int) $this->customer->id,
            (int) DB::connection(self::SECOND)->table('customers')->where('id', $this->customer->id)->value('id'),
            'দৃশ্যটাই বানানো যায়নি — দ্বিতীয় সংযোগ গ্রাহকের সারিটা দেখে না।',
        );

        DB::beginTransaction();

        try {
            app(CreditExposure::class)->assertRoomLocked($this->customer, '100');

            try {
                $this->grabTheCustomerFromTheSecondCounter();

                $this->fail('⛔ দ্বিতীয় কাউন্টার গ্রাহকের সারিতে দিব্যি হাত দিল — দেয়ালটা কোনো তালা দেয়নি।');
            } catch (QueryException $e) {
                $this->assertSame('HY000', (string) $e->getCode(), $e->getMessage());
                $this->assertSame(1205, (int) ($e->errorInfo[1] ?? 0),
                    "⛔ অপেক্ষার সময় পার হওয়ার ত্রুটি নয় — অন্য কোনো কারণে থেমেছে: {$e->getMessage()}");
            }
        } finally {
            DB::commit();
        }

        // ⭐ কমিটের পরে তালা খোলে — একই কথা, এবার চলে যায়
        $this->assertSame((int) $this->customer->id, $this->grabTheCustomerFromTheSecondCounter(),
            '⛔ কমিটের পরেও সারিটা আটকে আছে — তালাটা লেনদেনের সাথে ছাড়ছে না।');
    }

    // ── ⓶ দ্বিতীয় কাউন্টার ফিরে যায় ────────────────────────────────────

    /**
     * ⛔ চালানের দরজা: দুইটা ৬০০ টাকার ডিও, ১,০০০ সীমা — দ্বিতীয়টা ফিরে যায়।
     *
     * ⓘ দ্বিতীয় চালানের আগের দেয়াল পার হয় (তখন প্রথমটা খসড়া, সীমা
     * আটকায়নি)। তার লেখার লেনদেন শুরু হতেই প্রথম কাউন্টার আরেক সংযোগে
     * প্রথম চালান নিশ্চিত করে কমিট করে। ⚠️ তালাসহ দেয়াল না থাকলে দ্বিতীয়টাও
     * নিশ্চিত হত — মোট ১,২০০।
     */
    public function test_the_second_challan_is_refused_when_the_first_posted_meanwhile(): void
    {
        $first = $this->challan(6);
        $second = $this->challan(6);

        $this->whileTheSecondCounterPosts(function () use ($first): void {
            app(DeliveryChallanService::class)->confirm(
                DeliveryChallan::query()->with(['lines.product', 'lines.orderLine', 'warehouse'])->findOrFail($first->id),
            );
        });

        $field = $this->refusedOn(fn () => app(DeliveryChallanService::class)->confirm($second->fresh(['lines'])));

        $this->assertSame('customer_id', $field, "⛔ দ্বিতীয় চালান সীমার দেয়ালে থামেনি, থেমেছে `{$field}`-এ।");
        $this->assertSame(DocumentStatus::DRAFT, $second->fresh()->status, '⛔ দেয়ালে থেমেও দ্বিতীয় চালান পাকা হয়ে গেছে।');
        $this->assertNotSame(DocumentStatus::DRAFT, $first->fresh()->status,
            'দৃশ্যটাই বানানো যায়নি — প্রথম কাউন্টারের চালান নিশ্চিত হয়নি।');
        $this->assertWithinTheLimit();
    }

    /**
     * ⛔ বিলের দরজা: আগের দেয়ালের পরে সীমা বদলালে তালাসহ দেয়াল তাজা সীমা দেখে।
     *
     * ⓘ বিলের পথে "দুই কাউন্টার" এভাবে সাজানো যায় না: খসড়া বিল নিজেই
     * সীমা আটকে রাখে, তাই অন্য কাউন্টার এই খসড়াটা দেখে আগেই থামে (প্রথম
     * লাল রানে ঠিক এটাই ঘটেছিল — থেমেছিল প্রথম কাউন্টার, দ্বিতীয় নয়)।
     * ⚠️ বাসি ছবির আসল ঝুঁকি এখানে: আগের দেয়ালের পরে আরেক সেশনে সীমা
     * ১,০০০ থেকে ৫০০ হলো আর কমিট হলো। ⛔ হাতের `$customer` তখনো ১,০০০
     * বলে — তাজা সারি না পড়লে ৬০০ টাকার বিল ৫০০ সীমায় খাতায় বসত।
     */
    public function test_the_bill_is_refused_when_the_limit_fell_meanwhile(): void
    {
        $bill = $this->bill(6);

        $this->whileTheSecondCounterPosts(function (): void {
            DB::table('customers')->where('id', $this->customer->id)->update(['credit_limit' => '500']);
        });

        $field = $this->refusedOn(fn () => app(SalesInvoiceService::class)->confirm($bill->fresh()));

        $this->assertSame('customer_id', $field, "⛔ বিলটা সীমার দেয়ালে থামেনি, থেমেছে `{$field}`-এ।");
        $this->assertSame(DocumentStatus::DRAFT, $bill->fresh()->status, '⛔ দেয়ালে থেমেও বিলটা পাকা হয়ে গেছে।');
        $this->assertSame(0, bccomp('500', (string) Customer::query()->findOrFail($this->customer->id)->credit_limit, 4),
            'দৃশ্যটাই বানানো যায়নি — আরেক সেশনের সীমা বদল কমিট হয়নি।');

        // ⓘ খসড়াটা নিজে সীমা আটকে রাখে (সেটা নিয়মই), তাই মাপা হয় কেবল খাতা
        $this->assertSame(0, bccomp('0', Customer::query()->findOrFail($this->customer->id)->outstanding(), 4),
            '⛔ ফিরিয়ে দেওয়া বিলের টাকা খাতায় বসে গেছে।');
    }

    // ── ⓷ লেনদেনের বাইরে ডাকা যায় না ─────────────────────────────────

    /**
     * ⛔ লেনদেন ছাড়া তালাসহ দেয়াল ডাকলে ব্যতিক্রম।
     *
     * ⓘ লেনদেনের বাইরে `FOR UPDATE`-এর তালা কোয়েরি শেষ হতেই খুলে যায় —
     * পাহারাটা দেখতে থাকত, অথচ কিছুই আটকাত না।
     */
    public function test_the_locked_check_refuses_to_run_outside_a_transaction(): void
    {
        $this->assertSame(0, DB::transactionLevel(), 'দৃশ্যটাই বানানো যায়নি — পরীক্ষা নিজেই লেনদেনের ভিতরে।');

        $this->expectException(LogicException::class);

        app(CreditExposure::class)->assertRoomLocked($this->customer, '100');
    }

    // ── ⓸ ধরে রাখা বিক্রয় দুইবার গোনা হয় না ───────────────────────────

    /**
     * ⭐ রাখা খসড়া কাউন্টারে ফিরে এসে পাকা হয় — ৮০০ টাকা, ১,০০০ সীমা।
     *
     * ⚠️ খসড়া বিল (৮০০) আর তার চালান (৮০০) একই টাকা। ⛔ লেনদেনের ভিতরের
     * দেয়াল আগের দেয়ালের মতো একই ছাড় (`exceptChallanId`/`exceptInvoiceId`)
     * না পেলে ১,৬০০ গুনত, আর সীমার ভিতরের বিক্রয়ও ফিরে যেত।
     */
    public function test_a_resumed_draft_is_not_counted_twice_by_the_locked_check(): void
    {
        $held = $this->sell(8, ['save_as_draft' => '1']);

        $this->assertSame(DocumentStatus::DRAFT, $held['invoice']->status, 'দৃশ্যটাই বানানো যায়নি — খসড়া রাখা হয়নি।');

        $done = $this->sell(8, ['resume_invoice_id' => $held['invoice']->id]);

        $this->assertSame(DocumentStatus::CONFIRMED, $done['invoice']->status);
        $this->assertSame((int) $held['invoice']->id, (int) $done['invoice']->id, '⛔ খসড়াটা নয়, নতুন একটা বিল পাকা হয়েছে।');
        $this->assertWithinTheLimit();
    }

    /** ⭐ "শেষ করুন" দরজাও — খসড়া বিল আর তার চালান একবারই গোনা। */
    public function test_finishing_a_held_sale_is_not_counted_twice_by_the_locked_check(): void
    {
        $held = $this->sell(8, ['save_as_draft' => '1']);

        $invoice = app(DirectSaleService::class)->finishHeld($held['invoice']);

        $this->assertSame(DocumentStatus::CONFIRMED, $invoice->status);
        $this->assertWithinTheLimit();
    }

    // ── প্রস্তুতি ────────────────────────────────────────────────────────

    /**
     * দ্বিতীয় কাউন্টারের লেখার লেনদেন শুরু হওয়ার মুহূর্তে প্রথম কাউন্টারের কাজ — আরেক সংযোগে, কমিটসহ।
     *
     * ⚠️ ডিফল্ট সংযোগ কিছুক্ষণের জন্য দ্বিতীয়টায় সরে, যাতে সেবাগুলো
     * (তারা `DB::` আর মডেলের ডিফল্ট সংযোগে চলে) সত্যিই আরেক সেশনে লেখে।
     * ⓘ একবারই চলে — প্রথম কাউন্টারের নিজের লেনদেন এটাকে আর জাগায় না।
     */
    private function whileTheSecondCounterPosts(callable $firstCounter): void
    {
        $main = DB::getDefaultConnection();
        $armed = true;

        Event::listen(TransactionBeginning::class, function (TransactionBeginning $event) use (&$armed, $main, $firstCounter): void {
            if (! $armed || $event->connectionName !== $main) {
                return;
            }

            $armed = false;

            DB::setDefaultConnection(self::SECOND);

            try {
                $firstCounter();
            } finally {
                DB::setDefaultConnection($main);
            }
        });
    }

    /** দ্বিতীয় সংযোগ থেকে গ্রাহকের সারিতে `FOR UPDATE` — পেলে আইডি। */
    private function grabTheCustomerFromTheSecondCounter(): int
    {
        return (int) DB::connection(self::SECOND)->transaction(fn () => DB::connection(self::SECOND)
            ->table('customers')
            ->where('id', $this->customer->id)
            ->lockForUpdate()
            ->value('id'));
    }

    /** ⛔ শেষ কথা: খাতার বকেয়া + আটকে থাকা টাকা সীমার ভিতরে। */
    private function assertWithinTheLimit(): void
    {
        $customer = Customer::query()->findOrFail($this->customer->id);
        $used = bcadd($customer->outstanding(), app(CreditExposure::class)->pending($customer), 4);

        $this->assertLessThanOrEqual(0, bccomp($used, (string) $customer->credit_limit, 4),
            "⛔ সীমা পার: ব্যবহৃত {$used}, সীমা {$customer->credit_limit}।");
    }

    /** অফিসের একটা খসড়া চালান — `qty` × ১০০ টাকা। */
    private function challan(int $qty): DeliveryChallan
    {
        return app(DeliveryChallanService::class)->create(
            [
                'customer_id' => $this->customer->id,
                'warehouse_id' => $this->warehouse->id,
                'trx_date' => now()->toDateString(),
            ],
            [['product_id' => $this->product->id, 'delivered_qty' => (string) $qty, 'rate' => '100']],
        );
    }

    /** কাউন্টারের একটা খসড়া বিল, চালান ছাড়া — `qty` × ১০০ টাকা। */
    private function bill(int $qty): SalesInvoice
    {
        return app(SalesInvoiceService::class)->create(
            [
                'customer_id' => $this->customer->id,
                'warehouse_id' => $this->warehouse->id,
                'trx_date' => now()->toDateString(),
            ],
            [['product_id' => $this->product->id, 'qty' => (string) $qty, 'rate' => '100']],
        );
    }

    /**
     * কাউন্টারের বিক্রয় — `qty` × ১০০ টাকা, বাকিতে।
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function sell(int $qty, array $extra = []): array
    {
        return app(DirectSaleService::class)->complete(
            array_merge([
                'customer_id' => $this->customer->id,
                'warehouse_id' => $this->warehouse->id,
                'deposit' => '0',
            ], $extra),
            [['product_id' => $this->product->id, 'qty' => (string) $qty, 'rate' => '100']],
        );
    }

    /** ব্যর্থ হলে কোন ঘরের বার্তা — কোনো ব্যতিক্রম না হলে দাবিটা ব্যর্থ। */
    private function refusedOn(callable $work): string
    {
        try {
            $work();
        } catch (ValidationException $e) {
            return (string) array_key_first($e->errors());
        }

        $this->fail('⛔ কিছুই আটকায়নি — দুই কাউন্টার মিলে সীমা পার করে ফেলল।');
    }
}
