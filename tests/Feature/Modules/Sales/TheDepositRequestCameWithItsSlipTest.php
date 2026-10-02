<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Attachment;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Models\DepositClaim;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * স্লিপসহ জমার অনুরোধ — মালিক, ১ অক্টোবর ২০২৬:
 * *"customer payment dile bank slip soho ekta request paTanor bebosta app e thakbe. web eo thakbe"*।
 *
 * ⭐ দাবি:
 *   SR ফোন থেকে স্লিপসহ পাঠান → অপেক্ষমাণ দাবি, স্লিপ বাঁধা, খাতায় কিছুই নয়;
 *   ব্যাংকে জমায় স্লিপ ছাড়া → ফেরে, দাবিও ওঠে না; `.php` নামে ছবি → ফেরে, দাবিও ওঠে না;
 *   অন্য কোম্পানির ব্যাংক খাত → ফেরে;
 *   একই মানুষ — আদায়ের চাবি ছাড়া ৪০৩, চাবি দিলে চলে; স্লিপ দেখে কেবল দাবি দেখার চাবি;
 *   দোকানি পোর্টালে স্লিপ তোলেন আর নিজেরটা দেখেন, অন্যের দাবির স্লিপে ৪০৩।
 */
final class TheDepositRequestCameWithItsSlipTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Customer $dealer;

    private Account $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        Storage::fake('local');

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->dealer = Customer::query()->firstOrFail();
        $this->dealer->forceFill(['portal_enabled' => true, 'portal_password' => 'dealer-pass-1'])->save();
        // ⓘ ডেমো কোম্পানিতে চালু ব্যাংক খাত নেই — নিজের একটা ([[AClaimAcceptedTwiceTookTheMoneyTwiceTest]]-এর মতো)
        $this->bank = Account::query()->create([
            'company_id' => $this->company->id,
            'code' => '1102-SLIPBANK',
            'name_en' => 'Slip bank',
            'name_bn' => 'স্লিপের ব্যাংক',
            'parent_id' => StandardChart::find(StandardChart::BANK)->id,
            'type' => Account::ASSET,
            'nature' => Account::DEBIT,
            'money_kind' => Account::BANK,
        ]);
        $this->assertTrue(Account::query()->ofMoneyKind(Account::BANK)->active()->whereKey($this->bank->id)->exists(),
            'প্রস্তুতিটাই ভুল — নতুন খাতটা চালু ব্যাংক হিসেবে ধরা পড়ে না।');
    }

    public function test_the_phone_sends_a_slip_and_it_waits_for_accounts(): void
    {
        $sr = $this->member(['sales.collection.create']);
        $ledgerBefore = DB::table('ledger_entries')->count();
        Sanctum::actingAs($sr, [AuthController::APP]);

        $json = $this->post('/api/v1/sales/deposit-requests', $this->form(['slip' => $this->png('slip.png')]),
            ['Accept' => 'application/json'])->assertCreated()->json();

        $claim = DepositClaim::query()->where('public_id', $json['id'])->firstOrFail();
        $this->assertSame(DepositClaim::PENDING, $claim->status);
        $this->assertTrue($json['has_slip'], '⛔ স্লিপটা দাবির সাথে বাঁধা হয়নি।');
        $this->assertSame(1, Attachment::query()->where('source_entity', DepositClaim::class)->where('source_entity_id', $claim->id)->count());
        $this->assertStringContainsString($sr->name, (string) $claim->note, 'কে পাঠালেন তা তালিকায় নেই।');
        $this->assertSame($ledgerBefore, DB::table('ledger_entries')->count(), '⛔ অনুরোধেই খাতায় টাকা উঠে গেল — গ্রহণের আগে কিছু ওঠার কথা নয়।');

        $list = $this->getJson('/api/v1/sales/deposit-requests?customer='.$this->dealer->public_id)->assertOk()->json('requests');
        $this->assertSame($json['id'], $list[0]['id']);
    }

    public function test_a_bank_deposit_without_a_slip_or_with_a_disguised_file_raises_nothing(): void
    {
        Sanctum::actingAs($this->member(['sales.collection.create']), [AuthController::APP]);

        $this->post('/api/v1/sales/deposit-requests', $this->form(), ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('slip');

        $this->post('/api/v1/sales/deposit-requests', $this->form(['slip' => $this->png('slip.php')]), ['Accept' => 'application/json'])
            ->assertStatus(422);

        $this->assertSame(0, DepositClaim::query()->count(), '⛔ স্লিপ ফিরল অথচ দাবিটা থেকে গেল।');
    }

    public function test_another_companys_bank_account_is_refused(): void
    {
        $foreign = Account::query()->withoutGlobalScopes()->where('company_id', '!=', $this->company->id)
            ->where('is_group', false)->firstOrFail();
        Sanctum::actingAs($this->member(['sales.collection.create']), [AuthController::APP]);

        $this->post('/api/v1/sales/deposit-requests', $this->form(['bank_account_id' => $foreign->id, 'slip' => $this->png('slip.png')]),
            ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('bank_account_id');
    }

    public function test_the_same_person_needs_the_collection_key_to_send_and_the_claim_key_to_see_the_slip(): void
    {
        $person = $this->member([]);
        Sanctum::actingAs($person, [AuthController::APP]);
        $this->post('/api/v1/sales/deposit-requests', $this->form(['slip' => $this->png('slip.png')]), ['Accept' => 'application/json'])
            ->assertForbidden();

        $this->grant($person, 'sales.collection.create');
        Sanctum::actingAs($person->fresh(), [AuthController::APP]);
        $id = $this->post('/api/v1/sales/deposit-requests', $this->form(['slip' => $this->png('slip.png')]), ['Accept' => 'application/json'])
            ->assertCreated()->json('id');
        $claim = DepositClaim::query()->where('public_id', $id)->firstOrFail();

        $this->actingAs($person->fresh())->get(route('sales.claim.slip', $claim))->assertForbidden();
        $this->grant($person, 'sales.claim.view');
        $this->actingAs($person->fresh())->get(route('sales.claim.slip', $claim))->assertOk();
        $this->actingAs($person->fresh())->get(route('sales.claim.index'))->assertOk()->assertSee('data-slip-link', false);
    }

    public function test_the_web_form_sends_with_the_slip(): void
    {
        $sr = $this->member(['sales.collection.create']);
        $this->actingAs($sr)->get(route('sales.claim.request.create'))->assertOk()->assertSee('data-slip-input', false);

        $this->actingAs($sr)->post(route('sales.claim.request.store'), $this->form(['customer' => (string) $this->dealer->id, 'slip' => $this->pdf('slip.pdf')]))
            ->assertSessionHasNoErrors()->assertRedirect(route('sales.claim.request.create'));
        $this->assertSame(1, DepositClaim::query()->pending()->count());
    }

    public function test_the_dealer_sends_a_slip_and_sees_only_their_own(): void
    {
        $this->actingAs($this->dealer->fresh(), 'portal')
            ->get(route('sales.portal.claim.create'))->assertOk()->assertSee('data-slip-input', false);

        $this->actingAs($this->dealer->fresh(), 'portal')->post(route('sales.portal.claim.store'), [
            'claimed_on' => now()->toDateString(), 'amount' => '2500', 'method' => 'bank',
            'reference' => 'TRX-77', 'bank_account_id' => $this->bank->id, 'slip' => $this->png('slip.png'),
        ])->assertSessionHasNoErrors()->assertRedirect();

        $claim = DepositClaim::query()->where('customer_id', $this->dealer->id)->firstOrFail();
        // ⛔ দোকানির কাজ কোনো কর্মীর নামে চড়ে না — নিরীক্ষা আর স্লিপ, দুই জায়গাতেই ([[Actor]])
        $this->assertNull(DB::table('audit_trails')->where('auditable_type', DepositClaim::class)
            ->where('auditable_id', $claim->id)->where('action', 'created')->value('user_id'),
            '⛔ দোকানির দাবি নিরীক্ষায় একজন কর্মীর নামে বসেছে।');
        $this->assertNull(Attachment::query()->where('source_entity', DepositClaim::class)
            ->where('source_entity_id', $claim->id)->value('uploaded_by'), '⛔ দোকানির স্লিপ একজন কর্মীর নামে বসেছে।');
        $this->actingAs($this->dealer->fresh(), 'portal')->get(route('sales.portal.claim.show', $claim))->assertOk()
            ->assertSee('data-slip-link', false);
        $this->actingAs($this->dealer->fresh(), 'portal')->get(route('sales.portal.claim.slip', $claim))->assertOk();

        $other = Customer::query()->whereKeyNot($this->dealer->id)->firstOrFail();
        $other->forceFill(['portal_enabled' => true, 'portal_password' => 'dealer-pass-2'])->save();
        $this->actingAs($other->fresh(), 'portal')->get(route('sales.portal.claim.slip', $claim))->assertForbidden();
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function form(array $extra = []): array
    {
        return [
            'customer' => (string) $this->dealer->public_id,
            'claimed_on' => now()->toDateString(),
            'amount' => '4820',
            'method' => 'bank',
            'reference' => 'DBBL-'.random_int(1000, 9999),
            'bank_account_id' => $this->bank->id,
            ...$extra,
        ];
    }

    /** @param  list<string>  $keys */
    private function member(array $keys): User
    {
        $user = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);
        foreach ($keys as $key) {
            $this->grant($user, $key);
        }

        return $user->fresh();
    }

    private function grant(User $user, string $key): void
    {
        CompanyContext::forCompany($this->company->id,
            fn () => $user->givePermissionTo(Permission::findOrCreate($key, 'web')));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function png(string $name): UploadedFile
    {
        $image = imagecreatetruecolor(40, 30);
        imagefill($image, 0, 0, imagecolorallocate($image, 20, 120, 200));
        $path = tempnam(sys_get_temp_dir(), 'slip');
        imagepng($image, $path);
        imagedestroy($image);

        return new UploadedFile($path, $name, 'image/png', null, true);
    }

    private function pdf(string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'slip');
        file_put_contents($path, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [] /Count 0 >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n");

        return new UploadedFile($path, $name, 'application/pdf', null, true);
    }
}
