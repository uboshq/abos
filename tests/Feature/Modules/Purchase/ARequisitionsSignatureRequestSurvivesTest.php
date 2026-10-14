<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Engines\Approval\HeldForApproval;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Purchase\Models\PurchaseRequisition;
use App\Modules\Purchase\Services\PurchaseRequisitionService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ চাহিদাপত্রের সইয়ের অনুরোধ ইনবক্সে টিকে থাকে — হিসাবের নিরীক্ষা, ১৩ অক্টোবর ২০২৬ ([[PurchaseRequisitionService::approve()]])।
 *
 * ⛔ আগে সই চাওয়া হত লেনদেনের **ভিতরে**: অনুরোধ লিখে থামতেই লেনদেন ফিরে যেত, অনুরোধটাও মুছত। পর্দা বলত "সইয়ের জন্য
 * পাঠানো হয়েছে", অথচ সইকারীর ইনবক্স খালি — ছক বসানো থাকলে চাহিদাপত্র কখনোই অনুমোদন পেত না।
 */
final class ARequisitionsSignatureRequestSurvivesTest extends TestCase
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

        $this->signer = User::factory()->create(['is_active' => true, 'current_company_id' => $company->id]);
        $this->signer->companies()->attach($company->id, ['is_active' => true]);
        $flow = ApprovalFlow::query()->create(['company_id' => $company->id, 'module' => 'purchase', 'action' => 'requisition',
            'document_type' => '', 'threshold_amount' => null, 'is_active' => true]);
        ApprovalFlowStep::query()->create(['approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => 'user', 'approver_id' => $this->signer->id]);
    }

    public function test_the_request_reaches_the_inbox_and_the_signature_lets_it_through(): void
    {
        $requisition = app(PurchaseRequisitionService::class)->create(['trx_date' => now()->toDateString(), 'purpose' => 'Stock for Eid'],
            [['product_id' => Product::query()->value('id'), 'qty' => '10', 'estimated_rate' => '100']]);

        try {
            app(PurchaseRequisitionService::class)->approve($requisition->fresh());
            $this->fail('প্রস্তুতি — সই চাওয়ার কথা');
        } catch (HeldForApproval) {
        }

        $asked = app(ApprovalEngine::class)->latestFor($requisition->fresh(), 'requisition');
        $this->assertNotNull($asked, '⛔ "সইয়ের জন্য পাঠানো হয়েছে" — অথচ অনুরোধটা লেনদেনের সাথে মুছে গেছে, ইনবক্স খালি।');
        $this->assertSame(Approval::PENDING, $asked->status);
        $this->assertSame(DocumentStatus::DRAFT, $requisition->fresh()->status);

        app(ApprovalEngine::class)->approve($asked, $this->signer);

        $this->assertSame(DocumentStatus::CONFIRMED, app(PurchaseRequisitionService::class)->approve($requisition->fresh())->status,
            '⛔ সইয়ের পরেও চাহিদাপত্র অনুমোদন পেল না।');
        $this->assertInstanceOf(PurchaseRequisition::class, $requisition->fresh());
    }
}
