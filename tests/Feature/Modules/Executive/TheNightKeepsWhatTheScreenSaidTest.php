<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Executive;

use App\Core\Engines\Report\Trend;
use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Core\Support\Money;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Customer\Models\Customer;
use App\Modules\Executive\Services\Board;
use App\Modules\Executive\Services\Comparison;
use App\Modules\Executive\Services\Figures;
use App\Modules\Executive\Services\Snapshots;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Services\DirectSaleService;
use Database\Seeders\DemoSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * রাতের হিসাব — প্রতিটা কোম্পানি ও শাখার আটটা সংখ্যা, প্রতিরাতে, তিন বছর।
 *
 * ⭐ লেখা সংখ্যা = ঐ মুহূর্তে "আজ" পাতার সংখ্যা; দুইবার চালালে নতুন সারি নয়; তিন বছরের বেশি পুরনো সরে।
 */
final class TheNightKeepsWhatTheScreenSaidTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Company $alpha;

    private Company $beta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        config(['abos.dashboards_v2' => true]);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->alpha = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->beta = Company::query()->where('code', 'FMART')->firstOrFail();

        $this->actingAs($this->owner);
        CompanyContext::set((int) $this->alpha->id, (int) $this->owner->current_branch_id);
    }

    public function test_one_row_per_company_branch_and_day_equal_to_the_screen(): void
    {
        $this->sell('3', '1500');
        $this->artisan('abos:owner-snapshot')->assertSuccessful();

        $today = Carbon::today()->toDateString();
        $branches = DB::table('branches')->whereIn('company_id', [$this->alpha->id, $this->beta->id])->where('is_active', true)->whereNull('deleted_at')->count();

        // ⓘ প্রতিটা শাখা + প্রতিটা কোম্পানির গোটা সারি
        $this->assertSame($branches + 2, DB::table('executive_snapshots')->whereIn('company_id', [$this->alpha->id, $this->beta->id])->where('taken_on', $today)->count(),
            '⛔ প্রতিটা কোম্পানি/শাখা/দিনে ঠিক একটা সারি নয়।');

        $board = app(Board::class)->build($this->owner, Figures::TODAY);

        foreach ($board['companies'] as $company) {
            $whole = DB::table('executive_snapshots')->where('company_id', $company['id'])->where('place', 0)->where('taken_on', $today)->first();

            foreach ([Figures::SALES, Figures::COLLECTIONS, Figures::RECEIVABLE, Figures::PAYABLE, Figures::FUND, Figures::STOCK, Figures::SIGNATURES] as $key) {
                $this->assertSame(0, bccomp((string) $whole->{$key}, $company['values'][$key], 4),
                    "⛔ {$company['name']}-এর '{$key}' রাতের হিসাবে {$whole->{$key}}, অথচ পর্দায় {$company['values'][$key]}।");
            }

            foreach ($company['rows'] as $row) {
                $kept = DB::table('executive_snapshots')->where('company_id', $company['id'])->where('place', $row['id'])->where('taken_on', $today)->first();
                $this->assertNotNull($kept, "⛔ শাখা {$row['name']}-এর সারি নেই।");
                $this->assertSame(0, bccomp((string) $kept->receivable, $row['values'][Figures::RECEIVABLE], 4));
                $this->assertSame(0, bccomp((string) $kept->sales, $row['values'][Figures::SALES], 4));
                $this->assertNull($kept->signatures, 'অনুমোদনের সারিতে শাখা নেই — শাখার সারিতে সংখ্যাটা থাকার কথা নয়।');
            }
        }

        $this->assertSame(0, bccomp('4500', (string) DB::table('executive_snapshots')->where('company_id', $this->alpha->id)->where('place', 0)->value('sales'), 4),
            'প্রস্তুতিটাই ভুল — আজকের বিক্রি রাতের হিসাবে নেই।');
    }

    public function test_running_twice_writes_no_second_row_and_keeps_the_latest(): void
    {
        $this->artisan('abos:owner-snapshot')->assertSuccessful();
        $count = DB::table('executive_snapshots')->count();

        $this->sell('2', '1000');
        $this->artisan('abos:owner-snapshot')->assertSuccessful();

        $this->assertSame($count, DB::table('executive_snapshots')->count(), '⛔ দ্বিতীয়বার চালালে নতুন সারি বসেছে।');
        $this->assertSame(0, bccomp('2000', (string) DB::table('executive_snapshots')->where('company_id', $this->alpha->id)->where('place', 0)->value('sales'), 4),
            '⛔ দ্বিতীয়বার চালালে আগের সংখ্যাই রয়ে গেছে।');
    }

    public function test_records_older_than_three_years_are_pruned_and_nothing_younger(): void
    {
        $edge = Carbon::today()->subYears(Snapshots::KEEP_YEARS);

        foreach ([[$edge->copy()->subDay(), 'gone'], [$edge->copy(), 'kept'], [Carbon::today()->subYear(), 'kept']] as [$day, $_]) {
            DB::table('executive_snapshots')->insert([
                'company_id' => $this->alpha->id, 'place' => 0, 'taken_on' => $day->toDateString(),
                'sales' => '1', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->artisan('abos:owner-snapshot')->assertSuccessful();

        $this->assertFalse(DB::table('executive_snapshots')->where('taken_on', $edge->copy()->subDay()->toDateString())->exists(),
            '⛔ তিন বছরের বেশি পুরনো সারি থেকে গেছে।');
        $this->assertTrue(DB::table('executive_snapshots')->where('taken_on', $edge->toDateString())->exists(),
            '⛔ ঠিক তিন বছরের সারিটা সরে গেছে — রাখার কথা ছিল।');
        $this->assertTrue(DB::table('executive_snapshots')->where('taken_on', Carbon::today()->subYear()->toDateString())->exists());
    }

    public function test_it_is_scheduled_for_five_to_midnight(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'abos:owner-snapshot'));

        $this->assertNotNull($event, '⛔ রাতের হিসাবের আদেশটা নির্ধারিত কাজের তালিকায় নেই — কেউ চালাবে না।');
        $this->assertSame('55 23 * * *', $event->expression);
    }

    public function test_the_history_page_shows_last_month_and_receivable_then_reaches_compare(): void
    {
        // ⓘ গত মাসের আজকের দিনে রাতের হিসাব
        $then = Carbon::today()->subMonthNoOverflow();
        Carbon::setTestNow($then->copy()->setTime(23, 55));
        $this->sell('2', '2500');
        $this->artisan('abos:owner-snapshot')->assertSuccessful();
        Carbon::setTestNow();

        $kept = (string) DB::table('executive_snapshots')->where('company_id', $this->alpha->id)->where('place', 0)
            ->where('taken_on', $then->toDateString())->value('receivable');
        $this->assertSame(1, bccomp($kept, '0', 4), 'প্রস্তুতিটাই ভুল — গত মাসের বাকি শূন্য।');

        $this->actingAs($this->owner)->get(route('executive.history'))
            ->assertOk()
            ->assertSee(Money::format($kept, 0))
            ->assertSee('data-history-row="'.$this->alpha->id.'-0"', false);

        // ⭐ তুলনার পাতায় বাকির "তখন" = সেই রাতের হিসাব (আগের মাসের একই দিন)
        $compare = app(Comparison::class)->build($this->owner, Comparison::COMPANIES, (int) $this->alpha->id, Trend::PREVIOUS_MONTH);
        $this->assertSame(0, bccomp($kept, (string) $compare['rows'][0]['was'][Figures::RECEIVABLE], 4),
            '⛔ তুলনার পাতায় বাকির "তখন" রাতের হিসাব থেকে আসেনি।');
    }

    public function test_a_branch_limited_reader_sees_only_their_branch_not_the_company_sum(): void
    {
        $this->artisan('abos:owner-snapshot')->assertSuccessful();

        $accountant = User::query()->where('email', 'accounts@abos.test')->firstOrFail();
        CompanyContext::forCompany((int) $this->alpha->id, function () use ($accountant) {
            foreach ($accountant->roles as $role) {
                Role::findById($role->id)->givePermissionTo('executive.view');
            }
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $mms = Branch::acrossAllCompanies()->where('company_id', $this->alpha->id)->where('code', 'MMS')->firstOrFail();
        UserDataScope::query()->create(['company_id' => $this->alpha->id, 'user_id' => $accountant->id, 'scope_type' => UserDataScope::BRANCH, 'scope_id' => $mms->id]);
        app(DataScope::class)->forget();

        $page = $this->actingAs($accountant->fresh())->get(route('executive.history', ['date' => Carbon::today()->toDateString()]))->assertOk();

        $page->assertSee('data-history-row="'.$this->alpha->id.'-'.$mms->id.'"', false)
            ->assertDontSee('data-history-row="'.$this->alpha->id.'-0"', false)
            ->assertDontSee($this->beta->name_bn)
            ->assertDontSee(Branch::acrossAllCompanies()->where('company_id', $this->alpha->id)->where('code', 'NTK')->firstOrFail()->name());
    }

    private function sell(string $qty, string $rate): void
    {
        CompanyContext::forCompany((int) $this->alpha->id, function () use ($qty, $rate) {
            $warehouse = Warehouse::query()->where('code', 'WH-MMS')->firstOrFail();
            CompanyContext::set((int) $this->alpha->id, (int) $warehouse->branch_id);

            app(DirectSaleService::class)->complete(
                ['customer_id' => Customer::acrossDealers()->where('name_en', 'Rahim Traders')->firstOrFail()->id,
                    'warehouse_id' => $warehouse->id, 'own_transport' => '1', DirectSaleService::REPEAT_FIELD => '1'],
                [['product_id' => Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->firstOrFail()->id,
                    'qty' => $qty, 'rate' => $rate, 'free_qty' => '0']],
            );
        });

        app(Board::class)->refresh($this->owner);
    }
}
