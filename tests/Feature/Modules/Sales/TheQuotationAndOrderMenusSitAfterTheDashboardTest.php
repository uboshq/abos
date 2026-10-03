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
        $delivery = strpos($html, e(__('core.menu.delivery_processing')));

        $this->assertNotFalse($quotations, '⛔ উদ্ধৃতির ভাঁজ বারে নেই।');
        $this->assertNotFalse($orders, '⛔ বিক্রয় আদেশের ভাঁজ বারে নেই।');
        $this->assertNotFalse($delivery, 'প্রস্তুতিটাই ভুল — ডেলিভারি অর্ডারের সারি নেই।');
        $this->assertTrue($quotations < $orders && $orders < $delivery,
            '⛔ ক্রম ভুল — উদ্ধৃতি, তারপর বিক্রয় আদেশ, তারপর বাকি সারি।');

        /*
         * ⭐ বাকি ক্রম — মালিকের নির্দেশ, ১ অক্টোবর ২০২৬: অর্ডার → নতুন DO → DO তালিকা (ভাঁজ) → সরাসরি বিক্রয় →
         * ইনভয়েস তালিকা → ডেলিভারি চালান → ডেলিভারি প্রসেসিং → মূল্য নির্ধারণ → বিক্রয় ফেরত → যে কাগজ বেরোয়নি।
         * ⓘ রুট ধরে মাপা — ভাঁজের নাম পাতার অন্য জায়গায় আঁকা হয়, তাই নামের অবস্থান ক্রম বলে না।
         */
        // ⓘ পুরো লিংক, শেষের উদ্ধৃতিসহ — `/sales/direct` নিজেই `/sales/direct/drafts`-এর শুরু, তাই কেবল ঠিকানা খুঁজলে খসড়ার লিংক মিলত
        $at = fn (string $url) => strpos($html, 'href="'.e($url).'"');
        /*
         * ⭐ ২ অক্টোবর ২০২৬, মালিক: "নতুন DO"-র জায়গায় "Delivery Order (DO)" ভাঁজ — অর্ডার (DO) তালিকা, অপেক্ষমাণ,
         * আংশিক, ব্যাক, ইতিহাস; আর "DO তালিকা"-র জায়গায় "ডেলিভারি ট্র্যাকিং"।
         */
        $order = [
            // ⓘ ৩ অক্টোবর ২০২৬: আসল DO কাগজের ডেস্কে ([[DeliveryOrderDeskController]])
            'do_list' => $at(route('sales.delivery_order.index')),
            'do_pending' => $at(route('sales.delivery_order.index', ['tab' => 'pending'])),
            'do_partial' => $at(route('sales.delivery_order.index', ['tab' => 'partial'])),
            'do_back' => $at(route('sales.delivery_order.index', ['tab' => 'back'])),
            'do_history' => $at(route('sales.delivery_order.index', ['tab' => 'history'])),
            'tracking' => $at(route('sales.tracking.index')),
            'direct' => $at(route('sales.direct.create')),
            'invoices' => $at(route('sales.invoice.index')),
            'challans' => $at(route('sales.challan.index')),
            'dispatch' => $at(route('sales.shipment.index')),
            'pricing' => $at(route('sales.price_list.index')),
            'returns' => $at(route('sales.return.index')),
            'not_printed' => $at(route('sales.print_queue.index')),
        ];

        foreach ($order as $row => $position) {
            $this->assertNotFalse($position, "⛔ '{$row}' সারিটা মেনুতে নেই।");
        }

        $sorted = $order;
        asort($sorted);
        $this->assertSame(array_keys($order), array_keys($sorted), '⛔ মেনুর ক্রম মালিকের দেওয়া ক্রম নয়: '.json_encode($order));

        $this->assertStringContainsString(e(__('core.menu.delivery_orders')), $html, '⛔ "Delivery Order (DO)" ভাঁজ নেই।');
        $this->assertStringNotContainsString('href="'.e(route('sales.do.index', ['tab' => 'cancelled'])).'"', $html,
            '⛔ পুরনো "DO তালিকা"-র ধাপগুলো মেনুতে ফিরে এসেছে — ওগুলো তালিকার পাতার ট্যাবে।');

        /* ⭐ "Billing Documents" ভাঁজ — খসড়া, ইনভয়েস, চালান, এই ক্রমে (মালিক, ২ অক্টোবর ২০২৬) */
        $this->assertStringContainsString(e(__('core.menu.billing_documents')), $html, '⛔ "Billing Documents" ভাঁজ নেই।');
        $drafts = $at(route('sales.direct.drafts'));
        $this->assertNotFalse($drafts, '⛔ খসড়া তালিকা মেনুতে নেই।');
        $this->assertTrue($order['direct'] < $drafts && $drafts < $order['invoices'],
            '⛔ খসড়া তালিকা সরাসরি বিক্রয়ের পরে আর ইনভয়েস তালিকার আগে নয়।');

        /* ⓘ নতুন DO এখন তালিকার পাতার বোতামে, মেনুতে নয় — তাই সেটা নিচে আলাদা মাপা */
        foreach (PlannedScreenController::SCREENS as $screen) {
            $this->assertStringContainsString(e(route('sales.planned', ['screen' => $screen])), $html,
                "⛔ '{$screen}' সারিটা মেনুতে নেই।");
        }
    }

    /** ⭐ "DO Create & List" — নতুন DO-র বোতাম DO তালিকার পাতার ভিতরে (মালিক, ২ অক্টোবর ২০২৬) */
    public function test_the_do_list_carries_the_new_do_button(): void
    {
        $this->get(route('sales.delivery_order.index'))->assertOk()
            ->assertSee('href="'.e(route('sales.delivery_order.create')).'"', false)
            ->assertSee(__('sales::delivery_order.new'));
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

        $this->actingAs($clerk)->get(route('sales.planned', ['screen' => 'order_pending']))->assertForbidden();

        $clerk->givePermissionTo('sales.order.view');
        // ⓘ ১ অক্টোবর ২০২৬ থেকে ঠিকানাটা অর্ডার তালিকার "অপেক্ষমাণ" ট্যাবে নামে ([[PlannedScreenController::FOLDED]])
        $this->actingAs($clerk->fresh())->get(route('sales.planned', ['screen' => 'order_pending']))
            ->assertRedirect(route('sales.order.index', ['tab' => 'pending']));
    }
}
