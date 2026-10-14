<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\SalesInvoice;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ "এখনই নিয়ে যাবেন" বিক্রি চালানের সই পেলেই শেষ — গেট পাসের সুইচ চালু থাকলেও (হিসাবের নিরীক্ষা, ১৩ অক্টোবর ২০২৬)।
 *
 * ⛔ আগে: সুইচ `sales.invoice_at_goods_issue` চালু থাকলে খসড়া চালান জন্মাত "গেট পাসে বেরোবে" হয়ে, আর শেষ করার সময়
 * ([[DirectSaleService::finishHeld()]]) "এখনই নিয়ে যাবেন" দেখে ঘরটা উল্টাত — **সইয়ের পরে**। চালানের ছাপ বদলে সই আর ঢাকত
 * না, নতুন অনুরোধ লেনদেনের ভিতরে জন্মে গড়িয়ে মুছত, আর সই-হওয়া বিক্রি চিরকাল "অপেক্ষায়" থাকত।
 */
final class ATakenNowSaleFinishesOnItsChallanSignatureTest extends TestCase
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
        Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail()->forceFill(['credit_limit' => '1000000'])->save();

        $this->signer = User::factory()->create(['is_active' => true, 'current_company_id' => $company->id]);
        $this->signer->companies()->attach($company->id, ['is_active' => true]);

        $flow = ApprovalFlow::query()->create(['company_id' => $company->id, 'module' => 'sales', 'action' => 'challan',
            'document_type' => '', 'threshold_amount' => null, 'is_active' => true]);
        ApprovalFlowStep::query()->create(['approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => 'user', 'approver_id' => $this->signer->id]);

        app(SettingsService::class)->set('sales.invoice_at_goods_issue', true);
    }

    public function test_the_challan_signature_finishes_a_taken_now_sale(): void
    {
        $this->post(route('sales.direct.store'), [
            'customer_id' => Customer::query()->where('name_en', 'Rahim Traders')->value('id'),
            'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
            'trx_date' => now()->toDateString(),
            'payment_term' => 'credit',
            'vehicle_owner' => 'customer',
            'delivery_mode' => 'take_now',
            'lines' => [['product_id' => Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->value('id'), 'qty' => '2', 'rate' => '10', 'free_qty' => '0']],
        ])->assertSessionHasNoErrors();

        $invoice = SalesInvoice::query()->latest('id')->firstOrFail();
        $challan = DeliveryChallan::query()->latest('id')->firstOrFail();
        $this->assertSame(DocumentStatus::DRAFT, $invoice->status, 'প্রস্তুতি — চালানের সইয়ের অপেক্ষা');
        $this->assertFalse((bool) $challan->issue_at_gate, '⛔ "এখনই নিয়ে যাবেন" চালান সইয়ের আগে "গেট পাসে বেরোবে" হয়ে জন্মাল।');

        $asked = Approval::query()->where('approvable_type', DeliveryChallan::class)->where('approvable_id', $challan->id)
            ->where('status', Approval::PENDING)->firstOrFail();

        $this->actingAs($this->signer);
        app(ApprovalEngine::class)->approve($asked, $this->signer);

        $this->assertSame(DocumentStatus::CONFIRMED, $invoice->fresh()->status, '⛔ চালানের সইয়ের পরেও বিক্রি খসড়া — সই আর চালানকে ঢাকে না।');
        $this->assertSame(DocumentStatus::CONFIRMED, $challan->fresh()->status);
        $this->assertSame(0, Approval::query()->where('approvable_type', DeliveryChallan::class)->where('approvable_id', $challan->id)
            ->where('status', Approval::PENDING)->count(), '⛔ সই-হওয়া চালান আবার সই চাইল।');
    }
}
