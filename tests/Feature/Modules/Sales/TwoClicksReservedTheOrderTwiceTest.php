<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Services\PermissionSyncer;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\SalesOrderService;
use Database\Seeders\DemoSeeder;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * দুই ক্লিকে একটা বিক্রয় আদেশ মাল দুইবার ধরত, আর বাতিলে দুইবার ছাড়ত — চূড়ান্ত অডিট (abos-8f-এর পড়া), ৩০ সেপ্টেম্বর ২০২৬।
 *
 * ── ⛔ আগে ([[SalesOrderService::confirm()]], [[SalesOrderService::cancel()]]) ──
 * "খসড়া কি না" / "বাতিল কি না" দেখা হত হাতের পুরনো মডেলে, লেনদেনের বাইরে, তালা ছাড়া। দুইটা অনুরোধ একসাথে
 * এলে দুইটাই পার হত: নিশ্চিতে প্রতিটা সারির মাল দুইবার ধরা (Reserved দ্বিগুণ, বিক্রয়যোগ্য মাল মিথ্যা কম),
 * বাতিলে দুইবার ছাড়া (Reserved ঋণাত্মক, বিক্রয়যোগ্য মাল মিথ্যা বেশি)।
 *
 * ── ⭐ এখন ─────────────────────────────────────────────────────────────
 * লেনদেনের প্রথম কাজ আদেশের সারিতে তালা, তারপর অবস্থা আবার পড়া ([[DepositClaimService::lockPending()]]-এর
 * ছাঁচ)। দৌড় সাজানো [[TwoClicksPostedTheSamePaperTwiceTest]]-এর মতো; দুই ক্লিকই মালিকের (super_admin) —
 * প্রথমটা পাকা হওয়াও মাপা হয়।
 */
final class TwoClicksReservedTheOrderTwiceTest extends TestCase
{
    use DatabaseMigrations;

    private const SECOND = 'second_click';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        app(StandardChart::class)->install();

        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->assertTrue($owner->hasRole(PermissionSyncer::SUPER_ADMIN_ROLE), 'দৃশ্যটাই বানানো যায়নি — মালিক super_admin নন।');
        $this->actingAs($owner);

        config(['database.connections.'.self::SECOND => config('database.connections.'.DB::getDefaultConnection())]);
        DB::purge(self::SECOND);
        DB::connection(self::SECOND)->statement('SET SESSION innodb_lock_wait_timeout = 5');
    }

    /** ⚠️ কমিট হওয়া সারি তুলে নেওয়া — [[TwoCountersSoldPastTheLimitTest::tearDown()]]-এর কারণেই। */
    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        foreach (DB::select('SHOW TABLES') as $row) {
            $table = array_values((array) $row)[0];

            if ($table !== 'migrations') {
                DB::table($table)->truncate();
            }
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=1');
        DB::purge(self::SECOND);

        parent::tearDown();
    }

    /** ⛔ একই আদেশ দুইবার নিশ্চিত — মাল একবারই ধরা হয়, দ্বিতীয়টা `status`-এ ফেরে। */
    public function test_one_order_confirmed_twice_reserves_once(): void
    {
        $order = $this->draftOrder();
        $stale = SalesOrder::query()->findOrFail($order->id);

        $this->whileTheOtherClickPosts(fn () => app(SalesOrderService::class)->confirm(SalesOrder::query()->findOrFail($order->id)));

        $this->assertSame('status', $this->refusedOn(fn () => app(SalesOrderService::class)->confirm($stale)));
        $this->assertSame(DocumentStatus::CONFIRMED, $order->fresh()->status, 'দৃশ্যটাই বানানো যায়নি — প্রথম ক্লিকের আদেশ পাকা হয়নি।');
        // ⓘ সারি নয়, পরিমাণ — একটা সারির মাল দুই লটে থাকলে একবারেই দুইটা চলাচল হয়
        $this->assertSame(0, bccomp($this->reserved(SalesOrder::STOCK_SOURCE, $order->id), '2', 4), '⛔ একই আদেশের মাল দুইবার ধরা হয়েছে।');
    }

    /** ⛔ একই আদেশ দুইবার বাতিল — ধরা মাল একবারই ছাড়ে, Reserved ঋণাত্মক হয় না। */
    public function test_one_order_cancelled_twice_releases_once(): void
    {
        $order = app(SalesOrderService::class)->confirm($this->draftOrder());
        $stale = SalesOrder::query()->findOrFail($order->id);

        $this->whileTheOtherClickPosts(fn () => app(SalesOrderService::class)->cancel(SalesOrder::query()->findOrFail($order->id), 'first click'));

        $this->assertSame('status', $this->refusedOn(fn () => app(SalesOrderService::class)->cancel($stale, 'second click')));
        $this->assertSame(DocumentStatus::CANCELLED, $order->fresh()->status, 'দৃশ্যটাই বানানো যায়নি — প্রথম ক্লিকের বাতিল হয়নি।');
        // ⓘ ছাড়া হয় যতটুকু ধরা আছে — এই আদেশের নিজের ধরা (নিশ্চিতের সময়) যতটুকু, ঠিক ততটুকু, একবার
        $held = $this->reserved(SalesOrder::STOCK_SOURCE, $order->id);
        $released = $this->reserved(SalesOrder::STOCK_SOURCE.':cancel', $order->id);
        $this->assertSame(0, bccomp(bcadd($held, $released, 4), '0', 4), "⛔ একই আদেশের ধরা মাল দুইবার ছাড়া হয়েছে: ধরা {$held}, ছাড়া {$released}।");
    }

    // ── প্রস্তুতি ────────────────────────────────────────────────────────

    /** দ্বিতীয় ক্লিকের লেখার লেনদেন শুরু হওয়ার মুহূর্তে প্রথম ক্লিকের কাজ — আরেক সংযোগে, কমিটসহ, একবার। */
    private function whileTheOtherClickPosts(callable $firstClick): void
    {
        $main = DB::getDefaultConnection();
        $armed = true;

        Event::listen(TransactionBeginning::class, function (TransactionBeginning $event) use (&$armed, $main, $firstClick): void {
            if (! $armed || $event->connectionName !== $main) {
                return;
            }

            $armed = false;
            DB::setDefaultConnection(self::SECOND);

            try {
                $firstClick();
            } finally {
                DB::setDefaultConnection($main);
            }
        });
    }

    /** এই উৎসের মোট ধরা (reserved) — এই পণ্যের; আদেশে ২ একক, ভিত্তি-এককেও ২ (বিস্কুট পিসে) */
    private function reserved(string $sourceType, int $sourceId): string
    {
        // ⓘ পণ্য ধরেও — ডেমোর আগের সারি একই নম্বরের উৎসে অন্য পণ্যে থাকতে পারে
        return bcadd((string) StockMovement::query()->where('source_type', $sourceType)->where('source_id', $sourceId)
            ->where('product_id', $this->biscuit())->sum('reserved_change'), '0', 4);
    }

    private function biscuit(): int
    {
        return (int) Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->value('id');
    }

    private function draftOrder(): SalesOrder
    {
        return app(SalesOrderService::class)->create([
            'customer_id' => Customer::query()->where('name_en', 'Rahim Traders')->value('id'),
            'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
            'trx_date' => now()->toDateString(),
        ], [['product_id' => $this->biscuit(), 'ordered_qty' => '2', 'rate' => '10']]);
    }

    /** ব্যর্থ হলে কোন ঘরের বার্তা — কিছুই না আটকালে দাবিটা ব্যর্থ। */
    private function refusedOn(callable $work): string
    {
        try {
            $work();
        } catch (ValidationException $e) {
            return (string) array_key_first($e->errors());
        }

        $this->fail('⛔ কিছুই আটকায়নি — একই আদেশ দুইবার খাতায় বসল।');
    }
}
