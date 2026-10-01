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
use App\Modules\MasterData\Models\ReasonCode;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Models\SalesReturnLine;
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Sales\Services\DirectSaleService;
use App\Modules\Sales\Services\SalesReturnService;
use Database\Seeders\DemoSeeder;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * দুই ক্লিক (বা দুই জানালা), একই কাগজ, একই মুহূর্ত — আর কাগজটা দুইবার খাতায় বসত।
 *
 * ── ⛔ যা খোলা ছিল (চূড়ান্ত অডিট ⛔৩ ও ⛔৪, ৩০ সেপ্টেম্বর ২০২৬) ─────────
 * ফেরত আর চালানের নিশ্চিত/বাতিল "এখনো খসড়া কি না" আর "বিলে কত বেচা হয়েছিল, কত ফিরেছে" দেখত
 * লেনদেনের **বাইরে**, কাগজের সারিতে তালা ছাড়া। দুইটা অনুরোধ একসাথে এলে দুইটাই পুরনো ছবি দেখত:
 * - একই চালান দুইবার নিশ্চিত → মাল দুইবার বেরোয়;
 * - একই চালান বা ফেরত দুইবার বাতিল → মাল দুইবার ফেরে;
 * - একই বিলের সারিতে দুইটা ফেরত (৬ + ৬, বেচা ১০) → দুইটাই "৬ জায়গা আছে… না, ১০ আছে" দেখে পাকা।
 *
 * ── ⭐ এখন ([[DepositClaimService::lockPending()]]-এর ছাঁচ) ─────────────
 * লেনদেনের প্রথম কাজ কাগজের সারিতে তালা, তারপর অবস্থা আবার পড়া; ফেরতে বিলের সারিতেও তালা, আর
 * "কত ফিরেছে" তালার ভিতরে আবার মাপা।
 *
 * ── ⓘ দৌড়টা কীভাবে সাজানো ([[TwoCountersSoldPastTheLimitTest]]-এর ছাঁচ) ──
 * দ্বিতীয় অনুরোধের লেখার লেনদেন **শুরু হওয়ার ঠিক মুহূর্তে** ([[TransactionBeginning]]) প্রথম অনুরোধ
 * আরেক সংযোগে পুরো কাজটা করে কমিট করে। দ্বিতীয়জনের হাতের ছবি তখন বাসি — দুই ক্লিকে ঠিক যা ঘটে।
 * ⭐ দুইজনই মালিক (super_admin) — প্রথমটা পাকা হওয়াও মাপা হয়: তালা কাউকে আটকায় না, কেবল দ্বিতীয়বারকে।
 */
final class TwoClicksPostedTheSamePaperTwiceTest extends TestCase
{
    use DatabaseMigrations;

    private const SECOND = 'second_click';

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

        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->assertTrue($owner->hasRole(PermissionSyncer::SUPER_ADMIN_ROLE), 'দৃশ্যটাই বানানো যায়নি — মালিক super_admin নন।');
        $this->actingAs($owner);

        $this->customer = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->orderBy('id')->firstOrFail();

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

    // ── ⛔৪ চালান ────────────────────────────────────────────────────────

    /** ⛔ একই চালান দুইবার নিশ্চিত — মাল একবারই বেরোয়, দ্বিতীয়টা `status`-এ ফেরে। */
    public function test_one_challan_confirmed_twice_sends_the_goods_once(): void
    {
        $challan = $this->challan(6);
        $stale = DeliveryChallan::query()->findOrFail($challan->id);

        $this->whileTheOtherClickPosts(fn () => app(DeliveryChallanService::class)->confirm(
            DeliveryChallan::query()->findOrFail($challan->id),
        ));

        $this->assertSame('status', $this->refusedOn(fn () => app(DeliveryChallanService::class)->confirm($stale)));
        $this->assertSame(DocumentStatus::CONFIRMED, $challan->fresh()->status, 'দৃশ্যটাই বানানো যায়নি — প্রথম ক্লিকের (super_admin) চালান পাকা হয়নি।');
        $this->assertSame(1, $this->movements(DeliveryChallan::STOCK_SOURCE, $challan->id), '⛔ একই চালানের মাল দুইবার বেরিয়েছে।');
    }

    /** ⛔ একই চালান দুইবার বাতিল — মাল একবারই ফেরে। */
    public function test_one_challan_cancelled_twice_brings_the_goods_back_once(): void
    {
        $challan = app(DeliveryChallanService::class)->confirm($this->challan(6));
        $stale = DeliveryChallan::query()->findOrFail($challan->id);

        $this->whileTheOtherClickPosts(fn () => app(DeliveryChallanService::class)->cancel(
            DeliveryChallan::query()->findOrFail($challan->id), 'first click',
        ));

        $this->assertSame('status', $this->refusedOn(fn () => app(DeliveryChallanService::class)->cancel($stale, 'second click')));
        $this->assertSame(DocumentStatus::CANCELLED, $challan->fresh()->status, 'দৃশ্যটাই বানানো যায়নি — প্রথম ক্লিকের বাতিল হয়নি।');
        $this->assertSame(1, $this->movements(DeliveryChallan::STOCK_SOURCE.':cancel', $challan->id), '⛔ একই চালানের মাল দুইবার ফিরেছে।');
    }

    // ── ⛔৩ ফেরত ─────────────────────────────────────────────────────────

    /** ⛔ বেচা ১০, দুইটা ফেরত ৬ + ৬ একসাথে — দ্বিতীয়টা `lines`-এ ফেরে, মোট ফেরত বেচার ভিতরে। */
    public function test_two_returns_on_one_bill_line_cannot_take_back_more_than_was_sold(): void
    {
        $bill = $this->sold(10);
        $first = $this->returnOf($bill, 6);
        $second = $this->returnOf($bill, 6);

        $this->whileTheOtherClickPosts(fn () => app(SalesReturnService::class)->confirm(
            SalesReturn::query()->findOrFail($first->id),
        ));

        $this->assertSame('lines', $this->refusedOn(fn () => app(SalesReturnService::class)->confirm(
            SalesReturn::query()->findOrFail($second->id),
        )));
        $this->assertSame(DocumentStatus::CONFIRMED, $first->fresh()->status, 'দৃশ্যটাই বানানো যায়নি — প্রথম ফেরত পাকা হয়নি।');
        $this->assertSame(DocumentStatus::DRAFT, $second->fresh()->status, '⛔ থেমেও দ্বিতীয় ফেরত পাকা হয়ে গেছে।');

        $back = SalesReturnLine::query()->whereHas('return', fn ($q) => $q->posted())
            ->where('sales_invoice_line_id', $bill->lines->first()->id)->sum('qty');
        $this->assertLessThanOrEqual(0, bccomp((string) $back, '10', 4), "⛔ বেচা ১০, ফেরত পাকা {$back}।");
    }

    /** ⛔ একই ফেরত দুইবার নিশ্চিত — মাল একবারই তাকে ফেরে। */
    public function test_one_return_confirmed_twice_takes_the_goods_back_once(): void
    {
        $return = $this->returnOf($this->sold(10), 3);
        $stale = SalesReturn::query()->findOrFail($return->id);

        $this->whileTheOtherClickPosts(fn () => app(SalesReturnService::class)->confirm(
            SalesReturn::query()->findOrFail($return->id),
        ));

        $this->assertSame('status', $this->refusedOn(fn () => app(SalesReturnService::class)->confirm($stale)));
        $this->assertSame(DocumentStatus::CONFIRMED, $return->fresh()->status, 'দৃশ্যটাই বানানো যায়নি — প্রথম ক্লিকের ফেরত পাকা হয়নি।');
        $this->assertSame(1, $this->movements(SalesReturn::STOCK_SOURCE, $return->id), '⛔ একই ফেরতের মাল দুইবার তাকে উঠেছে।');
    }

    /** ⛔ একই ফেরত দুইবার বাতিল — মাল একবারই আবার বেরোয়। */
    public function test_one_return_cancelled_twice_undoes_it_once(): void
    {
        $return = app(SalesReturnService::class)->confirm($this->returnOf($this->sold(10), 3));
        $stale = SalesReturn::query()->findOrFail($return->id);

        $this->assertSame(1, $this->movements(SalesReturn::STOCK_SOURCE, $return->id), 'দৃশ্যটাই বানানো যায়নি — ফেরতে মাল একবার ওঠেনি।');

        $this->whileTheOtherClickPosts(fn () => app(SalesReturnService::class)->cancel(
            SalesReturn::query()->findOrFail($return->id), 'first click',
        ));

        $this->assertSame('status', $this->refusedOn(fn () => app(SalesReturnService::class)->cancel($stale, 'second click')));
        $this->assertSame(DocumentStatus::CANCELLED, $return->fresh()->status, 'দৃশ্যটাই বানানো যায়নি — প্রথম ক্লিকের বাতিল হয়নি।');

        // ⓘ বাতিলের উল্টো সারিও একই উৎসে — ফেরত ১ + একবার বাতিল ১ = ২; দুইবার বাতিলে ৩
        $this->assertSame(2, $this->movements(SalesReturn::STOCK_SOURCE, $return->id), '⛔ একই ফেরতের বাতিল দুইবার মাল নাড়িয়েছে।');
    }

    // ── প্রস্তুতি ────────────────────────────────────────────────────────

    /**
     * দ্বিতীয় ক্লিকের লেখার লেনদেন শুরু হওয়ার মুহূর্তে প্রথম ক্লিকের কাজ — আরেক সংযোগে, কমিটসহ।
     * ⓘ একবারই চলে; ডিফল্ট সংযোগ কিছুক্ষণের জন্য দ্বিতীয়টায় সরে, যাতে সেবাগুলো সত্যিই আরেক সেশনে লেখে।
     */
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

    private function movements(string $sourceType, int $sourceId): int
    {
        return StockMovement::query()->where('source_type', $sourceType)->where('source_id', $sourceId)->count();
    }

    private function challan(int $qty): DeliveryChallan
    {
        return app(DeliveryChallanService::class)->create([
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
            'own_transport' => true,
        ], [['product_id' => $this->product->id, 'delivered_qty' => (string) $qty, 'rate' => '100']]);
    }

    private function sold(int $qty): SalesInvoice
    {
        return app(DirectSaleService::class)->complete(
            ['customer_id' => $this->customer->id, 'warehouse_id' => $this->warehouse->id, 'own_transport' => '1'],
            [['product_id' => $this->product->id, 'qty' => (string) $qty, 'rate' => '100', 'free_qty' => '0']],
        )['invoice']->fresh(['lines']);
    }

    private function returnOf(SalesInvoice $bill, int $qty): SalesReturn
    {
        return app(SalesReturnService::class)->create([
            'customer_id' => $bill->customer_id,
            'warehouse_id' => $this->warehouse->id,
            'sales_invoice_id' => $bill->id,
            'reason_code_id' => ReasonCode::query()->inContext(ReasonCode::SALES_RETURN)->value('id'),
            'trx_date' => now()->toDateString(),
        ], [[
            'product_id' => $this->product->id,
            'sales_invoice_line_id' => $bill->lines->first()->id,
            'qty' => (string) $qty,
        ]]);
    }

    /** ব্যর্থ হলে কোন ঘরের বার্তা — কিছুই না আটকালে দাবিটা ব্যর্থ। */
    private function refusedOn(callable $work): string
    {
        try {
            $work();
        } catch (ValidationException $e) {
            return (string) array_key_first($e->errors());
        }

        $this->fail('⛔ কিছুই আটকায়নি — একই কাগজ দুইবার খাতায় বসল।');
    }
}
