<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\PricingRule;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\DiscountCap;
use App\Modules\Sales\Services\SalesInvoiceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * হাতের ছাড়ের কোনো ছাদ ছিল না — সারিতে আর বিলে শতাংশের সীমা ([[DiscountCap]], মালিক, ৫ অক্টোবর ২০২৬)।
 *
 * ⭐ ০ = সীমা নেই (আজকের আচরণ)। সীমার উপরে বিলটাই ফেরে, বাংলায় কারণসহ; সই চাওয়ার আগেই।
 * ⓘ দাম ১০০ × ১০ = ১,০০০ টাকার সারি; ৫০ টাকা ছাড় = ৫%, ৬০ টাকা = ৬%।
 */
final class ADiscountHadNoCeilingTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private Product $product;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->customer = Customer::query()->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->firstOrFail();
        $this->product->forceFill(['sale_price' => '100'])->save();

        $settings = app(SettingsService::class);
        $settings->set(PricingRule::POLICY, PricingRule::ALLOW);
        $settings->flush();
    }

    /** ⓘ সীমা ০ — দশ শতাংশ ছাড়ও বিলে বসে (আজকের আচরণ)। */
    public function test_with_no_cap_any_discount_is_written(): void
    {
        $this->assertNotNull($this->bill('100'));
    }

    /** ⛔ সারির সীমা ৫% — ৬% ফেরে বাংলায়, ৫% বসে। */
    public function test_a_line_above_its_cap_is_refused_and_one_at_the_cap_is_written(): void
    {
        $this->cap(DiscountCap::LINE, '5');

        $this->assertNotNull($this->bill('50'), '⛔ ঠিক সীমায় (৫%) ছাড়ও আটকাল।');

        $error = $this->refusal(fn () => $this->bill('60'));
        $this->assertSame(__('sales::validation.discount_over_line_cap', [
            'product' => $this->product->name(), 'given' => '6', 'cap' => '5',
        ], 'bn'), $error);
    }

    /** ⛔ বিলের সীমা ৫% — সারির ছাড় ৩% + মাথার ছাড় ৩০ টাকা = ৬% ফেরে; মাথার ২০ টাকায় ৫% বসে। */
    public function test_a_bill_above_its_cap_counts_line_and_header_discount(): void
    {
        $this->cap(DiscountCap::BILL, '5');

        $this->assertNotNull($this->bill('30', '20'));

        $error = $this->refusal(fn () => $this->bill('30', '30'));
        $this->assertSame(__('sales::validation.discount_over_bill_cap', ['given' => '6', 'cap' => '5'], 'bn'), $error);
    }

    /** ⛔ সীমা পরে বসলে পুরনো খসড়াও সই চাওয়ার আগেই ফেরে — কোনো সইয়ের অনুরোধ জন্মায় না। */
    public function test_the_signature_door_refuses_above_the_cap_before_asking(): void
    {
        $invoice = $this->bill('100');
        $this->cap(DiscountCap::LINE, '5');
        $before = Approval::query()->count();

        $this->refusal(fn () => app(SalesInvoiceService::class)->assertDiscountApproved($invoice->fresh()));

        $this->assertSame($before, Approval::query()->count(), '⛔ সীমার উপরের ছাড়ে সইয়ের অনুরোধ জন্মাল।');
    }

    /** ⓘ অফারের ছাড় সীমায় গোনা হয় না — নিজের ছকে সই নিয়ে আসে; কেবল মানুষের অংশ মাপা হয়। */
    public function test_the_offer_share_of_a_discount_is_not_counted(): void
    {
        $invoice = $this->bill('100');
        $invoice->lines()->update(['promotion_discount' => '60']);
        $this->cap(DiscountCap::LINE, '5');

        app(DiscountCap::class)->assertWithin($invoice->fresh());

        $invoice->lines()->update(['promotion_discount' => '0']);
        $this->refusal(fn () => app(DiscountCap::class)->assertWithin($invoice->fresh()));
    }

    /** ⭐ কাউন্টারে সীমাটা চোখের সামনে — বসানো থাকলে, না থাকলে নয়। */
    public function test_the_counter_shows_the_cap(): void
    {
        $this->get(route('sales.direct.create'))->assertOk()->assertDontSee('data-discount-cap', false);

        $this->cap(DiscountCap::LINE, '5');
        $this->cap(DiscountCap::BILL, '3');

        $this->get(route('sales.direct.create'))->assertOk()
            ->assertSee('data-discount-cap', false)
            ->assertSee(__('sales::message.discount_cap_hint', ['line' => '5', 'bill' => '3']));
    }

    private function cap(string $key, string $value): void
    {
        $settings = app(SettingsService::class);
        $settings->set($key, $value);
        $settings->flush();
    }

    private function refusal(callable $do): string
    {
        try {
            $do();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('discount_cap', $e->errors(), '⛔ অন্য কারণে ফিরল: '.json_encode($e->errors(), JSON_UNESCAPED_UNICODE));

            return (string) $e->errors()['discount_cap'][0];
        }

        $this->fail('⛔ সীমার উপরের ছাড় বিলে বসে গেল।');
    }

    private function bill(string $lineDiscount, string $billDiscount = '0'): SalesInvoice
    {
        app()->setLocale('bn');

        return app(SalesInvoiceService::class)->create(
            [
                'customer_id' => $this->customer->id,
                'warehouse_id' => $this->warehouse->id,
                'trx_date' => now()->toDateString(),
                'bill_discount' => $billDiscount,
            ],
            [['product_id' => $this->product->id, 'qty' => '10', 'rate' => '100', 'discount' => $lineDiscount]],
        );
    }
}
