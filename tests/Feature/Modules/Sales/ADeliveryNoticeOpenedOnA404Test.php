<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Notification;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Services\DeliveryChallanService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⛔ মালিকের অভিযোগ (২৩ অক্টোবর ২০২৬): "নোটিফিকেশনে ক্লিক করলে দেখা যায় না" — পর্দায় "৪০৪ — এই ঠিকানায় কিছু নেই"।
 *
 * <p>লাইভে গত ৭ দিনে ADI-তে ২২১টা `sales.delivery_stage`, ঠিকানা `/sales/tracking/challan/{uuid}`। নোটিফিকেশন খোলার দরজা
 * সারিটা পড়া করে চালানের গল্পে পাঠাত, কিন্তু গল্প চালানটা খুঁজত শাখার দেয়াল দিয়ে ([[ScopedToUserBranch]]) — হেডারে এক শাখা বাছা
 * থাকলে কেবল সেই শাখা। তাই মালিক হেডারে MMS-এ থাকলে NTK-র চালানের নোটিফিকেশন ৪০৪ দিত। এখন খোঁজা নাগাল ধরে
 * ([[SaleTrackingController::inReach()]], ভাউচারের [[OpensInReach]]-এর একই নিয়ম): মালিক সব শাখা, শাখায় সীমিত মানুষ নিজের শাখা।
 */
final class ADeliveryNoticeOpenedOnA404Test extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Branch $mine;

    private Branch $theirs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
        Customer::query()->firstOrFail()->forceFill(['credit_limit' => '100000'])->save();

        $this->mine = Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'MMS')->firstOrFail();
        $this->theirs = Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'NTK')->firstOrFail();
    }

    /** ⭐ লাইভের ঘটনা হুবহু: মালিক হেডারে MMS বেছেছেন, চালান NTK-র — নোটিফিকেশন খুললে ২০০ আর ঠিক সেই চালানের গল্প, সারিটা পড়া */
    public function test_the_owner_viewing_one_branch_opens_the_delivery_notice_of_another(): void
    {
        $challan = $this->challanIn($this->theirs);
        $url = route('sales.tracking.show', ['challan', $challan->public_id]);
        $note = Notification::query()->create([
            'company_id' => $this->company->id, 'user_id' => $this->owner->id, 'type' => 'sales.delivery_stage',
            'title' => 'DO '.$challan->document_no, 'url' => $url, 'branch_id' => $this->theirs->id, 'module' => 'sales',
        ]);
        $this->pinTo($this->owner, $this->mine);

        $this->actingAs($this->owner->fresh());
        $this->get(route('notifications.open', $note))->assertRedirect($url);
        $this->assertNotNull($note->fresh()->read_at, '⛔ নোটিফিকেশন খুলেও পড়া হিসেবে চিহ্ন পড়েনি');

        $page = $this->get($url);
        $this->assertSame(200, $page->status(), '⛔ হেডারে অন্য শাখা বাছা মালিক নোটিফিকেশনের চালানটা খুলতে পারলেন না (লাইভের ৪০৪)');
        $page->assertSee((string) $challan->document_no);

        // ⓘ ফোনের একই গল্প — একই নাগাল
        Sanctum::actingAs($this->owner->fresh(), [AuthController::APP]);
        $this->assertSame(200, $this->getJson('/api/v1/sales/tracking/challan/'.$challan->public_id)->status(),
            '⛔ ফোনেও হেডারে অন্য শাখা বাছা মালিক চালানের গল্প পেলেন না');
    }

    /** ⛔ নাগাল আগের মতোই: MMS-এ সীমিত কেরানি নিজের শাখার চালান খোলেন, NTK-র চালানে ৪০৪ */
    public function test_a_clerk_limited_to_one_branch_opens_only_its_own_challans(): void
    {
        $own = $this->challanIn($this->mine);
        $other = $this->challanIn($this->theirs);

        $clerk = User::factory()->create(['current_company_id' => $this->company->id, 'current_branch_id' => $this->mine->id, 'is_active' => true]);
        $clerk->companies()->attach($this->company->id, ['is_active' => true]);
        CompanyContext::forCompany($this->company->id, fn () => $clerk->givePermissionTo(Permission::findOrCreate('sales.delivery.view', 'web')));
        UserDataScope::query()->create(['company_id' => $this->company->id, 'user_id' => $clerk->id,
            'scope_type' => UserDataScope::BRANCH, 'scope_id' => $this->mine->id]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        app(DataScope::class)->forget();

        $this->actingAs($clerk->fresh());
        $this->assertSame(200, $this->get(route('sales.tracking.show', ['challan', $own->public_id]))->status(),
            'প্রস্তুতিটাই ভুল — কেরানি নিজের শাখার চালানই খুলতে পারলেন না');
        $this->assertSame(404, $this->get(route('sales.tracking.show', ['challan', $other->public_id]))->status(),
            '⛔ শাখায় সীমিত কেরানি অন্য শাখার চালানের গল্প খুলে ফেললেন — নাগাল ভাঙল');
    }

    private function challanIn(Branch $branch): DeliveryChallan
    {
        $service = app(DeliveryChallanService::class);
        $challan = $service->confirm($service->create([
            'customer_id' => Customer::query()->firstOrFail()->id,
            'warehouse_id' => Warehouse::query()->where('is_default', true)->firstOrFail()->id,
            'trx_date' => now()->toDateString(),
            'own_transport' => true,
        ], [['product_id' => Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->firstOrFail()->id, 'delivered_qty' => '1', 'rate' => '10']]));

        // ⓘ কেবল চালানের শাখা — গল্প খোঁজার নিয়মটাই পরখের বিষয়, মাল বা খাতা নয়
        DB::table('sal_challans')->where('id', $challan->id)->update(['branch_id' => $branch->id]);

        return DeliveryChallan::acrossBranches()->findOrFail($challan->id);
    }

    private function pinTo(User $user, Branch $branch): void
    {
        $user->forceFill(['view_all_branches' => false, 'current_branch_id' => $branch->id])->save();
        app(DataScope::class)->forget();
    }
}
