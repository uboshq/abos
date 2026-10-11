<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesQuotation;
use App\Modules\Sales\Services\SalesQuotationService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⛔ উদ্ধৃতির ছাড় আদেশে যায় মালিকের ছাড়ের সই নিয়ে — বিলের একই নিয়ম (পুরো-ERP পুনঃঅডিট, ৯ অক্টোবর ২০২৬, বিক্রয় ৭;
 * [[SalesQuotationService::convert()]])।
 *
 * ⓘ আগে উদ্ধৃতির সারির আর মাথার ছাড় রূপান্তরে চুপচাপ আদেশে চলে যেত, কারও সই ছাড়া। এখন সই আগে; সইয়ের অঙ্কের বাইরে কিছু নয়।
 */
final class AQuotedDiscountNeedsTheOwnersSignatureTest extends TestCase
{
    use RefreshDatabase;

    private User $signer;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'sales@abos.test')->firstOrFail());

        $this->signer = User::factory()->create(['current_company_id' => $company->id]);
        $this->signer->companies()->attach($company->id, ['is_active' => true]);
        $flow = ApprovalFlow::query()->where('module', 'sales')->where('action', 'discount')->firstOrFail();
        $flow->update(['threshold_amount' => '0', 'is_active' => true]);
        $flow->steps()->delete();
        ApprovalFlowStep::create(['approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => ApprovalFlowStep::BY_USER,
            'approver_id' => $this->signer->id, 'requires_all' => false]);

        $this->product = Product::query()->orderBy('id')->firstOrFail();
    }

    public function test_a_discounted_quotation_waits_for_the_signature_and_then_converts(): void
    {
        $quotation = $this->accepted('50');

        $this->assertSame('discount', $this->refusedOn(fn () => app(SalesQuotationService::class)->convert($quotation)),
            '⛔ সই ছাড়াই উদ্ধৃতির ছাড় আদেশে গেল');
        $this->assertSame(0, SalesOrder::query()->count());

        $asked = Approval::query()->where('approvable_type', $quotation->getMorphClass())->where('approvable_id', $quotation->id)
            ->where('action', 'discount')->where('status', Approval::PENDING)->first();
        $this->assertNotNull($asked, '⛔ সইয়ের অনুরোধ বসেনি — থামার সাথে মুছে গেছে');
        $this->assertSame('50.0000', (string) $asked->amount);

        app(ApprovalEngine::class)->approve($asked, $this->signer, 'ঠিক আছে');
        $order = app(SalesQuotationService::class)->convert($quotation->fresh());
        $this->assertSame('50.0000', bcadd((string) $order->discount, '0', 4));
    }

    public function test_a_quotation_without_a_discount_converts_as_before(): void
    {
        $this->assertNotNull(app(SalesQuotationService::class)->convert($this->accepted('0')));
    }

    private function accepted(string $discount): SalesQuotation
    {
        $service = app(SalesQuotationService::class);
        $rate = bccomp((string) $this->product->sale_price, '0', 4) > 0 ? (string) $this->product->sale_price : '100';
        $draft = $service->create(['customer_id' => Customer::query()->where('name_en', 'Rahim Traders')->value('id'), 'trx_date' => now()->toDateString(),
            'valid_until' => now()->addDays(10)->toDateString(), 'header_discount' => '0'],
            [['product_id' => $this->product->id, 'qty' => '3', 'rate' => $rate, 'discount' => $discount]]);

        return $service->accept($service->markSent($service->submit($draft)));
    }

    private function refusedOn(callable $work): string
    {
        try {
            $work();
        } catch (ValidationException $e) {
            return (string) array_key_first($e->errors());
        }

        $this->fail('কাজটা আটকানোর কথা ছিল');
    }
}
