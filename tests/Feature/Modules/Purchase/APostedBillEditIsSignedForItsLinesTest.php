<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Engines\Approval\HeldForApproval;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Services\DirectPurchaseService;
use App\Modules\Purchase\Services\PurchaseBillService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ নিশ্চিত বিলের বদলের সই কেবল সেই বদলের — হিসাবের নিরীক্ষা, ১৩ অক্টোবর ২০২৬ ([[PurchaseBillService::updatePosted()]])।
 *
 * ⛔ আগে: ১,০০০-এর বিল "৫০ × ১০০ = ৫,০০০" করার সই পেল। ছাপ নেওয়া হত বিল যেমন ছিল (বদলের আগে), প্রস্তাবিত সারি কোথাও
 * জমা থাকত না — তাই "২৫ × ২০০ = ৫,০০০" বা অন্য যেকোনো সারিতে ৫,০০০ বানালেই পুরনো সইয়ে পার।
 */
final class APostedBillEditIsSignedForItsLinesTest extends TestCase
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
            'document_type' => '', 'threshold_amount' => '3000', 'is_active' => true]);
        ApprovalFlowStep::query()->create(['approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => 'user', 'approver_id' => $this->signer->id]);
    }

    public function test_other_lines_with_the_signed_total_ask_for_a_new_signature(): void
    {
        $bill = $this->signedEdit();

        $this->assertHeld(fn () => app(PurchaseBillService::class)->update($bill->fresh(), $this->data(), [$this->line('25', '200')], repost: true),
            '⛔ "৫০ × ১০০"-র সই "২৫ × ২০০" বসিয়ে দিল।');
        $this->assertSame(0, bccomp((string) $bill->fresh()->total, '1000', 4), '⛔ সইয়ের অপেক্ষায় থাকা অবস্থায় বিলটা বদলে গেল।');
        $this->assertSame(Approval::PENDING, app(ApprovalEngine::class)->latestFor($bill->fresh(), 'bill')?->status, '⛔ নতুন সই চাওয়া হয়নি।');
    }

    public function test_the_signed_edit_goes_through(): void
    {
        $bill = $this->signedEdit();

        $edited = app(PurchaseBillService::class)->update($bill->fresh(), $this->data(), [$this->line('50', '100')], repost: true);
        $this->assertSame(0, bccomp((string) $edited->total, '5000', 4), '⛔ সই-হওয়া বদলটাই বসল না।');
    }

    /** ১,০০০-এর নিশ্চিত বিল, "৫০ × ১০০"-তে বদলের সই হয়েছে, বিল এখনো ১,০০০ */
    private function signedEdit(): PurchaseBill
    {
        $bill = app(DirectPurchaseService::class)->complete($this->data(), [$this->line('10', '100')])['bill'];

        $this->assertHeld(fn () => app(PurchaseBillService::class)->update($bill->fresh(), $this->data(), [$this->line('50', '100')], repost: true),
            'প্রস্তুতি — সই চাওয়ার কথা');

        $asked = app(ApprovalEngine::class)->latestFor($bill->fresh(), 'bill');
        $this->assertSame(Approval::PENDING, $asked?->status);

        $this->actingAs($this->signer);
        app(ApprovalEngine::class)->approve($asked, $this->signer);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        return $bill->fresh();
    }

    private function assertHeld(callable $what, string $why): void
    {
        try {
            $what();
            $this->fail($why);
        } catch (HeldForApproval) {
        }
    }

    /** @return array<string, mixed> */
    private function data(): array
    {
        return ['supplier_id' => Supplier::query()->value('id'), 'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
            'trx_date' => now()->toDateString(), 'supplier_bill_no' => 'SIGNED-EDIT-1'];
    }

    /** @return array<string, mixed> */
    private function line(string $qty, string $rate): array
    {
        return ['product_id' => Product::query()->value('id'), 'qty' => $qty, 'rate' => $rate, 'sales_price' => $rate, 'tax' => '0'];
    }
}
