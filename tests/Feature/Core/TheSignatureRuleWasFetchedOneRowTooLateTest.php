<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Engines\Approval\HeldForApproval;
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
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Services\DeliveryChallanService;
use Database\Seeders\DemoSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * সইয়ের ছকের শর্তগুলো একটা একটা করে, দেরিতে টানা হত — লোকালে সরাসরি ৫০০ (বিক্রয়ের হাঁটা, ২৯ সেপ্টেম্বর ২০২৬)।
 *
 * ── ⛔ আগে ─────────────────────────────────────────────────────────────
 * [[ApprovalEngine::flows()]] ছকের সাথে কেবল `steps` আগে থেকে টানত, আর [[ApprovalFlow::catches()]]
 * প্রতিটা অনুরোধে `conditions` আলাদা প্রশ্নে টানত (lazy load)। লোকালে `preventLazyLoading`
 * চালু থাকে ([[AppServiceProvider]]), তাই সইয়ের ছক বসানো কোম্পানিতে চালান নিশ্চিত আর
 * কাউন্টারের বিক্রি দুটোই ৫০০ দিত। লাইভে শুধু বাড়তি প্রশ্ন চলত, কোনো ভুল দেখা যেত না।
 * ⓘ টেস্টগুলো চলে `testing` পরিবেশে, যেখানে কড়াকড়ি বন্ধ — তাই কোনো টেস্ট লাল হয়নি।
 *
 * ── ⭐ এখন ─────────────────────────────────────────────────────────────
 * ছকগুলো `steps` আর `conditions` একসাথে নিয়ে আসে। দাবিটা কড়াকড়ি চালু রেখেই চলে।
 */
final class TheSignatureRuleWasFetchedOneRowTooLateTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        // ⛔ সুইচটা গোটা প্রক্রিয়ার — ফিরিয়ে না দিলে পরের টেস্টগুলোও কড়া চলত
        Model::preventLazyLoading(false);

        parent::tearDown();
    }

    /** ⭐ কড়াকড়ি চালু রেখে: সইয়ের ছক বসানো কোম্পানিতে চালান সইয়ে যায় — ৫০০ নয়। */
    public function test_a_challan_goes_for_signature_with_lazy_loading_forbidden(): void
    {
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($owner);

        $flow = ApprovalFlow::query()->create([
            'company_id' => $company->id, 'module' => 'sales', 'action' => 'challan',
            'document_type' => '', 'threshold_amount' => null, 'is_active' => true,
        ]);
        ApprovalFlowStep::query()->create([
            'approval_flow_id' => $flow->id, 'level' => 1,
            'approver_type' => ApprovalFlowStep::BY_USER, 'approver_id' => $owner->id,
        ]);
        $this->app->forgetInstance(ApprovalEngine::class);
        $this->app->forgetScopedInstances();

        $challans = app(DeliveryChallanService::class);
        $challan = $challans->create([
            'customer_id' => Customer::query()->value('id'),
            'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
            'trx_date' => now()->toDateString(),
            'own_transport' => true,
        ], [[
            'product_id' => Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->value('id'),
            'delivered_qty' => '1',
            'rate' => '10',
        ]]);

        Model::preventLazyLoading(true);

        try {
            $challans->confirm($challan);
            $this->fail('⛔ সইয়ের ছক থাকা সত্ত্বেও চালান সই ছাড়াই পাকা হয়ে গেছে।');
        } catch (HeldForApproval) {
            // ⭐ প্রত্যাশিত — সইয়ের জন্য গেছে
        }

        Model::preventLazyLoading(false);

        $this->assertSame(DocumentStatus::DRAFT, $challan->fresh()->status);
        $this->assertSame(1, Approval::query()
            ->where('approvable_type', $challan->getMorphClass())
            ->where('approvable_id', $challan->id)
            ->count(), 'সইয়ের অনুরোধ তৈরি হয়নি।');
    }
}
