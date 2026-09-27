<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Sales\Http\Controllers\PlannedScreenController;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * উদ্ধৃতি আর বিক্রয় আদেশের মেনু — ড্যাশবোর্ডের পরে, কোড পরে।
 *
 * ⭐ মালিকের নির্দেশ, ২৮ সেপ্টেম্বর ২০২৬: *"ড্যাশবোর্ড er por ei duti menu bosaw,
 * code pore korbo, age bosaw"*। ⓘ এই পরীক্ষা তিনটা কথা ধরে: দুইটা ভাঁজ বারে
 * আসে, প্রতিটা সারি খোলে আর সৎভাবে "তৈরি হচ্ছে" বলে, আর তালিকার বাইরের নাম ৪০৪।
 */
final class TheQuotationAndOrderMenusSitAfterTheDashboardTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    public function test_both_folds_sit_in_the_sales_bar_before_the_delivery_orders(): void
    {
        $html = $this->get(route('sales.planned', ['screen' => 'quotation_list']))->assertOk()->getContent();

        $quotations = strpos($html, e(__('core.menu.quotations')));
        $orders = strpos($html, e(__('core.menu.sales_orders')));
        $delivery = strpos($html, e(__('sales::menu.orders')));

        $this->assertNotFalse($quotations, '⛔ উদ্ধৃতির ভাঁজ বারে নেই।');
        $this->assertNotFalse($orders, '⛔ বিক্রয় আদেশের ভাঁজ বারে নেই।');
        $this->assertNotFalse($delivery, 'প্রস্তুতিটাই ভুল — ডেলিভারি অর্ডারের সারি নেই।');
        $this->assertTrue($quotations < $orders && $orders < $delivery,
            '⛔ ক্রম ভুল — উদ্ধৃতি, তারপর বিক্রয় আদেশ, তারপর বাকি সারি।');

        foreach (PlannedScreenController::SCREENS as $screen) {
            $this->assertStringContainsString(e(route('sales.planned', ['screen' => $screen])), $html,
                "⛔ '{$screen}' সারিটা মেনুতে নেই।");
        }
    }

    public function test_every_row_opens_and_says_it_is_being_built(): void
    {
        foreach (PlannedScreenController::SCREENS as $screen) {
            $this->get(route('sales.planned', ['screen' => $screen]))
                ->assertOk()
                ->assertSee(__('sales::planned.'.$screen))
                ->assertSee(__('sales::planned.being_built'))
                ->assertSee(__('sales::planned.about_'.$screen));
        }
    }

    public function test_a_name_off_the_list_is_not_a_screen_and_the_key_guards_the_door(): void
    {
        $this->get('/sales/planned/anything')->assertNotFound();

        $clerk = User::factory()->create(['current_company_id' => $this->company->id]);
        $clerk->companies()->attach($this->company->id, ['is_active' => true]);

        $this->actingAs($clerk)->get(route('sales.planned', ['screen' => 'order_list']))->assertForbidden();

        $clerk->givePermissionTo('sales.order.view');
        $this->actingAs($clerk->fresh())->get(route('sales.planned', ['screen' => 'order_list']))->assertOk();
    }
}
