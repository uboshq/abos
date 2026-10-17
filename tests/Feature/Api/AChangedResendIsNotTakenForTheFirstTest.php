<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Engines\Sync\SyncService;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Models\DepositClaim;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⛔ একই চাবিতে অন্য লেখা আগেরটার সাফল্য বলে দেখানো হয় না — ফোন লাইন-ধরে নিরীক্ষা §৭, ১৫ অক্টোবর ২০২৬
 * ([[RemembersAPhoneWrite]]; [[SyncService]] `applyOne`)।
 *
 * ⓘ উত্তর না আসার পরে মানুষ অঙ্ক বদলে আবার পাঠালেন (চাবি একই, কারণ প্রথমটা হয়তো বসে গেছে)। আগে সার্ভার আগের উত্তরটাই
 * ফেরাত — পর্দা ভাবত নতুন অঙ্ক বসেছে, অথচ বসেছে আগেরটা। এখন স্পষ্ট ফেরানো, আর একই লেখা আগের মতোই আগের উত্তর পায়।
 */
final class AChangedResendIsNotTakenForTheFirstTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
    }

    public function test_a_phone_write_door_refuses_a_changed_body_under_the_same_key(): void
    {
        $shop = Customer::query()->orderBy('id')->firstOrFail();
        $sr = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id]);
        $sr->companies()->attach($this->company->id, ['is_active' => true]);
        CompanyContext::forCompany($this->company->id, fn () => $sr->givePermissionTo(Permission::findOrCreate('sales.collection.create', 'web')));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($sr->fresh(), [AuthController::APP]);
        $advice = fn (string $amount) => ['customer' => (string) $shop->public_id, 'claimed_on' => now()->toDateString(), 'amount' => $amount, 'method' => 'cash'];
        $before = DepositClaim::query()->count();

        $this->withHeader('Idempotency-Key', 'changed-key-01')->postJson('/api/v1/sales/deposit-requests', $advice('50000'))->assertCreated();
        $this->withHeader('Idempotency-Key', 'changed-key-01')->postJson('/api/v1/sales/deposit-requests', $advice('5000'))
            ->assertStatus(409)->assertJsonPath('message', __('sync.same_key_other_content'));
        // ⓘ একই লেখা — আগের উত্তর, আগের মতো
        $this->withHeader('Idempotency-Key', 'changed-key-01')->postJson('/api/v1/sales/deposit-requests', $advice('50000'))->assertCreated();

        $this->assertSame($before + 1, DepositClaim::query()->count());
    }

    public function test_a_sync_change_resent_with_other_content_is_refused_not_reported_as_done(): void
    {
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($owner);
        app(StandardChart::class)->install();
        app(SettingsService::class)->set(VoucherService::MAKER_CHECKER, false);
        [$a, $b] = Account::query()->postable()->active()->whereNull('money_kind')->where('code', 'like', '5%')->orderBy('code')->take(2)->get()->all();
        $change = fn (string $amount) => ['changeId' => 'resend-1', 'entityType' => 'Voucher', 'operation' => 'CREATE',
            'payloadJson' => json_encode(['type' => Voucher::JOURNAL, 'trx_date' => now()->toDateString(), 'narration' => 'আবার',
                'lines' => [['account_id' => $a->id, 'debit' => $amount, 'credit' => '0'], ['account_id' => $b->id, 'debit' => '0', 'credit' => $amount]]])];

        $first = app(SyncService::class)->push($owner, 'phone-resend', 'accounts', [$change('50000')]);
        $this->assertSame('APPLIED', $first[0]['status'] ?? null, json_encode($first, JSON_UNESCAPED_UNICODE));

        $changed = app(SyncService::class)->push($owner, 'phone-resend', 'accounts', [$change('5000')]);
        $this->assertSame('CONFLICT', $changed[0]['status'], '⛔ অন্য অঙ্কের পাঠানোয় আগের ৫০,০০০-এর "হয়ে গেছে" ফিরল');
        $this->assertSame(__('sync.same_key_other_content'), $changed[0]['message']);

        $same = app(SyncService::class)->push($owner, 'phone-resend', 'accounts', [$change('50000')]);
        $this->assertSame('DUPLICATE', $same[0]['status']);
    }
}
