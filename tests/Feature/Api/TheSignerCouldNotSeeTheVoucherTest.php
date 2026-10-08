<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Engines\Sync\SyncService;
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
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ সইয়ের আগে ভাউচারটা ফোনে — মালিক, ৭ অক্টোবর ২০২৬: "app e voucher view korte hobe approval e"।
 *
 * অনুমোদনের কার্ডের "কাগজ দেখুন" দলিল-দরজা দিয়ে খোলে, আর সেটা ভাউচারে হিসাবের রিপোর্টের চাবি চাইত — যে সইকারীর ওই চাবি নেই,
 * তিনি যা সই করবেন তা দেখতেই পেতেন না। এখন ওয়েবের অনুমোদনের পাতার নিয়মে: অপেক্ষমাণ সইয়ের সিদ্ধান্তদাতা বা অনুরোধকারী কাগজ
 * খোলেন ([[DocumentApiController::signsFor()]]); অন্য কেউ নয়; সই হয়ে গেলে আগের মতো কাগজের নিজের চাবি।
 */
final class TheSignerCouldNotSeeTheVoucherTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    public function test_the_signer_opens_the_voucher_while_it_waits_a_stranger_never_and_not_after_signing(): void
    {
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
        app(SettingsService::class)->set(VoucherService::MAKER_CHECKER, false);

        $signer = $this->person(['approval.decide']);
        $stranger = $this->person(['approval.decide']);
        $writer = $this->person(['accounts.voucher.create']);

        $flow = ApprovalFlow::create(['company_id' => $this->company->id, 'module' => 'accounts', 'action' => 'journal', 'is_active' => true]);
        ApprovalFlowStep::create(['approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => 'user', 'approver_id' => $signer->id]);

        [$a, $b] = Account::query()->postable()->active()->whereNull('money_kind')->where('code', 'like', '5%')->orderBy('code')->take(2)->get()->all();
        $this->app['auth']->forgetGuards();
        $this->actingAs($writer);
        $out = app(SyncService::class)->push($writer, 'phone-s', 'accounts', [[
            'changeId' => 'sig-1', 'entityType' => 'Voucher', 'operation' => 'CREATE',
            'payloadJson' => json_encode(['type' => Voucher::JOURNAL, 'trx_date' => now()->toDateString(), 'narration' => 'সমন্বয়',
                'lines' => [['account_id' => $a->id, 'debit' => '100', 'credit' => '0'], ['account_id' => $b->id, 'debit' => '0', 'credit' => '100']]]),
        ]]);
        $this->assertSame('APPLIED', $out[0]['status'], json_encode($out, JSON_UNESCAPED_UNICODE));
        $voucher = Voucher::query()->where('public_id', $out[0]['entityId'])->firstOrFail();
        $approval = Approval::query()->where('approvable_type', Voucher::class)->where('approvable_id', $voucher->id)->pending()->firstOrFail();
        $url = '/api/v1/documents/Voucher/'.$voucher->public_id.'/pdf';

        $this->phone($signer)->get($url)->assertOk();
        $this->phone($stranger)->get($url)->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->actingAs($signer);
        app(ApprovalEngine::class)->approve($approval, $signer);
        $this->phone($signer)->get($url)->assertForbidden();
    }

    /**
     * ⭐ সইয়ের আগে বিস্তারিত — মালিক, ৭ অক্টোবর ২০২৬: *"approval e kono kichui details dekhay na"*
     * ([[ApprovalApiController::sheet()]])। সইকারী ভাউচারের ঘর, সারি আর যোগফল পান; অন্য কেউ ৪০৩; সই হয়ে গেলে ৪০৯।
     */
    public function test_the_signer_reads_the_vouchers_lines_before_signing_and_nobody_else_does(): void
    {
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
        app(SettingsService::class)->set(VoucherService::MAKER_CHECKER, false);

        $signer = $this->person(['approval.decide']);
        $stranger = $this->person(['approval.decide']);
        $writer = $this->person(['accounts.voucher.create']);
        $flow = ApprovalFlow::create(['company_id' => $this->company->id, 'module' => 'accounts', 'action' => 'journal', 'is_active' => true]);
        ApprovalFlowStep::create(['approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => 'user', 'approver_id' => $signer->id]);

        [$a, $b] = Account::query()->postable()->active()->whereNull('money_kind')->where('code', 'like', '5%')->orderBy('code')->take(2)->get()->all();
        $this->app['auth']->forgetGuards();
        $this->actingAs($writer);
        $out = app(SyncService::class)->push($writer, 'phone-d', 'accounts', [[
            'changeId' => 'sheet-1', 'entityType' => 'Voucher', 'operation' => 'CREATE',
            'payloadJson' => json_encode(['type' => Voucher::JOURNAL, 'trx_date' => now()->toDateString(), 'narration' => 'মাসের সমন্বয়',
                'lines' => [['account_id' => $a->id, 'debit' => '750', 'credit' => '0'], ['account_id' => $b->id, 'debit' => '0', 'credit' => '750']]]),
        ]]);
        $voucher = Voucher::query()->where('public_id', $out[0]['entityId'])->firstOrFail();
        $approval = Approval::query()->where('approvable_type', Voucher::class)->where('approvable_id', $voucher->id)->pending()->firstOrFail();
        $url = '/api/v1/approvals/'.$approval->public_id.'/sheet';

        $sheet = $this->phone($signer)->getJson($url)->assertOk()->json();
        $this->assertSame([$voucher->document_no, 'Voucher'], [$sheet['documentNo'], $sheet['documentType']]);
        $this->assertContains('মাসের সমন্বয়', array_column($sheet['facts'], 'value'), '⛔ ভাউচারের বর্ণনা বিস্তারিতে নেই।');
        $this->assertSame([$a->label(), $b->label()], array_column($sheet['rows'], 'account'), '⛔ ভাউচারের সারি নেই।');
        $this->assertSame(['debit', 'credit'], array_keys($sheet['totals']));
        $this->assertContains('debit', array_column($sheet['columns'], 'key'));

        $this->phone($stranger)->getJson($url)->assertForbidden();
        $this->phone($writer)->getJson($url)->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->actingAs($signer);
        app(ApprovalEngine::class)->approve($approval, $signer);
        $this->phone($signer)->getJson($url)->assertStatus(409);
    }

    /**
     * ⛔ যে কাগজ নিজে সইয়ের পাতা দেয় না (ডেলিভারি অর্ডার), তার বিস্তারিতও আসে — মালিক, ৮ অক্টোবর ২০২৬: *"approval a
     * bistarito vew dile anagelona dekhay"*। খালি যোগফল JSON-এ `[]` হয়ে যেত, আর ফোন বস্তু চাইত — তাই "আনা গেল না"।
     * এখন যোগফল সবসময় বস্তু (`{}`), আর সারির নিজের তথ্য (পক্ষ · পয়েন্ট, কী বাবদ) ঘর হয়ে আসে।
     */
    public function test_a_paper_without_its_own_sheet_still_answers_with_an_object_of_totals_and_its_facts(): void
    {
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($owner);
        app(SettingsService::class)->set('customer.credit_limit_enabled', false);

        $signer = $this->person(['approval.decide']);
        $flow = ApprovalFlow::create(['company_id' => $this->company->id, 'module' => 'sales',
            'action' => \App\Modules\Sales\Services\DeliveryOrderService::APPROVAL_ACTION, 'is_active' => true]);
        ApprovalFlowStep::create(['approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => 'user', 'approver_id' => $signer->id]);

        $shop = \App\Modules\Customer\Models\Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();
        $product = \App\Modules\Inventory\Models\Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->firstOrFail();
        $service = app(\App\Modules\Sales\Services\DeliveryOrderService::class);
        $order = $service->submit($service->create(['customer_id' => $shop->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $product->id, 'qty' => '2']], $owner), $owner);
        $approval = Approval::query()->where('approvable_type', $order::class)->where('approvable_id', $order->id)->pending()->firstOrFail();

        $response = $this->phone($signer)->getJson('/api/v1/approvals/'.$approval->public_id.'/sheet')->assertOk();
        $this->assertStringContainsString('"totals":{}', (string) $response->getContent(), '⛔ খালি যোগফল তালিকা হয়ে গেল — ফোন পড়তে পারে না।');
        $this->assertSame((string) $order->document_no, $response->json('documentNo'));
        $this->assertContains($shop->nameWithPoint(), array_column($response->json('facts'), 'value'), '⛔ বিস্তারিতে পক্ষ নেই।');
    }

    /** @param  list<string>  $keys */
    private function person(array $keys): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->companies()->attach($this->company, ['is_active' => true]);
        $user->forceFill(['current_company_id' => $this->company->id, 'current_branch_id' => $this->company->defaultBranch()?->id])->save();
        CompanyContext::forCompany($this->company->id, fn () => $user->givePermissionTo(
            array_map(fn (string $k) => Permission::findOrCreate($k, 'web'), $keys)));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    private function phone(User $user): self
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user->fresh(), [AuthController::APP]);

        return $this;
    }
}
