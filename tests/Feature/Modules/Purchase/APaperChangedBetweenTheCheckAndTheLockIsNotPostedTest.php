<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Services\PurchaseBillService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⭐ সই দেখা আর তালার মাঝখানে কাগজ বদলালে কিছুই খাতায় নয় — হিসাবের নিরীক্ষা, ১৩ অক্টোবর ২০২৬
 * ([[DocumentApproval::assertUnchangedSince()]])।
 *
 * ⛔ সেবাগুলো সই দেখে লেনদেনের বাইরে, তারপর তালা দেয়। ঐ ফাঁকে আরেক জানালা থেকে খসড়া বদলালে বদলানোটাই খাতায় উঠত — সই
 * দেখা হয়েছিল আগের কাগজে। ⓘ এখানে "আরেক জানালা" বানানো হয় ঠিক তালার প্রশ্নের আগের মুহূর্তে।
 */
final class APaperChangedBetweenTheCheckAndTheLockIsNotPostedTest extends TestCase
{
    use RefreshDatabase;

    private User $signer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $this->signer = User::factory()->create(['is_active' => true, 'current_company_id' => $company->id]);
        $this->signer->companies()->attach($company->id, ['is_active' => true]);
        $flow = ApprovalFlow::query()->create(['company_id' => $company->id, 'module' => 'purchase', 'action' => 'bill',
            'document_type' => '', 'threshold_amount' => null, 'is_active' => true]);
        ApprovalFlowStep::query()->create(['approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => 'user', 'approver_id' => $this->signer->id]);
    }

    public function test_a_line_changed_just_before_the_lock_is_not_posted_on_the_signature(): void
    {
        $bill = $this->signedDraft();

        // ⓘ "আরেক জানালা": বিলের সারিতে তালার প্রশ্নটা যাওয়ার ঠিক আগে খসড়ার পরিমাণ ১০ থেকে ১০০
        $tampered = false;
        DB::beforeExecuting(function (string $query) use ($bill, &$tampered): void {
            if (! $tampered && str_contains($query, 'pur_bills') && str_contains(strtolower($query), 'for update')) {
                $tampered = true;
                DB::table('pur_bill_lines')->where('purchase_bill_id', $bill->id)->update(['qty' => '100']);
            }
        });

        try {
            app(PurchaseBillService::class)->confirm($bill->fresh());
            $this->fail('⛔ সইয়ের পরে, তালার ঠিক আগে বদলানো বিল খাতায় উঠল।');
        } catch (ValidationException $e) {
            $this->assertSame(__('core.approval.changed_while_posting'), $e->errors()['status'][0] ?? null,
                json_encode($e->errors(), JSON_UNESCAPED_UNICODE));
        }

        $this->assertTrue($tampered, 'প্রস্তুতি — "আরেক জানালা" চলেইনি');
        $this->assertSame(DocumentStatus::DRAFT, $bill->fresh()->status, '⛔ বদলানো বিল পাকা হলো।');
    }

    public function test_an_untouched_signed_bill_still_confirms(): void
    {
        $bill = $this->signedDraft();

        $this->assertSame(DocumentStatus::CONFIRMED, app(PurchaseBillService::class)->confirm($bill->fresh())->status);
    }

    private function signedDraft(): PurchaseBill
    {
        $bill = app(PurchaseBillService::class)->create(
            ['supplier_id' => Supplier::query()->value('id'), 'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
                'trx_date' => now()->toDateString(), 'supplier_bill_no' => 'RACE-1'],
            [['product_id' => Product::query()->value('id'), 'qty' => '10', 'rate' => '100', 'sales_price' => '100']],
        );

        try {
            app(PurchaseBillService::class)->confirm($bill->fresh());
            $this->fail('প্রস্তুতি — সই চাওয়ার কথা');
        } catch (ValidationException) {
        }

        $asked = app(ApprovalEngine::class)->latestFor($bill->fresh(), 'bill');
        $this->assertSame(Approval::PENDING, $asked?->status);

        // ⓘ শেষ সইয়ে বিল নিজেই পাকা হত ([[ConfirmThePurchasePaperOnTheLastSignature]]) — এখানে হাতে চাপার পথটা মাপা হয়, তাই
        //   সইয়ের মুহূর্তে শ্রোতা চুপ
        \Illuminate\Support\Facades\Event::fake([\App\Core\Events\ApprovalDecided::class]);
        app(ApprovalEngine::class)->approve($asked, $this->signer);

        $this->assertSame(DocumentStatus::DRAFT, $bill->fresh()->status);

        return $bill->fresh();
    }
}
