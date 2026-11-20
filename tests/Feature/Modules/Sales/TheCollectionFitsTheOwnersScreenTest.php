<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ টাকা নেওয়ার পর্দা মালিকের ১৩৬৬-এর পর্দায় নকশার মতো দুই কলামে (মালিক আর fe, ২৪ অক্টোবর ২০২৬; ছবি ১২, ১৩): মালিক নকশার পুরো
 * ডান কলাম দাগিয়ে বলেছিলেন "দাগানো পুরো অংশটাই নেই" — ১৩৬৬-এ সেটা নিচে নামত।
 *
 * ⓘ ২০ নভেম্বর ২০২৬ — মালিকের চূড়ান্ত সিদ্ধান্ত ("one money-in paper"): গ্রাহকের টাকা ঢোকে কেবল কাউন্টারের রসিদ আর হিসাবের রসিদ
 *   ভাউচারে (RCV-…)। আদায়ের ফর্ম (COL-…) আর আঁকা হয় না — পুরনো ঠিকানা রসিদের পর্দায় পাঠায়। তাই দুই-কলামের ফর্মের দাবিগুলো
 *   (`data-two-column-form`, ১৩৬৬-এর গ্রিড, আটকানো ডান কলাম, সারাংশ-পট্টি, বিল্ড করা CSS-এর শ্রেণি) ফর্মের সাথেই গেল; এখানে থাকল:
 *   পুরনো পর্দা আর আঁকা হয় না, আর অন্য পাতার মেনু আগের মতো।
 */
class TheCollectionFitsTheOwnersScreenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    public function test_the_old_collection_screen_is_no_longer_drawn_it_sends_to_the_receipt(): void
    {
        $customer = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();

        $old = $this->get(route('sales.collection.create', ['customer' => $customer->id]));
        $old->assertRedirect(route('accounts.voucher.create', ['type' => 'receipt', 'party_type' => 'customer', 'party_id' => $customer->id]));
        $this->assertStringNotContainsString('data-collection-form', (string) $old->getContent(), '⛔ পুরনো আদায়ের ফর্ম এখনো আঁকা হয়।');

        $receipt = (string) $this->followingRedirects()->get(route('sales.collection.create', ['customer' => $customer->id]))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-collection-form', $receipt, '⛔ রসিদের পর্দায় পুরনো আদায়ের ফর্ম।');
    }

    public function test_other_screens_keep_their_menu_as_it_was(): void
    {
        $page = (string) $this->get(route('customer.index'))->assertOk()->getContent();

        $this->assertDoesNotMatchRegularExpression('/<html\b[^>]*\bdata-two-column-form\b/', $page,
            '⛔ গ্রাহকের তালিকাও নিজেকে টাকার ফর্ম বলল — অন্য পাতায় মেনু রেলে যাওয়ার কথা নয়');
    }
}
