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
use App\Modules\Sales\Services\SalesInvoiceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⛔ ছাড়ের সই নামঞ্জুর হলে ছাড় কমিয়ে আবার সই চাওয়া যায় — পুরো-ERP পুনঃঅডিট, ৯ অক্টোবর ২০২৬ (বিক্রয় ৬;
 * [[SalesInvoiceService::assertDiscountApproved()]])।
 *
 * ⓘ সইকারী ৫,০০০ ছাড়ে "না" বললেন। বিক্রেতা ছাড় ১,০০০ করলেন — আগে বিলটা তবু "ছাড় নামঞ্জুর" বলে চিরকাল আটকে থাকত, নতুন সই চাওয়ার পথ
 * ছিল না। একই ৫,০০০ আবার এলে "না"-ই থাকে।
 */
final class ARejectedDiscountCanAskAgainWhenItChangesTest extends TestCase
{
    use RefreshDatabase;

    private User $signer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->signer = User::factory()->create(['current_company_id' => $company->id]);
        $this->signer->companies()->attach($company->id);
        $this->signer->givePermissionTo(['approval.view', 'approval.decide']);

        $flow = ApprovalFlow::query()->where('module', 'sales')->where('action', 'discount')->firstOrFail();
        $flow->update(['threshold_amount' => '0', 'is_active' => true]);
        $flow->steps()->delete();
        ApprovalFlowStep::create(['approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => ApprovalFlowStep::BY_USER,
            'approver_id' => $this->signer->id, 'requires_all' => false]);

        Customer::query()->orderBy('id')->firstOrFail()->forceFill(['credit_limit' => '10000000'])->save();
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    public function test_a_lower_discount_asks_for_a_new_signature_and_the_same_one_stays_refused(): void
    {
        $service = app(SalesInvoiceService::class);
        $bill = $service->create($this->header(), [$this->line('5000')]);

        $this->assertRefused(fn () => $service->confirm($bill->fresh()), 'প্রস্তুতি — সই চাওয়ার কথা');
        $asked = Approval::query()->where('approvable_id', $bill->id)->where('action', 'discount')->latest('id')->firstOrFail();
        app(ApprovalEngine::class)->reject($asked, $this->signer, 'এত ছাড় নয়');

        // ⓘ একই অঙ্ক — "না"-ই থাকে
        $this->assertSame(__('sales::validation.discount_rejected'), $this->assertRefused(fn () => $service->confirm($bill->fresh()), 'একই অঙ্ক'));

        // ⭐ কমানো ছাড় — নতুন সই চাওয়া হয়
        $bill = $service->update($bill->fresh(), $this->header(), [$this->line('1000')]);
        $why = $this->assertRefused(fn () => $service->confirm($bill->fresh()), 'কমানো ছাড়');
        $this->assertNotSame(__('sales::validation.discount_rejected'), $why, '⛔ ছাড় কমানোর পরেও বিলটা পুরনো "না"-তে আটকে');

        $fresh = Approval::query()->where('approvable_id', $bill->id)->where('action', 'discount')->where('status', Approval::PENDING)->first();
        $this->assertNotNull($fresh, '⛔ কমানো ছাড়ের জন্য নতুন সইয়ের অনুরোধ তৈরি হয়নি');
        $this->assertSame('1000.0000', (string) $fresh->amount);

        app(ApprovalEngine::class)->approve($fresh, $this->signer, 'ঠিক আছে');
        $this->assertSame(DocumentStatus::CONFIRMED, $service->confirm($bill->fresh())->status);
    }

    private function assertRefused(callable $what, string $why): string
    {
        try {
            $what();
            $this->fail('বিল আটকানোর কথা: '.$why);
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('discount', $e->errors(), $why.' — অন্য কারণে আটকাল: '.json_encode($e->errors(), JSON_UNESCAPED_UNICODE));

            return (string) $e->errors()['discount'][0];
        }
    }

    /** @return array<string, mixed> */
    private function header(): array
    {
        return ['customer_id' => Customer::query()->orderBy('id')->value('id'), 'warehouse_id' => Warehouse::query()->orderBy('id')->value('id'),
            'trx_date' => now()->toDateString()];
    }

    /** @return array<string, string|int> */
    private function line(string $discount): array
    {
        return ['product_id' => Product::query()->orderBy('id')->value('id'), 'qty' => '50', 'rate' => '1000', 'discount' => $discount];
    }
}
