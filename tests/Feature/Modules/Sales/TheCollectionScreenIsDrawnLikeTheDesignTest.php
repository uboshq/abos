<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Models\SalesInvoice;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * দফা ১, ২, ৩, ৪, ৫, ১০, ১১, ১৩, ১৫, ১৭, ২০ — টাকা নেওয়ার পর্দা নকশার মতো আঁকা (মালিকের নকশা ১০০, পাতা ১; ২১ অক্টোবর ২০২৬; a6)।
 *
 * ⓘ ২০ নভেম্বর ২০২৬ — মালিকের চূড়ান্ত সিদ্ধান্ত ("one money-in paper"): গ্রাহকের টাকা ঢোকে কেবল কাউন্টারের রসিদ আর হিসাবের রসিদ
 *   ভাউচারে (RCV-…)। আদায়ের ফর্ম (COL-…) আর আঁকা হয় না — পুরনো ঠিকানা রসিদের পর্দায় পাঠায়, গ্রাহক (আর বিল ধরে এলে বিল ও
 *   বকেয়া) ভরা। তাই নকশার অংশ-ক্রম, খোলা বিলের টেবিল, লাখ-কমার ঘর আর পর্দা-পাঠকের দাবিগুলো (দফা ১–২০) ফর্মের সাথেই গেল।
 *   এখানে থাকল দরজার নতুন কথা: আদায়ের চাবির মানুষও ফর্ম পান না, রসিদে যান; আর রসিদের চাবি ছাড়া সেখানে ঢোকেন না — আদায়ের
 *   পুরনো চাবি একা আর কোনো টাকা নেওয়ার ফর্ম খোলে না।
 */
final class TheCollectionScreenIsDrawnLikeTheDesignTest extends TestCase
{
    use RefreshDatabase;

    private User $maker;

    private Customer $dealer;

    private SalesInvoice $bill;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($owner);
        app(StandardChart::class)->install();

        $this->dealer = Customer::query()->create(['code' => 'SCR-1', 'name_en' => 'Karim Store', 'name_bn' => 'করিম স্টোর',
            'is_active' => true, 'branch_id' => CompanyContext::branchId()]);
        $this->bill = SalesInvoice::query()->create(['document_no' => 'INV-SCR-1', 'customer_id' => $this->dealer->id,
            'branch_id' => CompanyContext::branchId(), 'trx_date' => now()->subDays(20)->toDateString(),
            'due_on' => now()->subDays(8)->toDateString(), 'subtotal' => '30000', 'discount' => '0', 'tax' => '0', 'total' => '30000',
            'status' => DocumentStatus::CONFIRMED]);

        $this->maker = User::factory()->create(['is_active' => true, 'current_company_id' => $company->id, 'name' => 'Al-Amin']);
        $this->maker->companies()->attach($company->id, ['is_active' => true]);
        CompanyContext::forCompany((int) $company->id, fn () => $this->maker->givePermissionTo(
            array_map(fn (string $k) => \Spatie\Permission\Models\Permission::findOrCreate($k, 'web'),
                ['sales.collection.create', 'sales.collection.view'])));
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->maker = $this->maker->fresh();
    }

    public function test_the_design_screen_is_no_longer_drawn_the_address_sends_to_the_receipt(): void
    {
        $old = $this->actingAs($this->maker)->get(route('sales.collection.create', ['customer' => $this->dealer->id]));

        $old->assertRedirect(route('accounts.voucher.create', ['type' => 'receipt', 'party_type' => 'customer', 'party_id' => $this->dealer->id]));
        $old->assertSessionHas('warning', __('sales::collection_rules.now_on_receipt'));
        $this->assertStringNotContainsString('data-collection-form', (string) $old->getContent(), '⛔ পুরনো আদায়ের ফর্ম এখনো আঁকা হয়।');

        // ⓘ বিল ধরে এলে বিল আর তার বকেয়াও ভরা — বিলের পাতার "টাকা নিন"-এর একই ঠিকানা
        $this->get(route('sales.collection.create', ['sales_invoice_id' => $this->bill->id]))
            ->assertRedirect(route('accounts.voucher.create', ['type' => 'receipt', 'party_type' => 'customer', 'party_id' => $this->dealer->id,
                'against_type' => SalesInvoice::drillSourceType(), 'against_id' => $this->bill->id, 'amount' => SalesInvoice::query()->withCollected()->whereKey($this->bill->id)->firstOrFail()->dueAmount()]));
    }

    public function test_the_old_collection_key_alone_opens_no_money_form(): void
    {
        $this->assertFalse($this->maker->can('accounts.voucher.create'), 'প্রস্তুতিটাই ভুল — আদায়ের মানুষের হাতে রসিদের চাবিও আছে');

        $this->assertSame(403, $this->actingAs($this->maker)->get(route('accounts.voucher.create', ['type' => 'receipt']))->status(),
            '⛔ রসিদের চাবি ছাড়া মানুষ রসিদের পর্দা খুলে ফেললেন।');
    }
}
