<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Services\DealerScope;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Models\DealerBinding;
use App\Modules\Customer\Services\DealerBindingService;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Services\DeliveryChallanService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⛔ লটের রিকল ডিলারের দেয়াল মানে — fe-র বিক্রয় ফাঁক ১৯, ২০ অক্টোবর ২০২৬ ([[BatchTrace::recipients()]], [[DealerScope::restrict()]])।
 *
 * ⓘ আগে দেয়ালের ভিতরের বিক্রয়কর্মী লট বাছলেই অন্য বিক্রয়কর্মীর দোকানের নাম আর ফোন দেখতেন — ওয়েবের বাকি তালিকা যা লুকায়।
 * ⭐ একই মানুষ দুইবার: চিহ্নসহ কেবল নিজের ডিলার (X = রহিম), চিহ্ন তুলে নিলে দুইজনই — তফাতটা কেবল দেয়ালের, শাখা বা অনুমতির নয়।
 *   মালিক দুইজনকেই দেখেন। ⓘ ফিক্সচার: Y (করিম) সত্যিই এই লট থেকে কিনেছেন — চলাচলের সারিতে আর মালিকের পাতায়।
 */
final class TheLotRecallStaysBehindTheDealerWallTest extends TestCase
{
    use RefreshDatabase;

    private const X = 'Rahim Traders';

    private const Y = 'Karim Stores';

    public function test_a_walled_salesman_recalls_only_his_own_dealers_and_the_owner_sees_both(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $sales = User::query()->where('email', 'sales@abos.test')->firstOrFail();
        $owner->forceFill(['locale' => 'en'])->save();
        // ⓘ বিক্রয়কর্মীর হেডার চালানের শাখায় — শাখার দেয়াল যেন তফাতটা না বানায়
        $sales->forceFill(['locale' => 'en', 'current_company_id' => $company->id, 'current_branch_id' => $company->defaultBranch()?->id])->save();
        $this->actingAs($owner);

        $x = Customer::query()->where('name_en', self::X)->firstOrFail();
        $y = Customer::query()->where('name_en', self::Y)->firstOrFail();

        // ⓘ ডেমোর বাঁধন মুছে নিজের ছক: বিক্রয়কর্মী কেবল X-এ বাঁধা
        DealerBinding::query()->withoutGlobalScopes()->where('user_id', $sales->id)->delete();
        app(DealerBindingService::class)->bind((int) $sales->id, [(int) $x->id], now()->toDateString());
        app(SettingsService::class)->set(DealerScope::SWITCH, true);

        CompanyContext::forCompany($company->id, function () use ($sales): void {
            $sales->givePermissionTo(Permission::findOrCreate('sales.challan.view', 'web'));
            Role::findByName('salesman', 'web')->givePermissionTo(Permission::findOrCreate(DealerScope::OWN, 'web'));
        });
        $this->forget($company);

        $warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $product = Product::query()->firstOrFail();
        $product->forceFill(['track_batch' => true])->save();
        $batch = Batch::query()->create(['product_id' => $product->id, 'batch_no' => 'WALL-RECALL1', 'expiry_date' => now()->addMonths(9)->toDateString()]);
        app(StockService::class)->move(product: $product, warehouse: $warehouse, sourceType: 'test_opening', sourceId: $batch->id, floor: '100', batch: $batch);

        $this->sell($x, $product, $warehouse, '2');
        $this->sell($y, $product, $warehouse, '3');

        // ⓘ ফিক্সচার — Y সত্যিই এই লট থেকে কিনেছেন: চলাচলের সারিতে তাঁর চালান
        $yBought = DB::table('inv_stock_movements as m')
            ->join((new DeliveryChallan)->getTable().' as c', 'c.id', '=', 'm.source_id')
            ->where('m.company_id', $company->id)->where('m.batch_id', $batch->id)
            ->where('m.source_type', DeliveryChallan::STOCK_SOURCE)->where('m.floor_change', '<', 0)
            ->where('c.customer_id', $y->id)->exists();
        $this->assertTrue($yBought, 'দৃশ্যটাই বানানো যায়নি — Y এই লট থেকে কেনেননি');

        // ⓘ ফিক্সচার — বিক্রয়কর্মী সত্যিই দেয়ালের ভিতরে: সুইচ চালু, OWN আছে, ALL নেই, X-এ বাঁধা, Y-তে নয়
        $sales = $sales->fresh();
        $this->assertTrue(app(DealerScope::class)->switchOn(), 'দৃশ্যটাই বানানো যায়নি — ডিলারের সুইচ বন্ধ');
        $this->assertTrue($sales->can(DealerScope::OWN), 'দৃশ্যটাই বানানো যায়নি — OWN চাবি নেই');
        $this->assertFalse($sales->can(DealerScope::ALL), 'দৃশ্যটাই বানানো যায়নি — ALL চাবি আছে');
        $this->assertTrue(app(DealerScope::class)->walled($sales), 'দৃশ্যটাই বানানো যায়নি — বিক্রয়কর্মী দেয়ালের বাইরে');
        $this->assertTrue(app(DealerScope::class)->allowsCustomer((int) $x->id, $sales), 'দৃশ্যটাই বানানো যায়নি — X বাঁধা নয়');
        $this->assertFalse(app(DealerScope::class)->allowsCustomer((int) $y->id, $sales), 'দৃশ্যটাই বানানো যায়নি — Y-ও বাঁধা');

        // ⭐ মালিক দুইজনকেই দেখেন
        [$seen] = $this->recall($owner, $batch, $company);
        $this->assertEqualsCanonicalizing([(int) $x->id, (int) $y->id], $this->ids($seen), '⛔ মালিক রিকলে দুই ক্রেতাকে দেখেন না');

        // ⛔ দেয়ালের ভিতরের বিক্রয়কর্মী — কেবল X, আর Y-এর নাম পাতায় নেই
        [$seen, $page] = $this->recall($sales, $batch, $company);
        $this->assertSame([(int) $x->id], $this->ids($seen), '⛔ দেয়ালের ভিতরের বিক্রয়কর্মী রিকলে অন্যের ডিলার দেখলেন');
        $page->assertSee(self::X)->assertDontSee(self::Y);

        // ⭐ একই মানুষ, চিহ্ন তুলে নিলে — দুইজনই (Y-এর অনুপস্থিতি দেয়ালের, শাখা বা অনুমতির নয়)
        CompanyContext::forCompany($company->id, fn () => Role::findByName('salesman', 'web')->revokePermissionTo(DealerScope::OWN));
        $this->forget($company);
        [$seen, $page] = $this->recall($sales, $batch, $company);
        $this->assertEqualsCanonicalizing([(int) $x->id, (int) $y->id], $this->ids($seen), '⛔ চিহ্ন তোলার পরেও Y আসে না — তফাতটা দেয়ালের নয়');
        $page->assertSee(self::Y);
    }

    private function sell(Customer $to, Product $product, Warehouse $warehouse, string $qty): DeliveryChallan
    {
        $challan = app(DeliveryChallanService::class)->create(
            ['customer_id' => $to->id, 'warehouse_id' => $warehouse->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $product->id, 'delivered_qty' => $qty, 'rate' => '100']],
        );

        return app(DeliveryChallanService::class)->confirm($challan);
    }

    /** @return array{0: Collection<int, object>, 1: \Illuminate\Testing\TestResponse} */
    private function recall(User $as, Batch $batch, Company $company): array
    {
        $seen = collect();
        View::composer('sales::lot.trace', function ($view) use (&$seen) {
            $seen = $view->getData()['recipients'];
        });

        $this->app['auth']->forgetGuards();
        $this->forget($company);
        $page = $this->actingAs($as->fresh())->get(route('sales.lot.trace', ['batch' => $batch->id]))->assertOk();

        return [$seen, $page];
    }

    /** @return list<int> */
    private function ids(Collection $rows): array
    {
        return $rows->map(fn ($r) => (int) $r->customer_id)->unique()->values()->all();
    }

    private function forget(Company $company): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        app(DealerScope::class)->forget();
        app(\App\Core\Services\PermissionOverrides::class)->forget();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
    }
}
