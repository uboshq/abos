<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\SalesInvoiceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * সই যত টাকার ছাড়ে, ঠিক ততেই চলে — a4-এর অডিট, ৬ অক্টোবর ২০২৬ (সমন্বয়ক): ১০০ টাকার ছাড়ে সই নিয়ে খসড়া বদলে
 * ৫,০০০ ছাড়ে নিশ্চিত করা যেত, কারণ সইয়ের অঙ্ক মেলানো হত না।
 *
 * দাবি — একই মানুষ, একই খসড়া: সই ১০০; বদলে ৫,০০০ → আটকায়, নতুন অনুরোধ ৫,০০০-এর; আরেক খসড়ায় ১০০ রাখলে বসে।
 */
final class ASignatureCoversOnlyTheDiscountItSawTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private User $signer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->seller = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->signer = User::factory()->create(['current_company_id' => $company->id]);
        $this->signer->companies()->attach($company->id);
        $this->signer->givePermissionTo(['approval.view', 'approval.decide']);

        $flow = ApprovalFlow::query()->where('module', 'sales')->where('action', 'discount')->firstOrFail();
        $flow->update(['threshold_amount' => '0', 'is_active' => true]);
        $flow->steps()->delete();
        ApprovalFlowStep::create([
            'approval_flow_id' => $flow->id,
            'level' => 1,
            'approver_type' => ApprovalFlowStep::BY_USER,
            'approver_id' => $this->signer->id,
            'requires_all' => false,
        ]);

        // ⓘ বাকির সীমায় না আটকায় — প্রশ্নটা কেবল সইয়ের
        Customer::query()->orderBy('id')->firstOrFail()->forceFill(['credit_limit' => '10000000'])->save();

        $this->actingAs($this->seller);
    }

    public function test_a_signature_for_100_does_not_carry_a_discount_of_5000(): void
    {
        $service = app(SalesInvoiceService::class);

        $raised = $this->signed($this->draft('100'));
        $raised = $service->update($raised, $this->header(), [$this->line('5000')]);
        $this->assertSame('5000.0000', $service->discountAwaitingSignature($raised->fresh()), 'প্রস্তুতিটাই ভুল — ছাড় ৫,০০০ হয়নি।');

        try {
            $service->confirm($raised->fresh());
            $this->fail('⛔ ১০০ টাকার সইয়ে ৫,০০০ টাকার ছাড় খাতায় বসল।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('discount', $e->errors());
        }
        $this->assertSame(DocumentStatus::DRAFT, $raised->fresh()->status);

        $fresh = Approval::query()->where('approvable_id', $raised->id)->where('status', Approval::PENDING)->first();
        $this->assertNotNull($fresh, '⛔ বড় ছাড়ের জন্য নতুন সইয়ের অনুরোধ তৈরি হয়নি।');
        $this->assertSame('5000.0000', (string) $fresh->amount);

        // ⭐ সই যে অঙ্কে, সেটাই রাখলে বসে
        $kept = $this->signed($this->draft('100'));
        $this->assertSame(DocumentStatus::CONFIRMED, $service->confirm($kept->fresh())->status, '⛔ ১০০ টাকার সইয়ে ১০০ টাকার ছাড়ও আটকে গেল।');
    }

    private function signed(SalesInvoice $invoice): SalesInvoice
    {
        try {
            app(SalesInvoiceService::class)->confirm($invoice);
            $this->fail('প্রস্তুতিটাই ভুল — ছাড় সই ছাড়াই বসল।');
        } catch (ValidationException $e) {
            // ⓘ সই চাওয়া হলো — অন্য কারণে আটকালে প্রস্তুতিটাই ভুল
            $this->assertArrayHasKey('discount', $e->errors(), 'প্রস্তুতিটাই ভুল — অন্য কারণে আটকাল: '.json_encode($e->errors(), JSON_UNESCAPED_UNICODE));
        }

        $approval = Approval::query()->where('approvable_id', $invoice->id)->where('action', 'discount')->latest('id')->firstOrFail();
        $this->assertSame('100.0000', (string) $approval->amount);
        app(ApprovalEngine::class)->approve($approval, $this->signer, 'ঠিক আছে');

        return $invoice->fresh();
    }

    private function draft(string $discount): SalesInvoice
    {
        return app(SalesInvoiceService::class)->create($this->header(), [$this->line($discount)]);
    }

    /** @return array<string, mixed> */
    private function header(): array
    {
        return [
            'customer_id' => Customer::query()->orderBy('id')->value('id'),
            'warehouse_id' => Warehouse::query()->orderBy('id')->value('id'),
            'trx_date' => now()->toDateString(),
        ];
    }

    /** @return array<string, string|int> */
    private function line(string $discount): array
    {
        return ['product_id' => Product::query()->orderBy('id')->value('id'), 'qty' => '50', 'rate' => '1000', 'discount' => $discount];
    }
}
