<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * ডিলার পোর্টালের কর্তা গ্রাহক, কর্মী নন — কর্মীর গুদামের ছাঁকনি তাঁর ওপর ভাঙে না। ৩ অক্টোবর ২০২৬ (abos-2c-এর ধরা;
 * [[ScopedToUserWarehouse]])।
 *
 * ⛔ আগে: পোর্টালে DO জমা দিলে মজুদ আটকানোর কাজ গুদাম, মজুদের চলাচল আর লট পড়ত, আর ছাঁকনি `auth()->user()`-কে
 * কর্মী ধরে [[DataScope::idsFor()]]-এ পাঠাত — গ্রাহক যেতেই TypeError, পাতায় ৫০০।
 */
final class ADealerOnThePortalIsNotAStaffMemberTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_three_warehouse_scoped_tables_read_without_error_for_a_portal_customer(): void
    {
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $dealer = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();

        Auth::shouldUse('portal');
        $this->actingAs($dealer, 'portal');
        $this->assertInstanceOf(Customer::class, auth()->user(), 'প্রস্তুতিটাই ভুল — কর্তা গ্রাহক নন।');

        $this->assertGreaterThan(0, Warehouse::query()->count(), '⛔ গ্রাহক কর্তা হলে গুদামই দেখা যায় না।');
        StockMovement::query()->count();
        Batch::query()->count();
    }
}
