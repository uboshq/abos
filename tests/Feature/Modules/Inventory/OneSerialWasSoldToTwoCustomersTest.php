<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\SerialNumber;
use App\Modules\Inventory\Services\SerialNumberService;
use Database\Seeders\DemoSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * একই সিরিয়াল দুই গ্রাহকের কাছে বিক্রি হত — abos-63-এর তালিকা (abos-bb-র নিরীক্ষার বাকি), ২ অক্টোবর ২০২৬।
 *
 * ⛔ [[SerialNumberService::issue()]] পিসটা লেনদেনের ভিতরে পড়ত, কিন্তু তালা ছাড়া। দুই কাউন্টার একই মুহূর্তে একই
 * নম্বর বেচলে দুইজনেই "গুদামে" দেখত আর দুইজনেই বেচত — ওয়ারেন্টির দাবিতে দুই ক্রেতার কাগজে এক নম্বর।
 *
 * ⭐ মঞ্চ ([[TwoAtOnceBrokeAFinanceCeilingTest]]-এর ছাঁচ): আমাদের লেনদেন পিসটা পড়ার ঠিক পরে অন্যজন আরেক সংযোগে
 * একই নম্বর বেচতে যান। তালা থাকলে তিনি অপেক্ষায় আটকান (১ সেকেন্ডে ফেরেন); না থাকলে দুইজনেই বেচতেন।
 */
final class OneSerialWasSoldToTwoCustomersTest extends TestCase
{
    use DatabaseMigrations;

    private const SECOND = 'serial_two';

    private ?string $other = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $product = Product::query()->orderBy('id')->firstOrFail();
        $product->forceFill(['track_serial' => true])->save();
        app(SerialNumberService::class)->receive($product->fresh(), ['SN-RACE-1']);

        config(['database.connections.'.self::SECOND => config('database.connections.'.DB::getDefaultConnection())]);
        DB::purge(self::SECOND);
        DB::connection(self::SECOND)->statement('SET SESSION innodb_lock_wait_timeout = 1');

        auth()->user()?->loadMissing(['roles', 'permissions']);
        auth()->user()?->getAllPermissions();
    }

    /** ⚠️ কমিট হওয়া সারি তুলে নেওয়া — [[TwoAtOnceBrokeAFinanceCeilingTest::tearDown()]]-এর কারণেই। */
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

    public function test_a_second_counter_selling_the_same_serial_at_once_is_refused(): void
    {
        $main = DB::getDefaultConnection();
        $armed = true;

        Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$armed, $main): void {
            if (! $armed || $query->connectionName !== $main || DB::transactionLevel() === 0
                || ! str_starts_with(ltrim($query->sql), 'select') || ! str_contains($query->sql, 'inv_serial_numbers')) {
                return;
            }

            $armed = false;
            DB::setDefaultConnection(self::SECOND);

            try {
                app(SerialNumberService::class)->issue(['SN-RACE-1'], ['sold_to' => 'Other counter']);
                $this->other = 'done';
            } catch (QueryException|ValidationException $e) {
                $this->other = class_basename($e);
            } finally {
                DB::setDefaultConnection($main);
            }
        });

        $ours = 'done';

        try {
            app(SerialNumberService::class)->issue(['SN-RACE-1'], ['sold_to' => 'Our counter']);
        } catch (ValidationException) {
            $ours = 'refused';
        }

        $this->assertNotNull($this->other, 'দৃশ্যটাই বানানো যায়নি — আমাদের লেনদেনে সিরিয়ালের পড়াই হয়নি।');
        $this->assertFalse($ours === 'done' && $this->other === 'done',
            '⛔ একই সিরিয়াল দুই কাউন্টারেই বিক্রি হলো — দুই ক্রেতার কাগজে এক নম্বর।');
        $this->assertSame(SerialNumber::SOLD, SerialNumber::query()->numbered('SN-RACE-1')->value('status'));
    }
}
