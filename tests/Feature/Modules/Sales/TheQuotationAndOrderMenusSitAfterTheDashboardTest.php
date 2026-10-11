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
        // ⓘ ৪ অক্টোবর ২০২৬ থেকে উদ্ধৃতির তালিকা আসল পাতা ([[PlannedScreenController::MOVED]]) — বার সেখান থেকেই পড়া
        $html = $this->get(route('sales.quotation.index'))->assertOk()->getContent();

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
        /*
         * ⭐ ৪ অক্টোবর ২০২৬, মালিক (সমন্বয়কের পরিকল্পনা): আন্তর্জাতিক ধারার ক্রম — DO → সরাসরি বিক্রয় (কাউন্টার,
         * ডিপো যাচাই, খসড়া) → ডেলিভারি প্রসেসিং → বিল ও চালান → ট্র্যাকিং → ফেরত → দাম → POS ও শিফট।
         */
        $order = [
            // ⓘ ৩ অক্টোবর ২০২৬: আসল DO কাগজের ডেস্কে ([[DeliveryOrderDeskController]])
            'do_list' => $at(route('sales.delivery_order.index')),
            'do_pending' => $at(route('sales.delivery_order.index', ['tab' => 'pending'])),
            'do_partial' => $at(route('sales.delivery_order.index', ['tab' => 'partial'])),
            'do_back' => $at(route('sales.delivery_order.index', ['tab' => 'back'])),
            'do_history' => $at(route('sales.delivery_order.index', ['tab' => 'history'])),
            'direct' => $at(route('sales.direct.create')),
            'depot_check' => $at(route('sales.direct.depot_check')),
            'drafts' => $at(route('sales.direct.drafts')),
            // ⭐ ৪ অক্টোবর ২০২৬, মালিক: আন্তর্জাতিক মানে চালান ডেলিভারি প্রসেসিং-এর প্রথম সারি, ট্র্যাকিং তার শেষে;
            // বিলিং-এ কেবল বিলের কাগজ (ইনভয়েস, বাতিল-ইনভয়েস, যে কাগজ বেরোয়নি)
            'challans' => $at(route('sales.challan.index')),
            'transport' => $at(route('sales.transport.index')),
            'loading' => $at(route('sales.loading_sheet.index')),
            'gate_pass' => $at(route('sales.gate_pass.index')),
            'dispatch' => $at(route('sales.shipment.index')),
            'confirm' => $at(route('sales.delivery.index')),
            'tracking' => $at(route('sales.tracking.index')),
            'invoices' => $at(route('sales.invoice.index')),
            'not_printed' => $at(route('sales.print_queue.index')),
            'returns' => $at(route('sales.return.index')),
            'pricing' => $at(route('sales.price_list.index')),
            // ⓘ POS আর শিফট শেষে — কিন্তু ওদের নিজের সুইচ ডিফল্টে বন্ধ, তাই এখানে মাপা হয় না
        ];

        foreach ($order as $row => $position) {
            $this->assertNotFalse($position, "⛔ '{$row}' সারিটা মেনুতে নেই।");
        }

        $sorted = $order;
        asort($sorted);
        $this->assertSame(array_keys($order), array_keys($sorted), '⛔ মেনুর ক্রম মালিকের দেওয়া ক্রম নয়: '.json_encode($order));

        /*
         * ⭐ DO অর্ডারের ভাঁজের ভিতরে — মালিক, ৭ অক্টোবর ২০২৬ (ছবি): *"এই DO গ্রুপটা ঠিক করে দাও, এটা অর্ডারের ভিতরে দেওয়ার
         * কথা ছিল, আন্তর্জাতিক মান অনুযায়ী"*। ⓘ SAP/Dynamics-এ Sales Order আর তার Delivery Order একই "Orders" মেনুতে।
         * ⛔ আলাদা "ডেলিভারি অর্ডার (DO)" ভাঁজ আর নেই; সারিগুলো অর্ডারের পরে, সরাসরি বিক্রয়ের আগে (উপরের ক্রমের দাবি)।
         */
        $this->assertStringNotContainsString(e(__('core.menu.delivery_orders')), $html, '⛔ DO এখনো আলাদা ভাঁজে — অর্ডারের ভিতরে যাওয়ার কথা।');
        $ordersAt = strpos($html, e(__('core.menu.sales_orders')));
        $this->assertTrue($ordersAt < $order['do_list'] && $order['do_list'] < $order['direct'], '⛔ DO-র সারি অর্ডারের ভাঁজে নয়।');
        $this->assertStringNotContainsString('href="'.e(route('sales.do.index', ['tab' => 'cancelled'])).'"', $html,
            '⛔ পুরনো "DO তালিকা"-র ধাপগুলো মেনুতে ফিরে এসেছে — ওগুলো তালিকার পাতার ট্যাবে।');

        /* ⭐ দুই ভাঁজ — "সরাসরি বিক্রয়" (কাউন্টার, ডিপো যাচাই, খসড়া) আর "Billing Documents" (ইনভয়েস, চালান); ৪ অক্টোবর ২০২৬ */
        $this->assertStringContainsString(e(__('core.menu.direct_sale')), $html, '⛔ "সরাসরি বিক্রয়" ভাঁজ নেই।');
        $this->assertStringContainsString(e(__('core.menu.billing_documents')), $html, '⛔ "Billing Documents" ভাঁজ নেই।');

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

    /** ⭐ ৫ অক্টোবরের চার দর-তালিকার পুরনো ঠিকানা "তৈরি হচ্ছে" নয় — আসল তালিকায় নামে ([[PlannedScreenController::PRICE_BOOK]]) */
    public function test_the_old_pricing_addresses_land_on_the_real_price_lists(): void
    {
        foreach (PlannedScreenController::PRICE_BOOK as $screen => $target) {
            $this->assertNotContains($screen, PlannedScreenController::SCREENS, "⛔ '{$screen}' এখনো 'তৈরি হচ্ছে' তালিকায়।");
            $this->get(route('sales.planned', ['screen' => $screen]))
                ->assertRedirect(route('sales.price_book.index', ['target' => $target]));
        }
    }
}
