<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Executive;

use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Core\Support\Money;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Executive\Services\Board;
use App\Modules\Executive\Services\Figures;
use App\Modules\Executive\Services\Snapshots;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Services\DirectSaleService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * রাতের হিসাব পড়ার সময় পড়ুয়ার চাবি ফেরে — ec, Executive লাইন-নিরীক্ষা, ১৪ অক্টোবর ২০২৬।
 *
 * ── ⛔ কী হতো ────────────────────────────────────────────────────────
 * সারিটা super_admin-এর চোখে লেখা, তাই প্রতিটা ঘর ভরা। মালিক দ্বিতীয় একজনকে `executive.view` আর বিক্রির
 * চাবি দিলেন, হিসাবের চাবি আর ক্রয়মূল্যের চাবি দিলেন না। "আজ" পাতায় তাঁর তহবিল আর মজুদের দাম ঢাকা (••••);
 * ইতিহাসে ঐ দুইটাই প্রতিটা আগের দিনের পুরো সংখ্যায়, আর `?figure=stock` দিলে ৯০ দিনের মজুদের দামের ধারা।
 * "সইয়ের অপেক্ষায়"-ও super_admin-এর গোটা কোম্পানির সংখ্যা, তাঁর নিজের নয়।
 */
final class TheNightsRecordHidesWhatTheReadersKeysHideTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Company $alpha;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        config(['abos.dashboards_v2' => true]);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->alpha = Company::query()->where('code', 'TDEPOT')->firstOrFail();

        $this->actingAs($this->owner);
        CompanyContext::set((int) $this->alpha->id, (int) $this->owner->current_branch_id);
    }

    public function test_a_hidden_figure_stays_hidden_in_the_history_grid_and_its_trend(): void
    {
        $this->sell('3', '1500');
        $this->artisan('abos:owner-snapshot')->assertSuccessful();

        $today = Carbon::today()->toDateString();
        $whole = DB::table('executive_snapshots')->where('company_id', $this->alpha->id)->where('place', 0)->where('taken_on', $today)->first();
        $this->assertNotNull($whole->stock, 'প্রস্তুতিটাই ভুল — রাতের হিসাবে মজুদের দাম নেই।');
        $this->assertNotNull($whole->fund, 'প্রস্তুতিটাই ভুল — রাতের হিসাবে তহবিল নেই।');

        // ⓘ বিক্রি, মজুদ, অর্থ আর অনুমোদনের ড্যাশবোর্ড খোলে — কিন্তু হিসাবের চাবি, ক্রয়মূল্যের চাবি, রিপোর্টের চাবি নেই
        $reader = $this->member(['executive.view', 'sales.invoice.view', 'inventory.stock.view', 'finance.capital.view', 'approval.view']);

        $page = $this->actingAs($reader)->get(route('executive.history', ['date' => $today]))->assertOk();

        $page->assertSee('data-history-row="'.$this->alpha->id.'-0"', false)
            ->assertSee('data-cell="sales">'.Money::format((string) $whole->sales, 0).'</td>', false)
            ->assertSee('data-cell="stock">'.Figures::HIDDEN.'</td>', false)
            ->assertSee('data-cell="fund">'.Figures::HIDDEN.'</td>', false)
            ->assertSee('data-cell="signatures">'.Figures::HIDDEN.'</td>', false);

        $this->assertNotSame([], $page->viewData('series'), 'প্রস্তুতিটাই ভুল — খোলা সংখ্যার ধারাও খালি।');

        foreach ([Figures::STOCK, Figures::FUND, Figures::SIGNATURES] as $key) {
            $trend = $this->actingAs($reader)->get(route('executive.history', ['date' => $today, 'figure' => $key]))->assertOk();
            $this->assertSame([], $trend->viewData('series'), "⛔ ঢাকা '{$key}'-এর ধারা ইতিহাসে খোলা।");
        }

        // ⓘ তুলনার পাতার "তখন"-ও একই পথে পড়ে
        $this->actingAs($reader);
        $this->assertNull(app(Snapshots::class)->valueOn($reader, (int) $this->alpha->id, null, Figures::STOCK, $today),
            '⛔ ঢাকা মজুদের দাম তুলনার "তখন"-এ খোলা।');

        // ⭐ মালিকের চোখে সব আগের মতোই খোলা
        $this->actingAs($this->owner->fresh())->get(route('executive.history', ['date' => $today]))->assertOk()
            ->assertSee('data-cell="stock">'.Money::format((string) $whole->stock, 0).'</td>', false);
    }

    /** @param list<string> $keys */
    private function member(array $keys): User
    {
        $user = User::factory()->create(['current_company_id' => $this->alpha->id, 'current_branch_id' => null, 'is_active' => true]);
        $user->companies()->attach($this->alpha->id, ['is_active' => true]);

        CompanyContext::forCompany((int) $this->alpha->id, function () use ($user, $keys): void {
            foreach ($keys as $key) {
                $user->givePermissionTo(Permission::findOrCreate($key, 'web'));
            }
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        app(DataScope::class)->forget();

        return $user->fresh();
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
