<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Search\SearchEngine;
use App\Core\Engines\Search\SearchHit;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\SalesOrderService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⚠️ খোঁজা বন্ধ পর্দার কাগজ দেখাত — ec, Core লাইন-নিরীক্ষা, ১৫ অক্টোবর ২০২৬ ([[SearchEngine::search()]],
 * [[RefuseSwitchedOffScreens::refuses()]])।
 *
 * ⓘ মালিক আদেশের পর্দা বন্ধ রাখলেও Ctrl+K আদেশের নম্বর আর গ্রাহকের নাম দেখাত, চাপলে ৪০৪; খালি বাক্সের সাম্প্রতিক তালিকা
 * ([[StartingPoints::recent()]]) একই সুইচ আগেই মানত।
 */
final class ASearchOpenedASwitchedOffScreenTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_switched_off_screens_paper_is_not_offered_by_the_search(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($owner);
        app(SettingsService::class)->set('sales.screen_orders', true);

        app(SalesOrderService::class)->create(
            [
                'customer_id' => Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail()->id,
                'warehouse_id' => Warehouse::query()->where('is_default', true)->firstOrFail()->id,
                'trx_date' => now()->toDateString(),
            ],
            [['product_id' => Product::query()->orderBy('id')->firstOrFail()->id, 'ordered_qty' => '1', 'rate' => '10']],
        );
        $order = SalesOrder::query()->latest('id')->firstOrFail();

        // ⓘ নিয়ন্ত্রণ — পর্দা চালু থাকলে আদেশটা খোঁজায় আসে
        $this->assertContains($order->document_no, $this->orders($order->document_no, $owner),
            'পর্দা চালু অবস্থাতেই আদেশটা খোঁজায় এল না — নিচের দাবি তাহলে ফাঁকা।');

        app(SettingsService::class)->set('sales.screen_orders', false);
        app()->forgetInstance(\App\Http\Middleware\RefuseSwitchedOffScreens::class);

        $this->assertNotContains($order->document_no, $this->orders($order->document_no, $owner),
            '⛔ আদেশের পর্দা বন্ধ, তবু খোঁজা আদেশটা দেখাল — চাপলে ৪০৪।');
    }

    /** @return list<string> */
    private function orders(string $term, User $user): array
    {
        return array_map(
            fn (SearchHit $hit) => $hit->documentNo,
            array_values(array_filter(app(SearchEngine::class)->search($term, $user), fn (SearchHit $hit) => $hit->slug === 'sales_order')),
        );
    }
}
