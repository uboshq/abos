<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Purchase\Models\Quotation;
use App\Modules\Purchase\Models\Rfq;
use App\Modules\Purchase\Services\RfqService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⛔ এক দরপত্রে একজন সরবরাহকারীর দুটো দর (Purchase লাইন-ধরা নিরীক্ষা, ১৩ অক্টোবর ২০২৬; [[RfqService::quote()]])।
 *
 * ⚠️ "আগেই দর দিয়েছেন?" দেখা হত তালা ছাড়া, লেনদেনের বাইরে: দুই ক্লিক একই মুহূর্তে দুজনেই "দেননি" দেখত, দুটো দর বসত, আর তুলনায়
 * একজন দুবার। ⭐ এখন দরপত্রের সারিতে তালা দিয়ে আবার দেখা — দ্বিতীয়জন অপেক্ষা করেন।
 */
final class TwoQuotesFromOneSupplierTest extends TestCase
{
    use DatabaseMigrations;

    private const SECOND = 'rfq_two';

    private Rfq $rfq;

    private Supplier $supplier;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        app(SettingsService::class)->set('purchase.screen_requisitions', true);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->product = Product::query()->firstOrFail();
        $this->supplier = Supplier::query()->orderBy('id')->firstOrFail();
        $this->rfq = app(RfqService::class)->create(
            ['trx_date' => now()->toDateString(), 'respond_by' => now()->addDays(3)->toDateString()],
            [['product_id' => $this->product->id, 'qty' => '5']],
            [$this->supplier->id],
        );

        config(['database.connections.'.self::SECOND => config('database.connections.'.DB::getDefaultConnection())]);
        DB::purge(self::SECOND);
        DB::connection(self::SECOND)->statement('SET SESSION innodb_lock_wait_timeout = 1');
    }

    /** ⚠️ কমিট হওয়া সারি তুলে নেওয়া — [[TwoTransfersEmptiedOneTillTest::tearDown()]]-এর কারণেই। */
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

    public function test_two_quotes_entered_at_the_same_moment_leave_one(): void
    {
        $main = DB::getDefaultConnection();
        $armed = true;

        // ⓘ প্রথমজনের লেনদেন দর-টেবিল ছুঁলো — ঠিক তখনই একই সরবরাহকারীর দ্বিতীয় দর
        Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$armed, $main): void {
            if (! $armed || $query->connectionName !== $main || DB::transactionLevel() === 0
                || ! str_contains($query->sql, 'pur_quotations')) {
                return;
            }

            $armed = false;
            DB::setDefaultConnection(self::SECOND);

            try {
                $this->quote('90');
            } catch (QueryException|ValidationException) {
                // ⭐ তালায় আটকালেন, বা "আগেই দিয়েছেন" শুনলেন
            } finally {
                DB::setDefaultConnection($main);
            }
        });

        $this->quote('100');

        $this->assertFalse($armed, 'দৃশ্যটাই বানানো যায়নি — দ্বিতীয় দর কখনো চলেনি।');
        $this->assertSame(1, Quotation::query()->where('rfq_id', $this->rfq->id)->where('supplier_id', $this->supplier->id)->count(),
            '⛔ এক দরপত্রে একই সরবরাহকারীর দুটো দর।');
    }

    private function quote(string $rate): Quotation
    {
        return app(RfqService::class)->quote(
            ['rfq_id' => $this->rfq->id, 'supplier_id' => $this->supplier->id, 'quoted_on' => now()->toDateString()],
            [['product_id' => $this->product->id, 'qty' => '5', 'rate' => $rate, 'discount' => '0', 'tax' => '0']],
        );
    }
}
