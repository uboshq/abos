<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use Tests\TestCase;

/**
 * আদেশের লেখা আজকের নিয়ম বলে — ধাপ ১৪-এর পর্দার পরীক্ষা (সমন্বয়ক, ৬ অক্টোবর ২০২৬)।
 *
 * ⛔ পুরনো লেখা তিনটা ভুল বলত: বিক্রয় চালানে আদেশের ঘরের নাম "ক্রয় আদেশ"; নিশ্চিত আদেশের অবস্থা "সংরক্ষিত"; আর আদেশ নিশ্চিত হলে
 * "মাল অর্ডারে ধরা পড়েছে" — অথচ মালিকের সিদ্ধান্তে (৬ অক্টোবর) মাল ধরা হয় চালানে, আদেশে নয় (`sales.reserve_on_order` বন্ধ)।
 *
 * দাবি — দুই ভাষাতেই: নাম "বিক্রয় আদেশ / Sales order", অবস্থা "নিশ্চিত / Confirmed", আর আদেশের বার্তায় "ধরা পড়েছে" নেই।
 */
final class TheOrderWordsTellTodaysRuleTest extends TestCase
{
    public function test_the_order_is_a_sales_order_confirmed_and_holds_no_stock(): void
    {
        $this->assertSame('বিক্রয় আদেশ', __('sales::field.order', [], 'bn'), '⛔ বিক্রয়ের ঘরে "ক্রয় আদেশ"।');
        $this->assertSame('Sales order', __('sales::field.order', [], 'en'));

        $this->assertSame('নিশ্চিত', __('sales::order_status.state.confirmed', [], 'bn'), '⛔ নিশ্চিত আদেশ এখনো "সংরক্ষিত"।');
        $this->assertSame('Confirmed', __('sales::order_status.state.confirmed', [], 'en'));

        foreach (['order_confirmed', 'order_note'] as $key) {
            $this->assertStringNotContainsString('ধরা পড়', __('sales::message.'.$key, [], 'bn'), "⛔ {$key} এখনো বলে মাল আদেশে ধরা পড়ে।");
        }
    }
}
