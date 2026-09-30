<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Services\DataScope;
use App\Core\Services\PermissionSyncer;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\GatePass;
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Sales\Services\DeliveryStage;
use App\Modules\Sales\Services\DeliveryStageService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * গেট পাসে শাখার দেয়াল ছিল না — চূড়ান্ত অডিট ⛔১৪, ৩০ সেপ্টেম্বর ২০২৬।
 *
 * ── ⛔ আগে ─────────────────────────────────────────────────────────────
 * গেট পাসের সারিতে `branch_id` বসত, কিন্তু মডেলে শাখার ছাঁকনি ([[ScopedToUserBranch]]) ছিল না — তার
 * চালান আর বিলে ছিল। এক শাখায় সীমাবদ্ধ কর্মী অন্য শাখার গেট পাস তালিকায় দেখতেন, খুলতেন, আর বাতিল
 * করতে পারতেন — গাড়ি, চালক, মাল সব।
 *
 * ── ⭐ এখন ─────────────────────────────────────────────────────────────
 * [[GatePass]] চালান আর বিলের মতোই শাখায় ছাঁকা। ⓘ একই মানুষ দুইবার: শাখার সীমা থাকলে অন্য শাখার গেট
 * পাস নেই (৪০৪), সীমা তুললে আছে — তাই ৪০৪-টা শাখার জন্যই। ⭐ আর মালিক (super_admin, কোনো সীমা নেই)
 * সব শাখার সবই দেখেন।
 */
final class TheGatePassCrossedTheBranchWallTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private GatePass $home;

    private GatePass $elsewhere;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->assertTrue($this->owner->hasRole(PermissionSyncer::SUPER_ADMIN_ROLE), 'দৃশ্যটাই বানানো যায়নি — মালিক super_admin নন।');
        $this->actingAs($this->owner);

        app(StandardChart::class)->install();
        app(CashTillService::class)->ensurePrimaryTill();

        $this->home = $this->passFor($this->dispatchedChallan());
        $this->elsewhere = $this->passFor($this->dispatchedChallan());

        $other = Branch::query()->where('company_id', $this->company->id)
            ->whereKeyNot((int) $this->company->defaultBranch()?->id)->orderBy('id')->firstOrFail();

        DB::table('sal_gate_passes')->where('id', $this->elsewhere->id)->update(['branch_id' => $other->id]);
        DB::table('sal_challans')->where('id', $this->elsewhere->delivery_challan_id)->update(['branch_id' => $other->id]);
    }

    /** ⛔→⭐ একই কর্মী: শাখার সীমায় অন্য শাখার গেট পাস তালিকায় নেই, খোলে না, বাতিল হয় না; সীমা তুললে খোলে। */
    public function test_a_branch_bound_clerk_cannot_reach_another_branchs_gate_pass(): void
    {
        $clerk = $this->aClerk();
        $this->boundToHome($clerk);

        $this->actingAs($clerk)->get(route('sales.gate_pass.index'))->assertOk()
            ->assertSee($this->home->document_no)
            ->assertDontSee($this->elsewhere->document_no);

        $this->actingAs($clerk)->get(route('sales.gate_pass.show', $this->elsewhere))->assertNotFound();

        $this->actingAs($clerk)->post(route('sales.gate_pass.cancel', $this->elsewhere), ['reason' => 'অন্য শাখার'])
            ->assertNotFound();

        $this->assertSame(GatePass::ISSUED, GatePass::acrossBranches()->findOrFail($this->elsewhere->id)->status,
            '⛔ অন্য শাখার কর্মী গেট পাসটা বাতিল করে ফেলেছেন।');

        // ⭐ একই কর্মী, সীমা তোলা — এবার খোলে, তাই ৪০৪-টা শাখার জন্যই ছিল
        UserDataScope::query()->where('user_id', $clerk->id)->delete();
        app()->forgetInstance(DataScope::class);

        $this->actingAs($clerk->fresh())->get(route('sales.gate_pass.show', $this->elsewhere))->assertOk();
    }

    /** ⭐ মালিক (super_admin) — দুই শাখার গেট পাসই তালিকায় আর খোলে। */
    public function test_the_owner_sees_every_branchs_gate_pass(): void
    {
        $this->actingAs($this->owner)->get(route('sales.gate_pass.index'))->assertOk()
            ->assertSee($this->home->document_no)
            ->assertSee($this->elsewhere->document_no);

        $this->actingAs($this->owner)->get(route('sales.gate_pass.show', $this->elsewhere))->assertOk();
    }

    // ── প্রস্তুতি ────────────────────────────────────────────────────────

    private function aClerk(): User
    {
        $clerk = User::factory()->create(['current_company_id' => $this->company->id]);
        $clerk->companies()->attach($this->company->id, ['is_active' => true]);
        $clerk->givePermissionTo([
            Permission::findOrCreate('sales.gate_pass.view', 'web'),
            Permission::findOrCreate('sales.gate_pass.cancel', 'web'),
        ]);

        return $clerk->fresh();
    }

    private function boundToHome(User $clerk): void
    {
        UserDataScope::query()->create([
            'company_id' => $this->company->id,
            'user_id' => $clerk->id,
            'scope_type' => UserDataScope::BRANCH,
            'scope_id' => (int) $this->company->defaultBranch()?->id,
        ]);
        app()->forgetInstance(DataScope::class);
    }

    private function dispatchedChallan(): DeliveryChallan
    {
        $challans = app(DeliveryChallanService::class);

        $challan = $challans->confirm($challans->create([
            'customer_id' => Customer::query()->where('name_en', 'Rahim Traders')->value('id'),
            'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
            'trx_date' => now()->toDateString(),
            'own_transport' => true,
        ], [['product_id' => Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->value('id'), 'delivered_qty' => '1', 'rate' => '10']]));

        app(DeliveryStageService::class)->move($challan, DeliveryStage::DISPATCHED);

        return $challan;
    }

    private function passFor(DeliveryChallan $challan): GatePass
    {
        return GatePass::query()->where('delivery_challan_id', $challan->id)->firstOrFail();
    }
}
