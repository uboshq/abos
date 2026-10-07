<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Services\PermissionSyncer;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\CommissionClaim;
use App\Modules\Sales\Services\CommissionClaimService;
use App\Modules\Sales\Services\DirectSaleService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * একটা কমিশন দাবি একসাথে মেনে নেওয়া আর নাকচ — আর দাবির খাত দুইবার খালি হত (চূড়ান্ত অডিট, abos-8f-এর পড়া, ৩০ সেপ্টেম্বর ২০২৬)।
 *
 * ── ⛔ আগে ([[CommissionClaimService::settle()]], [[CommissionClaimService::reject()]]) ──
 * "অপেক্ষমাণ কি না" দেখা হত লেনদেনের বাইরে, হাতের পুরনো মডেলে। আর দুই পথের খাতার চাবি আলাদা
 * (`:settled` / `:rejected`), তাই পোস্টিং ইঞ্জিনের "এক কাগজ একবার" পাহারাও ধরত না: দুইজন একসাথে চাপলে
 * দুইটাই বসত — দাবির খাতে দ্বিগুণ ক্রেডিট, সরবরাহকারীর দেনাও কমল, খরচও হলো।
 *
 * ── ⭐ এখন ─────────────────────────────────────────────────────────────
 * লেনদেনের প্রথম কাজ দাবির সারিতে তালা, তারপর অবস্থা আবার ([[DepositClaimService::lockPending()]]-এর ছাঁচ)।
 * দুই ক্লিকই মালিকের (super_admin) — প্রথমটা পাকা হওয়াও মাপা হয়।
 */
final class TheClaimWasSettledAndRejectedAtOnceTest extends TestCase
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

        app(SettingsService::class)->set('sales.commission_max_amount', 100000);
        app(SettingsService::class)->set('sales.commission_max_percent', 60);

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

    /** ⛔ একজন মানলেন, একই মুহূর্তে আরেকজন নাকচ — দ্বিতীয়টা `status`-এ ফেরে, খাতায় কেবল প্রথমটা। */
    public function test_a_claim_settled_and_rejected_at_once_is_decided_once(): void
    {
        $claim = $this->aClaim();
        $stale = CommissionClaim::query()->findOrFail($claim->id);

        $this->whileTheOtherClickPosts(fn () => app(CommissionClaimService::class)->settle(CommissionClaim::query()->findOrFail($claim->id)));

        $this->assertSame('status', $this->refusedOn(fn () => app(CommissionClaimService::class)->reject($stale, 'second click')));
        $this->assertSame(CommissionClaim::SETTLED, $claim->fresh()->status, 'দৃশ্যটাই বানানো যায়নি — প্রথম ক্লিকে দাবি মানা হয়নি।');
        $this->assertSame(0, $this->posted(CommissionClaim::STOCK_SOURCE.':rejected', $claim->id), '⛔ মেনে নেওয়া দাবি একই সাথে নাকচ হয়ে খাতায় বসেছে।');
    }

    /** ⛔ একই দাবি দুইবার নাকচ — খরচ একবারই। */
    public function test_a_claim_rejected_twice_is_written_off_once(): void
    {
        $claim = $this->aClaim();
        $stale = CommissionClaim::query()->findOrFail($claim->id);

        $this->whileTheOtherClickPosts(fn () => app(CommissionClaimService::class)->reject(CommissionClaim::query()->findOrFail($claim->id), 'first click'));

        $this->assertSame('status', $this->refusedOn(fn () => app(CommissionClaimService::class)->reject($stale, 'second click')));
        $this->assertSame(CommissionClaim::REJECTED, $claim->fresh()->status, 'দৃশ্যটাই বানানো যায়নি — প্রথম ক্লিকে দাবি নাকচ হয়নি।');
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

    private function posted(string $sourceType, int $sourceId): int
    {
        return LedgerEntry::query()->where('source_type', $sourceType)->where('source_id', $sourceId)->count();
    }

    private function aClaim(): CommissionClaim
    {
        $customer = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();

        $invoice = app(DirectSaleService::class)->complete(
            ['customer_id' => $customer->id, 'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'), 'own_transport' => '1'],
            [['product_id' => Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->value('id'), 'qty' => '10', 'rate' => '100', 'free_qty' => '0']],
        )['invoice'];

        return app(CommissionClaimService::class)->create([
            'customer_id' => $customer->id,
            'supplier_id' => Supplier::query()->firstOrFail()->id,
            'sales_invoice_id' => $invoice->id,
            'rate_percent' => '5',
        ]);
    }

    /** ব্যর্থ হলে কোন ঘরের বার্তা — কিছুই না আটকালে দাবিটা ব্যর্থ। */
    private function refusedOn(callable $work): string
    {
        try {
            $work();
        } catch (ValidationException $e) {
            return (string) array_key_first($e->errors());
        }

        $this->fail('⛔ কিছুই আটকায়নি — একই দাবি দুইবার খাতায় বসল।');
    }
}
