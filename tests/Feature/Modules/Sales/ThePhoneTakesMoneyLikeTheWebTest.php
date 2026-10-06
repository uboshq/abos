<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Engines\Sync\SyncService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\SyncChange;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Models\Collection;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ⭐ ফোনে টাকা আদায় — মালিক, ৭ অক্টোবর ২০২৬: *"এখন অ্যাপে পেমেন্ট অপশন চালু করো"* ([[CollectionSync]],
 * [[CollectionApiController::setup()]])।
 *
 * ⭐ কেবল অফিসের লোক — মালিক, ৭ অক্টোবর ২০২৬: *"এটা কেবল অফিসের লোকদের জন্য থাকবে, আর ফিল্ডের জন্য থাকবে payment
 * request"* — মাঠের টাকা যায় স্লিপসহ জমার অনুরোধে। দরজা খাতায় টাকা তোলার চাবিতে ([[CollectionSync::officeMayCollect()]])।
 *
 * দাবি:
 *   · টাকার খাত ওয়েবের ফর্মের একই তালিকা, ধরনসহ; /me বলে ফোনে আদায় চলে কি না
 *   · SR (আদায়ের চাবি আছে, অফিসের নেই) — ফোনে ঘর নেই, খাতের দরজা ৪০৩, পাঠানো আদায় ফেরত, কিছুই বসে না
 *   · ফোন থেকে খাত, মাধ্যম, লেনদেন-নম্বর আর "এখনই নিশ্চিত" — ওয়েবের একই সেবায় পাকা: বাছা খাতে টাকা, গ্রাহকের প্রাপ্য কমে
 *   · একই আদায় দুবার পৌঁছালে একটাই
 *   · "1e5", ভবিষ্যতের তারিখ, অচেনা খাত — কারণসহ ফেরত, ৫০০ নয়; একই ব্যাচের ভালো আদায়টা তবু বসে
 *   · "নিশ্চিত" না চাইলে খসড়া — ওয়েবের মতো
 */
final class ThePhoneTakesMoneyLikeTheWebTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Customer $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
        $this->shop = Customer::query()->orderBy('id')->firstOrFail();
    }

    public function test_the_phone_gets_the_webs_money_accounts_and_knows_it_may_collect(): void
    {
        $this->phone($this->owner);
        $accounts = $this->getJson('/api/v1/sales/collections/setup')->assertOk()->json('accounts');
        $this->assertNotEmpty($accounts, 'টাকার কোনো খাত আসেনি।');
        foreach ($accounts as $a) {
            $this->assertContains($a['kind'], ['cash', 'bank', 'mfs']);
        }
        $this->assertTrue($this->getJson('/api/v1/me')->assertOk()->json('mayCollect'));

        $this->phone($this->accountant());
        $this->assertTrue($this->getJson('/api/v1/me')->assertOk()->json('mayCollect'), 'অফিসের হিসাবরক্ষকের ফোনে আদায় নেই।');
    }

    public function test_a_field_salesman_has_no_collection_on_the_phone(): void
    {
        // ⓘ SR — জমার অনুরোধের জন্য আদায়ের চাবি আছে, খাতায় টাকা তোলার নেই
        $sr = $this->person(['sales.collection.create', 'sales.collection.view']);

        $this->phone($sr);
        $this->assertFalse($this->getJson('/api/v1/me')->assertOk()->json('mayCollect'), '⛔ SR-এর ফোনে আদায়ের ঘর।');
        $this->getJson('/api/v1/sales/collections/setup')->assertForbidden();

        $before = \App\Modules\Sales\Models\Collection::query()->count();
        $out = app(SyncService::class)->push($sr->fresh(), 'phone-sr', 'sales', [$this->change('sr-1', [
            'customerId' => (string) $this->shop->public_id, 'amount' => '300', 'trxDate' => now()->toDateString(),
        ])]);
        $this->assertSame(SyncChange::REJECTED, $out[0]['status'], '⛔ SR-এর ফোন থেকে আদায় বসল।');

        // ⓘ উল্টোটাও — খাতার চাবি আছে, আদায়ের নেই: ফোনের আদায় নয়
        $clerk = $this->person(['accounts.voucher.create']);
        $out = app(SyncService::class)->push($clerk, 'phone-clerk', 'sales', [$this->change('clerk-1', [
            'customerId' => (string) $this->shop->public_id, 'amount' => '300', 'trxDate' => now()->toDateString(),
        ])]);
        $this->assertSame(SyncChange::REJECTED, $out[0]['status'], '⛔ আদায়ের চাবি ছাড়া ফোন থেকে আদায় বসল।');
        $this->assertSame($before, \App\Modules\Sales\Models\Collection::query()->count());
    }

    private ?User $accountant = null;

    /** ⓘ অফিসের হিসাবরক্ষক — আদায়ের চাবি আর খাতায় টাকা তোলার চাবি */
    private function accountant(): User
    {
        return $this->accountant ??= $this->person(['sales.collection.create', 'sales.collection.view', 'accounts.voucher.create']);
    }

    /** @param  list<string>  $keys */
    private function person(array $keys): User
    {
        $user = User::factory()->create(['is_active' => true, 'current_company_id' => $this->owner->current_company_id]);
        $user->companies()->attach($this->owner->current_company_id, ['is_active' => true]);
        CompanyContext::forCompany((int) $this->owner->current_company_id, fn () => $user->givePermissionTo(
            array_map(fn (string $k) => \Spatie\Permission\Models\Permission::findOrCreate($k, 'web'), $keys)));
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    public function test_a_phone_collection_with_confirm_posts_to_the_chosen_account_once(): void
    {
        $account = $this->anAccount();
        $due = $this->shop->outstanding();
        $payload = [
            'customerId' => (string) $this->shop->public_id, 'amount' => '750.50', 'trxDate' => now()->toDateString(),
            'accountId' => (string) $account->public_id, 'instrument' => 'bKash', 'instrumentNo' => 'TRX-7781',
            'narration' => 'দোকানে গিয়ে নেওয়া', 'confirm' => true,
        ];

        $out = $this->push([$this->change('pay-1', $payload)]);
        $this->assertSame(SyncChange::APPLIED, $out[0]['status'], json_encode($out));

        $collection = Collection::query()->where('public_id', $out[0]['entityId'])->firstOrFail();
        $this->assertSame(DocumentStatus::CONFIRMED, $collection->status, '⛔ "এখনই নিশ্চিত" চাইলেও পাকা হয়নি।');
        // ⓘ ফোনের রসিদ — নম্বর আর অবস্থা
        $this->phone($this->accountant());
        $this->getJson('/api/v1/sales/collections/'.$collection->public_id)->assertOk()
            ->assertJsonPath('no', $collection->document_no)->assertJsonPath('status', DocumentStatus::CONFIRMED);
        $this->assertSame([$account->id, 'bKash', 'TRX-7781'], [(int) $collection->account_id, $collection->instrument, $collection->instrument_no]);
        $this->assertTrue(LedgerEntry::query()->where('account_id', $account->id)->where('debit', '750.5000')
            ->where('document_no', $collection->document_no)->exists(), '⛔ টাকা বাছা খাতে বসেনি।');
        $this->assertSame(0, bccomp(bcsub($due, '750.50', 4), $this->shop->fresh()->outstanding(), 2), '⛔ গ্রাহকের প্রাপ্য কমেনি।');

        // ⭐ একই আদায় আবার পৌঁছাল — একটাই
        $before = Collection::query()->count();
        $again = $this->push([$this->change('pay-1', $payload)]);
        $this->assertSame($out[0]['entityId'], $again[0]['entityId']);
        $this->assertSame($before, Collection::query()->count(), '⛔ একই আদায় দুবার বসল।');
    }

    public function test_bad_rows_are_refused_with_a_reason_and_the_good_one_still_lands(): void
    {
        $base = ['customerId' => (string) $this->shop->public_id, 'trxDate' => now()->toDateString()];
        $before = Collection::query()->count();

        $out = $this->push([
            $this->change('bad-1', $base + ['amount' => '1e5']),
            $this->change('bad-2', ['trxDate' => now()->addDays(3)->toDateString()] + $base + ['amount' => '100']),
            $this->change('bad-3', $base + ['amount' => '100', 'accountId' => '01890000-0000-7000-8000-000000000000']),
            // ⓘ দল-খাতে টাকা নয় — নগদের মা-খাত নিজে
            $this->change('bad-4', $base + ['amount' => '100', 'accountId' => (string) \Illuminate\Support\Facades\DB::table('accounts')->where('company_id', $this->owner->current_company_id)->where('is_group', true)->value('public_id')]),
            $this->change('good-1', $base + ['amount' => '100']),
        ]);

        $this->assertSame([SyncChange::REJECTED, SyncChange::REJECTED, SyncChange::REJECTED, SyncChange::REJECTED, SyncChange::APPLIED], array_column($out, 'status'), json_encode($out));
        $this->assertSame($before + 1, Collection::query()->count());

        // ⓘ "নিশ্চিত" না চাইলে খসড়া — ওয়েবে লেখার পরের অবস্থা
        $this->assertSame(DocumentStatus::DRAFT, Collection::query()->where('public_id', $out[4]['entityId'])->value('status'));
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    private function anAccount(): Account
    {
        $this->phone($this->owner);
        $accounts = collect($this->getJson('/api/v1/sales/collections/setup')->json('accounts'));
        $pick = $accounts->firstWhere('kind', 'mfs') ?? $accounts->firstWhere('kind', 'bank') ?? $accounts->first();

        return Account::query()->where('public_id', $pick['id'])->firstOrFail();
    }

    /** @param  list<array<string, mixed>>  $changes @return list<array<string, mixed>> */
    private function push(array $changes): array
    {
        return app(SyncService::class)->push($this->accountant(), 'phone-pay', 'sales', $changes);
    }

    /** @param  array<string, mixed>  $payload @return array<string, mixed> */
    private function change(string $id, array $payload): array
    {
        return ['changeId' => $id, 'entityType' => 'Collection', 'operation' => 'CREATE', 'payloadJson' => json_encode($payload)];
    }

    private function phone(User $user): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user->fresh(), [AuthController::APP]);
    }
}
