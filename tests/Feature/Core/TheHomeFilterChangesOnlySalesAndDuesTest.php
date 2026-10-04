<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Contracts\HomeSalesFilters;
use App\Core\Dashboard\HomeFilter;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Dashboard\AccountsWidgets;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Metrics\SalesArea;
use App\Modules\Sales\Metrics\SalesMetrics;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\DirectSaleService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * হোমের ফিল্টার — মালিক, ৪ অক্টোবর ২০২৬: গুদাম/এলাকা/SR বাছলে বদলায় কেবল বিক্রি আর বকেয়া ([[HomeFilter]])।
 *
 * ⓘ দাবি: একটা বিক্রি কেবল নিজের গুদাম, নিজের SR আর নিজের এলাকার ছাঁকনিতে বাড়ে, অন্যগুলোয় নয়; ছাঁকনি কেবল
 * `during()`-এর ভেতরে চালু; বকেয়া এলাকা মানে; তালিকার বাইরের নম্বর ছাঁকনি নেয় না; পাতায় চালু ফিল্টারের লাইন থাকে।
 */
final class TheHomeFilterChangesOnlySalesAndDuesTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_sale_counts_only_under_its_own_warehouse_seller_and_area(): void
    {
        $this->seed(DemoSeeder::class);
        config(['abos.dashboards_v2' => true]);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($owner);

        $level = SalesArea::level();
        $tree = SalesArea::tree();
        $customer = Customer::query()->where('is_active', true)->get()
            ->first(fn (Customer $c) => SalesArea::of($c->location_id, $tree, $level) !== null);
        $this->assertNotNull($customer, 'ডেমোতে এলাকায় বসা কোনো গ্রাহক নেই — এলাকার দাবি কিছু দেখবে না।');
        $area = SalesArea::of($customer->location_id, $tree, $level)->id;
        $otherArea = $tree->where('level', $level)->keys()->first(fn (int $id) => $id !== $area);
        $this->assertNotNull($otherArea, 'দ্বিতীয় এলাকা নেই।');

        $warehouse = (int) Warehouse::query()->where('is_default', true)->value('id');
        $otherWarehouse = (int) Warehouse::query()->whereKeyNot($warehouse)->value('id');
        $other = User::query()->whereKeyNot($owner->id)->firstOrFail();

        $sell = function () use ($customer, $warehouse): void {
            app(DirectSaleService::class)->complete(
                ['customer_id' => $customer->id, 'warehouse_id' => $warehouse, 'own_transport' => '1'],
                [['product_id' => Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->value('id'), 'qty' => '1', 'rate' => '10', 'free_qty' => '0']],
            );
        };

        // ⓘ প্রথম বিক্রি — মালিক আর অন্যজন দুজনেই SR-তালিকায় ওঠেন
        $sell();
        $sell();
        SalesInvoice::query()->latest('id')->firstOrFail()->forceFill(['created_by' => $other->id])->saveQuietly();

        $filters = [
            'none' => [],
            'seller' => ['seller' => $owner->id],
            'other_seller' => ['seller' => $other->id],
            'warehouse' => ['warehouse' => $warehouse],
            'other_warehouse' => ['warehouse' => $otherWarehouse],
            'area' => ['area' => $area],
            'other_area' => ['area' => $otherArea],
        ];
        $today = Carbon::today()->toDateString();
        $totals = fn () => array_map(fn (array $q) => $this->filter($q)->during(fn () => SalesMetrics::invoiceTotal($today, $today)), $filters);

        $this->assertTrue($this->filter(['seller' => $other->id])->active(), 'অন্য SR তালিকায় ওঠেননি।');

        $before = $totals();
        $sell();
        $after = $totals();
        $grew = fn (string $k) => bcsub($after[$k], $before[$k], 4);
        $sale = $grew('none');

        $this->assertSame(1, bccomp($sale, '0', 4), 'বিক্রি মোটে ওঠেনি।');
        foreach (['seller', 'warehouse', 'area'] as $own) {
            $this->assertSame(0, bccomp($grew($own), $sale, 4), "⛔ নিজের {$own} ছাঁকনিতে বিক্রিটা পুরো ওঠেনি।");
        }
        foreach (['other_seller', 'other_area'] + ($otherWarehouse > 0 ? ['other_warehouse'] : []) as $foreign) {
            $this->assertSame(0, bccomp($grew($foreign), '0', 4), "⛔ অন্য ছাঁকনিতে ({$foreign}) বিক্রিটা উঠেছে।");
        }

        // ⛔ ছাঁকনি কেবল during()-এর ভেতরে
        $this->assertNull(HomeFilter::current());
        $this->assertSame(0, bccomp(SalesMetrics::invoiceTotal($today, $today), $after['none'], 4), '⛔ ছাঁকনি পরে ফাঁস হয়েছে।');

        // ⓘ তালিকার বাইরের নম্বর — ছাঁকনি নেয় না
        $this->assertFalse($this->filter(['seller' => 999999, 'warehouse' => 999999, 'area' => 999999])->active());

        // ⭐ বকেয়া — এলাকা মানে; অন্য এলাকায় এই গ্রাহকের বকেয়া নেই, SR বকেয়া বদলায় না
        $receivable = new \ReflectionMethod(AccountsWidgets::class, 'receivable');
        $whole = $receivable->invoke(null);
        $inArea = $this->filter(['area' => $area])->during(fn () => $receivable->invoke(null));
        $ids = app(HomeSalesFilters::class)->customersInArea($area);
        $this->assertContains($customer->id, $ids);
        $expected = Customer::query()->whereIn('id', $ids)->get()->reduce(fn (string $s, Customer $c) => bcadd($s, $c->outstanding(), 4), '0');
        $this->assertSame(0, bccomp($inArea, $expected, 4), '⛔ এলাকার বকেয়া গ্রাহকদের খাতার যোগফল নয়।');
        $this->assertSame(0, bccomp($this->filter(['seller' => $owner->id])->during(fn () => $receivable->invoke(null)), $whole, 4),
            '⛔ SR বাছায় বকেয়া বদলেছে — বকেয়া গ্রাহকের, বিলের নয়।');

        // ⭐ পাতা — চালু ফিল্টারের লাইন, বাছা নাম, আর সময়ের লিংকে ফিল্টার থেকে যায়
        $page = $this->get(route('dashboard', ['seller' => $owner->id]))->assertOk()->getContent();
        $this->assertStringContainsString('data-home-filter-on', $page);
        $this->assertStringContainsString('seller='.$owner->id, $page, '⛔ সময় বদলালে ফিল্টার হারায়।');
        $page = $this->get(route('dashboard', ['seller' => 999999]))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-home-filter-on', $page, '⛔ তালিকার বাইরের SR-এ ফিল্টার চালু দেখায়।');
        $this->assertStringContainsString('data-home-filter', $page);
    }

    private function filter(array $query): HomeFilter
    {
        $sales = app(HomeSalesFilters::class);

        return HomeFilter::fromRequest(Request::create('/', 'GET', $query), $sales->choices(), $sales);
    }
}
