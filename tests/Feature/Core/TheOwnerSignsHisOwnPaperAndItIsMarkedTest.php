<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Engines\Approval\OwnerSelfApproval;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Reports\OwnerSelfApprovedReport;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\SalesOrderService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ লেখক ≠ অনুমোদনকারী কর্মীদের জন্য — মালিকের উপরে কেউ নেই (মালিক, ২০ নভেম্বর ২০২৬, "ok")।
 *
 * ⭐ দাবি:
 *   ১. মালিক নিজের অনুরোধে নিজে অনুমোদন দেন; কর্মী নিজের অনুরোধে পারেন না।
 *   ২. সুইচ `accounts.voucher_maker_checker` চালু — মালিক নিজের লেখা ভাউচার নিজে পোস্ট করেন; হিসাবরক্ষক পারেন না।
 *   ৩. মালিকের নিজে পাকা করা কাগজে স্থায়ী দাগ "নিজে লিখে নিজে অনুমোদিত (মালিক)" — পাতার মাথায়; সাধারণ কাগজে নয়।
 *   ৪. রিপোর্ট "মালিকের নিজে পাকা করা কাগজ" কেবল ঐ কাগজগুলো দেখায়, তারিখের পরিসরে।
 *   ৫. ফোনে অপেক্ষার আদেশ বদলায় না (৪০৯); সিদ্ধান্ত হয়ে গেলে দরজার বাধা ওঠে। ⓘ আদেশ সংশোধনে ফেরত যায় না (কাগজ খসড়া নয়),
 *      তাই এখানে "না" দিয়ে অপেক্ষা শেষ করা হয়।
 */
final class TheOwnerSignsHisOwnPaperAndItIsMarkedTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
        app(StandardChart::class)->install();
        app(SettingsService::class)->set(VoucherService::MAKER_CHECKER, true);
        app(SettingsService::class)->set(SalesOrderService::REPLACES_DO, true);
    }

    public function test_the_owner_approves_his_own_request_and_a_clerk_does_not(): void
    {
        $step = $this->flow($this->owner);
        $mine = $this->submittedOrder($this->owner);
        $approval = $this->pendingOf($mine);

        $this->assertTrue(app(ApprovalEngine::class)->canDecide($approval, $this->owner->fresh()),
            '⛔ মালিক নিজের অনুরোধে অনুমোদন দিতে পারলেন না।');
        app(ApprovalEngine::class)->approve($approval, $this->owner->fresh());
        $this->assertSame(Approval::APPROVED, $approval->fresh()->status);

        $clerk = $this->staff(['sales.order.view', 'sales.order.create', 'approval.decide']);
        $step->forceFill(['approver_id' => $clerk->id])->save();
        app()->forgetInstance(ApprovalEngine::class);

        $theirs = $this->submittedOrder($clerk);
        $this->assertFalse(app(ApprovalEngine::class)->canDecide($this->pendingOf($theirs), $clerk->fresh()),
            '⛔ কর্মী নিজের অনুরোধে নিজে অনুমোদন দিলেন — ছাড় কেবল মালিকের।');
    }

    public function test_the_owner_posts_his_own_voucher_with_maker_checker_on_and_an_accountant_does_not(): void
    {
        $this->post(route('accounts.voucher.store', 'journal'), $this->journal())->assertSessionHasNoErrors();
        $this->assertTrue(Voucher::query()->latest('id')->firstOrFail()->isPosted(), '⛔ মালিক নিজের লেখা ভাউচার পোস্ট করতে পারলেন না।');

        $accountant = $this->accountant();
        $this->actingAs($accountant);
        $this->post(route('accounts.voucher.store', 'journal'), $this->journal(['save_as_draft' => '1']))->assertSessionHasNoErrors();
        $draft = Voucher::query()->latest('id')->firstOrFail();
        $this->post(route('accounts.voucher.post', $draft))->assertSessionHasErrors('status');
        $this->assertTrue($draft->fresh()->isDraft(), '⛔ হিসাবরক্ষক নিজের লেখা ভাউচার নিজে পোস্ট করলেন।');
    }

    public function test_the_mark_sits_on_the_owners_own_paper_and_not_on_a_normal_one(): void
    {
        // ⓘ মালিক নিজে লিখে নিজে পোস্ট
        $this->post(route('accounts.voucher.store', 'journal'), $this->journal())->assertSessionHasNoErrors();
        $own = Voucher::query()->latest('id')->firstOrFail();

        // ⓘ হিসাবরক্ষক লেখেন, মালিক পোস্ট করেন — দুই হাত
        $accountant = $this->accountant();
        $this->actingAs($accountant);
        $this->post(route('accounts.voucher.store', 'journal'), $this->journal(['save_as_draft' => '1']))->assertSessionHasNoErrors();
        $normal = Voucher::query()->latest('id')->firstOrFail();
        $this->actingAs($this->owner);
        $this->post(route('accounts.voucher.post', $normal))->assertSessionHasNoErrors();
        $this->assertTrue($normal->fresh()->isPosted(), 'প্রস্তুতি — দ্বিতীয় হাতের পোস্ট হলো না');

        $marks = app(OwnerSelfApproval::class);
        $this->assertTrue($marks->ofPaper($own->fresh()), '⛔ মালিকের নিজে পাকা করা ভাউচারে দাগ নেই।');
        $this->assertFalse($marks->ofPaper($normal->fresh()), '⛔ দুই হাতের ভাউচারে "নিজে লিখে নিজে অনুমোদিত" দাগ।');

        $this->get(route('accounts.voucher.show', $own))->assertOk()
            ->assertSee('data-owner-self-mark', false)->assertSee(__('core.owner_self.mark'));
        $this->get(route('accounts.voucher.show', $normal))->assertOk()
            ->assertDontSee('data-owner-self-mark', false);

        // ⓘ সইয়ের পথ — মালিকের নিজের অনুরোধ, নিজের "হ্যাঁ"
        $this->flow($this->owner);
        $order = $this->submittedOrder($this->owner);
        $approval = $this->pendingOf($order);
        app(ApprovalEngine::class)->approve($approval, $this->owner->fresh());
        $this->assertTrue($marks->ofApproval($approval->fresh()), '⛔ মালিকের নিজের অনুরোধে নিজের সই — দাগ নেই।');
        $this->assertTrue($marks->ofPaper($order->fresh()));
    }

    public function test_the_report_lists_only_the_owners_own_papers_in_the_range(): void
    {
        $this->post(route('accounts.voucher.store', 'journal'), $this->journal())->assertSessionHasNoErrors();
        $own = Voucher::query()->latest('id')->firstOrFail();

        $accountant = $this->accountant();
        $this->actingAs($accountant);
        $this->post(route('accounts.voucher.store', 'journal'), $this->journal(['save_as_draft' => '1']))->assertSessionHasNoErrors();
        $normal = Voucher::query()->latest('id')->firstOrFail();
        $this->actingAs($this->owner);
        $this->post(route('accounts.voucher.post', $normal))->assertSessionHasNoErrors();

        $today = now()->toDateString();
        $rows = app(ReportEngine::class)->run(OwnerSelfApprovedReport::KEY, ['from' => now()->startOfMonth()->toDateString(), 'to' => $today], 1, 200)->rows;
        $numbers = array_column($rows, 'document_no');

        $this->assertContains($own->document_no, $numbers, '⛔ মালিকের নিজে পোস্ট করা ভাউচার রিপোর্টে নেই।');
        $this->assertNotContains($normal->document_no, $numbers, '⛔ দুই হাতের ভাউচার রিপোর্টে এল।');

        $earlier = app(ReportEngine::class)->run(OwnerSelfApprovedReport::KEY, [
            'from' => now()->subMonths(2)->startOfMonth()->toDateString(),
            'to' => now()->subMonths(2)->endOfMonth()->toDateString(),
        ], 1, 200)->rows;
        $this->assertNotContains($own->document_no, array_column($earlier, 'document_no'), '⛔ পরিসরের বাইরের কাগজ রিপোর্টে এল।');

        // ⓘ দরজা — মেনুর ঠিকানায় খোলে
        $this->get(route('accounts.report.show', ['slug' => 'owner-self-approved']))->assertOk()->assertSee($own->document_no);
    }

    public function test_the_phone_cannot_change_a_waiting_order_and_the_hold_lifts_once_it_is_decided(): void
    {
        $supervisor = $this->staff(['sales.order.view', 'approval.decide']);
        $this->flow($supervisor);
        $writer = $this->staff(['sales.order.view', 'sales.order.create']);
        $order = $this->submittedOrder($writer);

        // ⛔ অপেক্ষার আদেশ — ফোনের বদলের দরজা কিছু না লিখে ৪০৯, ওয়েবের একই বার্তায়
        Sanctum::actingAs($writer, [AuthController::APP]);
        $this->putJson('/api/v1/sales/orders/'.$order->public_id, $this->body('50'))
            ->assertStatus(409)->assertJsonPath('message', __('core.approval_hold.hint'));
        $this->assertSame(0, bccomp('5', (string) $order->lines()->value('ordered_qty'), 4), '⛔ অপেক্ষার আদেশের পরিমাণ ফোন থেকে বদলাল।');

        // ⓘ সিদ্ধান্তের দরজা খোলা — সুপারভাইজার "না" বলেন; তারপর অপেক্ষা নেই, দরজার বাধাও নেই (বাকিটা সেবার নিজের নিয়ম)
        Sanctum::actingAs($supervisor, [AuthController::APP]);
        $approval = $this->pendingOf($order);
        $this->postJson('/api/v1/approvals/'.$approval->public_id.'/reject', ['remarks' => 'পরিমাণ বেশি — নতুন করে লিখুন'])->assertOk();
        $this->assertSame(Approval::REJECTED, $approval->fresh()->status);

        Sanctum::actingAs($writer, [AuthController::APP]);
        $this->assertNotSame(409, $this->putJson('/api/v1/sales/orders/'.$order->public_id, $this->body('4'))->status(),
            '⛔ অপেক্ষা শেষ, তবু দরজা "অপেক্ষায়" বলে ফেরাল।');
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    private function submittedOrder(User $writer): SalesOrder
    {
        Sanctum::actingAs($writer, [AuthController::APP]);
        $made = $this->postJson('/api/v1/sales/orders', $this->body('5', true))->assertCreated()->json();
        $this->actingAs($this->owner);

        return SalesOrder::query()->where('public_id', $made['id'])->firstOrFail();
    }

    private function pendingOf(SalesOrder $order): Approval
    {
        return Approval::query()->where('approvable_type', $order->getMorphClass())->where('approvable_id', $order->id)
            ->where('status', Approval::PENDING)->latest('id')->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function body(string $qty, bool $submit = false): array
    {
        $product = Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->firstOrFail();
        $product->forceFill(['sale_price' => '40'])->save();

        return [
            'customer' => (string) Customer::query()->firstOrFail()->public_id,
            'lines' => [['product' => (string) $product->public_id, 'qty' => $qty]],
            'submit' => $submit,
        ];
    }

    private function flow(User $approver): ApprovalFlowStep
    {
        $flow = ApprovalFlow::query()->firstOrCreate(
            ['company_id' => $this->company->id, 'code' => 'ZQ-OWN'],
            ['module' => 'sales', 'action' => SalesOrderService::APPROVAL_ACTION, 'document_type' => 'SalesOrder', 'is_active' => true],
        );
        $step = ApprovalFlowStep::query()->updateOrCreate(
            ['approval_flow_id' => $flow->id, 'level' => 1],
            ['step_name' => 'অনুমোদনকারী', 'approver_type' => 'user', 'approver_id' => $approver->id],
        );
        app()->forgetInstance(ApprovalEngine::class);

        return $step;
    }

    /** @param  list<string>  $keys */
    private function staff(array $keys): User
    {
        $user = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);

        foreach ($keys as $key) {
            CompanyContext::forCompany($this->company->id, fn () => $user->givePermissionTo(Permission::findOrCreate($key, 'web')));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    private function accountant(): User
    {
        $user = $this->staff(['accounts.report', 'accounts.voucher.create', 'accounts.voucher.update']);
        $user->forceFill(['current_branch_id' => $this->company->defaultBranch()?->id])->save();

        return $user->fresh();
    }

    /** @return array<string, mixed> */
    private function journal(array $extra = []): array
    {
        [$a, $b] = Account::query()->postable()->active()->whereNull('money_kind')->where('code', 'like', '5%')->orderBy('code')->take(2)->get()->all();

        return [
            'type' => Voucher::JOURNAL, 'trx_date' => now()->toDateString(), 'narration' => 'মালিকের নিজের সমন্বয়',
            'lines' => [['account_id' => $a->id, 'debit' => '100', 'credit' => '0'], ['account_id' => $b->id, 'debit' => '0', 'credit' => '100']],
            ...$extra,
        ];
    }
}
