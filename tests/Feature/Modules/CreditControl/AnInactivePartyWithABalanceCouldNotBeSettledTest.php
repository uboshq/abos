<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\CreditControl;

use App\Core\Services\PartyRegistry;
use App\Core\Support\CompanyContext;
use App\Modules\Customer\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ বকেয়া রেখে বন্ধ করা পক্ষের টাকা মেটানোর পথ ছিল না (Customer/Supplier নিরীক্ষা, ১৩ অক্টোবর ২০২৬; [[PickableWhileOwing]])।
 *
 * ⚠️ আদায়, পরিশোধ আর ভাউচারের বাছাই কেবল সক্রিয় পক্ষ দেখাত — ৮,০০০ বাকি রেখে বন্ধ দোকান তালিকাতেই নেই, তাই টাকা এলেও বসানো
 * যেত না। ⭐ এখন জের থাকলে আসেন, "নিষ্ক্রিয় · জের আছে" চিহ্নসহ; জের-শূন্য নিষ্ক্রিয় আসেন না; অন্য পিকারে আগের মতো কেবল সক্রিয়।
 */
final class AnInactivePartyWithABalanceCouldNotBeSettledTest extends TestCase
{
    use CreditControlKit;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    public function test_the_money_pickers_offer_an_inactive_party_that_still_owes(): void
    {
        $this->bootCreditCompany();

        $owing = $this->customer('CLOSED-OWES', '10000');
        $this->owes($owing, '8000');
        $owing->forceFill(['is_active' => false])->save();
        $quiet = $this->customer('CLOSED-QUIET', '10000');
        $quiet->forceFill(['is_active' => false])->save();

        // ⓘ নামের পাশে পয়েন্ট আর শাখা — মালিক, ১৯ অক্টোবর ২০২৬ ([[Customer::pickerName()]])

        // ⓘ ভাউচারের বাছাই
        $options = collect(app(PartyRegistry::class)->forPicker(evenInactiveWithABalance: true))
            ->firstWhere('type', Customer::drillSourceType())['options'];
        $ids = collect($options)->pluck('id')->all();
        $this->assertContains((int) $owing->id, $ids, '⛔ ভাউচারের বাছাইয়ে বকেয়া রেখে বন্ধ গ্রাহক নেই');
        $this->assertNotContains((int) $quiet->id, $ids, 'জের-শূন্য নিষ্ক্রিয় গ্রাহক এলেন');

        // ⓘ অন্য পিকারে আগের মতো কেবল সক্রিয়
        $plain = collect(collect(app(PartyRegistry::class)->forPicker())->firstWhere('type', Customer::drillSourceType())['options'])->pluck('id')->all();
        $this->assertNotContains((int) $owing->id, $plain, 'সাধারণ পিকারে নিষ্ক্রিয় গ্রাহক এলেন');

        /*
         * ⓘ টাকা নেওয়ার পর্দা। ২০ নভেম্বর ২০২৬ — মালিকের চূড়ান্ত সিদ্ধান্ত ("one money-in paper"): গ্রাহকের টাকা ঢোকে কেবল রসিদ
         *   ভাউচারে; আদায়ের পর্দা আর আঁকা হয় না (পুরনো ঠিকানা রসিদে পাঠায়)। তাই আগের "আদায়ের পর্দায় চিহ্নসহ নাম" দাবির জায়গায়:
         *   রসিদের পর্দা নিজের পক্ষের তালিকায় বকেয়া রেখে বন্ধ গ্রাহককে দেয়, জের-শূন্যকে নয়।
         */
        $this->get(route('sales.collection.create'))->assertRedirect(route('accounts.voucher.create', ['type' => 'receipt']));
        $parties = $this->get(route('accounts.voucher.create', ['type' => 'receipt']))->assertOk()->viewData('parties');
        $onScreen = collect(collect($parties)->firstWhere('type', Customer::drillSourceType())['options'] ?? [])->pluck('id')->all();
        $this->assertContains((int) $owing->id, $onScreen, '⛔ রসিদের পর্দায় বকেয়া রেখে বন্ধ গ্রাহক নেই — টাকা এলেও বসানো যায় না');
        $this->assertNotContains((int) $quiet->id, $onScreen, 'রসিদের পর্দায় জের-শূন্য নিষ্ক্রিয় গ্রাহক এলেন');
    }
}
