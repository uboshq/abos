<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Sales\Models\DeliveryOrder;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesQuotation;
use App\Modules\Sales\Support\DeliveryOrderStatus;
use App\Modules\Sales\Support\SalesOrderStatus;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ⭐ ফেরত আর বাতিল কাগজ তালিকার মোটে নয় — ওয়েবে আর ফোনে একই অঙ্ক (fe, ১৭ অক্টোবর ২০২৬; a6-এর পর্যালোচনা: ওয়েব সব যোগ করত,
 * ফোন বাদ দিত)।
 *
 * দাবি: আদেশ, DO আর দরপত্রের তালিকায় একটা করে ১০০ টাকার চালু কাগজ, একটা ১০,০০০ টাকার বাতিল আর একটা ফেরত (দরপত্রে পুরনো
 * সংস্করণও) — ওয়েবের পাতার সর্বমোট আর ফোনের দরজার `total` দুটোই কেবল ১০০, আর পাতায় "বাদ" লেখা আছে।
 */
final class TheListTotalLeavesOutWhatNeverHappenedTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
        app(SettingsService::class)->set('customer.credit_limit_enabled', false);
    }

    public function test_the_web_and_the_phone_total_only_what_happened(): void
    {
        $lists = [
            [SalesOrder::class, '/api/v1/sales/orders', 'sales.order.index', 'total_leaves_out_order',
                [SalesOrderStatus::CANCELLED, SalesOrderStatus::REJECTED]],
            [DeliveryOrder::class, '/api/v1/sales/delivery-orders', 'sales.delivery_order.index', 'total_leaves_out_delivery_order',
                [DeliveryOrderStatus::CANCELLED, DeliveryOrderStatus::REJECTED]],
            [SalesQuotation::class, '/api/v1/sales/quotations', 'sales.quotation.index', 'total_leaves_out_quotation',
                [SalesQuotation::CANCELLED, SalesQuotation::REJECTED, SalesQuotation::REVISED]],
        ];

        $body = ['customer' => (string) Customer::query()->where('name_en', 'Rahim Traders')->value('public_id'), 'lines' => [
            ['product' => (string) Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->value('public_id'), 'qty' => '2'],
        ]];

        foreach ($lists as [$model, $api, $route, $note, $closed]) {
            $params = $route === 'sales.delivery_order.index' ? ['tab' => 'all'] : [];
            [$phoneBefore, $webBefore] = $this->totals($api, $route, $params);
            $this->assertSame(0, bccomp($phoneBefore, $webBefore, 2), "⛔ {$route}: ডেমোর তথ্যেই ওয়েব {$webBefore}, ফোন {$phoneBefore}।");

            // ⓘ একটা চালু কাগজ ১০০, বাকিগুলো ১০,০০০ — বন্ধ অবস্থায়; মোট ঠিক ১০০ বাড়ার কথা, দুই দিকেই
            foreach ([null, ...$closed] as $status) {
                $this->phone();
                $id = $this->postJson($api, $body)->assertCreated()->json('id');
                $this->actingAs($this->owner);
                $model::query()->where('public_id', $id)->update(['total' => $status === null ? '100' : '10000']
                    + ($status === null ? [] : ['status' => $status]));
            }

            [$phoneAfter, $webAfter] = $this->totals($api, $route, $params);
            $this->assertSame('100.00', bcsub($phoneAfter, $phoneBefore, 2), "⛔ {$api}: ফোনের মোটে বাতিল বা ফেরত ঢুকেছে।");
            $this->assertSame('100.00', bcsub($webAfter, $webBefore, 2), "⛔ {$route}: ওয়েবের সর্বমোটে বাতিল বা ফেরত ঢুকেছে।");
            $this->assertSame(0, bccomp($phoneAfter, $webAfter, 2), "⛔ {$route}: ওয়েব {$webAfter}, ফোন {$phoneAfter}।");

            $this->app['auth']->forgetGuards();
            $this->actingAs($this->owner)->get(route($route, $params))->assertSee(__('sales::message.'.$note));
        }
    }

    /**
     * @param  array<string, string>  $params
     * @return array{0: string, 1: string} ফোনের total, ওয়েবের সর্বমোট
     */
    private function totals(string $api, string $route, array $params): array
    {
        $this->phone();
        $phone = (string) $this->getJson($api)->assertOk()->json('total');

        $this->app['auth']->forgetGuards();
        $web = (string) ($this->actingAs($this->owner)->get(route($route, $params))->assertOk()->viewData('grand')['total'] ?? '0');

        return [$phone, bcadd($web, '0', 2)];
    }

    private function phone(): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->owner->fresh(), [AuthController::APP]);
    }
}
